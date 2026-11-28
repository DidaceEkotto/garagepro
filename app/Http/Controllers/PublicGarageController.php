<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Appointment;
use App\Models\ClientReview;
use App\Models\Country;
use App\Models\Customer;
use App\Models\PlatformMessage;
use App\Models\Tenant;
use App\Models\TenantService;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vitrine publique d'un établissement (/garage/{slug}) avec choix de prestations
 * et prise de rendez-vous instantanée (notification WhatsApp immédiate + espace admin /rendez-vous).
 */
class PublicGarageController extends Controller
{
    public function show(string $slug): Response
    {
        $tenant = Tenant::where('slug', $slug)
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->firstOrFail();

        $services = TenantService::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('price')
            ->get()
            ->map(fn (TenantService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'description' => $s->description,
                'price' => $s->price,
                'duration_minutes' => $s->duration_minutes,
                'image_url' => $s->imageUrl(),
            ])
            ->values()
            ->all();

        $reviewsQuery = ClientReview::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('published_publicly', true)
            ->whereNotNull('rating');

        $reviewsCount = (clone $reviewsQuery)->count();
        $avgRating = $reviewsCount > 0 ? round((float) (clone $reviewsQuery)->avg('rating'), 2) : null;

        $latestReviews = (clone $reviewsQuery)
            ->latest('submitted_at')
            ->take(6)
            ->get(['id', 'rating', 'comment', 'submitted_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'date' => $r->submitted_at?->format('d/m/Y'),
            ])
            ->all();

        $countries = Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']);
        if ($countries->isEmpty()) {
            $countries = collect(Country::defaultAfricanCountries());
        }

        $defaultDialCode = Country::where('code', $tenant->country ?: 'CM')->value('dial_code') ?? '+237';

        return Inertia::render('Public/GarageShow', [
            'garage' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type,
                'type_label' => match ($tenant->type) {
                    'centre_technique' => 'Centre Technique',
                    'assurance' => 'Assurance Auto',
                    'boutique_pieces', 'pieces' => 'Boutique Pièces',
                    default => 'Garage & Dépannage',
                },
                'city' => $tenant->city,
                'address' => $tenant->address,
                'formatted_location' => $tenant->formatted_location ?: trim(($tenant->address ? $tenant->address.', ' : '').($tenant->city ?? '')),
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'latitude' => $tenant->latitude ? (float) $tenant->latitude : null,
                'longitude' => $tenant->longitude ? (float) $tenant->longitude : null,
                'sos_enabled' => (bool) $tenant->sos_enabled,
                'photos' => $tenant->showcasePhotoUrls(),
                'rating' => $avgRating,
                'reviews_count' => $reviewsCount,
            ],
            'services' => $services,
            'reviews' => $latestReviews,
            'countries' => $countries,
            'defaultDialCode' => $defaultDialCode,
        ]);
    }

    /**
     * Enregistre un rendez-vous public, le fait apparaître immédiatement dans /rendez-vous,
     * notifie l'espace admin (PlatformMessage) et envoie un WhatsApp synchrone au garage (sans job ni cron).
     */
    public function bookAppointment(Request $request, string $slug, GreenApiService $greenApi): RedirectResponse
    {
        $tenant = Tenant::where('slug', $slug)
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->firstOrFail();

        $validated = $request->validate([
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
            'custom_service' => ['nullable', 'string', 'max:200'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'dial_code' => ['nullable', 'string', 'max:16'],
            'customer_phone' => ['required', 'string', 'min:6', 'max:40'],
            'vehicle_brand' => ['required', 'string', 'min:2', 'max:80'],
            'vehicle_model' => ['required', 'string', 'min:1', 'max:80'],
            'vehicle_plate' => ['required', 'string', 'min:3', 'max:40'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1500'],
        ]);

        $selectedServices = TenantService::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('id', $validated['service_ids'] ?? [])
            ->get();

        $serviceNames = $selectedServices->pluck('name')->all();
        if (! empty($validated['custom_service'])) {
            $serviceNames[] = trim($validated['custom_service']);
        }
        if (empty($serviceNames)) {
            $serviceNames[] = 'Demande de rendez-vous en ligne';
        }

        $totalPrice = (int) $selectedServices->sum('price');
        $totalDuration = max(30, (int) $selectedServices->sum('duration_minutes'));
        $dialCode = trim((string) ($validated['dial_code'] ?? '+237'));
        $rawPhone = preg_replace('/\s+/', '', (string) $validated['customer_phone']);
        $fullPhone = str_starts_with($rawPhone, '+') ? $rawPhone : $dialCode.$rawPhone;
        $fullName = trim($validated['customer_name'].(! empty($validated['customer_first_name']) ? ' '.$validated['customer_first_name'] : ''));
        $plate = strtoupper(trim($validated['vehicle_plate']));
        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $reason = implode(' + ', $serviceNames);

        $appointment = DB::transaction(function () use (
            $tenant,
            $validated,
            $dialCode,
            $fullPhone,
            $fullName,
            $plate,
            $scheduledAt,
            $totalDuration,
            $reason,
            $totalPrice
        ) {
            $agencyId = Agency::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            $customer = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($fullPhone, $validated) {
                    $q->where('phone', $fullPhone)
                        ->orWhere('phone', trim($validated['customer_phone']))
                        ->orWhere('whatsapp_phone', $fullPhone);
                })
                ->first();

            if (! $customer) {
                $customer = Customer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'agency_id' => $agencyId,
                    'name' => trim($validated['customer_name']),
                    'first_name' => $validated['customer_first_name'] ? trim($validated['customer_first_name']) : null,
                    'phone_dial_code' => $dialCode,
                    'phone' => $fullPhone,
                    'whatsapp_phone' => $fullPhone,
                    'type' => 'particulier',
                ]);
            }

            $vehicle = Vehicle::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('license_plate', $plate)
                ->first();

            if (! $vehicle) {
                $vehicle = Vehicle::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $customer->id,
                    'license_plate' => $plate,
                    'brand' => trim($validated['vehicle_brand']),
                    'model' => trim($validated['vehicle_model']),
                ]);
            }

            $notesParts = [];
            if ($totalPrice > 0) {
                $notesParts[] = 'Estimation prestations : '.number_format($totalPrice, 0, ',', ' ').' FCFA';
            }
            if (! empty($validated['notes'])) {
                $notesParts[] = trim($validated['notes']);
            }
            $notesParts[] = 'Réservé en ligne via la vitrine MboaCar';

            return Appointment::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'channel' => 'en_ligne',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $totalDuration,
                'status' => Appointment::STATUS_PENDING,
                'reason' => mb_substr($reason, 0, 250),
                'notes' => implode("\n", $notesParts),
            ]);
        });

        // Notification interne dans l'espace admin du garage (/messagerie & cloche)
        try {
            PlatformMessage::create([
                'tenant_id' => $tenant->id,
                'sender_type' => PlatformMessage::TYPE_CUSTOMER,
                'sender_name' => $fullName,
                'recipient_type' => PlatformMessage::TYPE_TENANT,
                'recipient_id' => $tenant->id,
                'subject' => 'Nouveau rendez-vous en ligne — '.$plate,
                'body' => "Client : {$fullName} ({$fullPhone})\n"
                    ."Véhicule : {$validated['vehicle_brand']} {$validated['vehicle_model']} ({$plate})\n"
                    ."Date souhaitée : {$scheduledAt->format('d/m/Y à H:i')}\n"
                    ."Prestation(s) : {$reason}"
                    .($totalPrice > 0 ? "\nEstimation : ".number_format($totalPrice, 0, ',', ' ').' FCFA' : '')
                    .(! empty($validated['notes']) ? "\nNote client : ".trim($validated['notes']) : ''),
                'is_read' => false,
            ]);
            Cache::forget("mboacar_tenant_nav_{$tenant->id}");
        } catch (\Throwable $e) {
            Log::warning('Public appointment internal notification failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        }

        // Envoi WhatsApp synchrone immédiat au garage (sans job ni cron)
        if (! empty($tenant->phone)) {
            try {
                $whatsappMsg = "📅 *NOUVEAU RENDEZ-VOUS EN LIGNE — MboaCar*\n\n"
                    ."👤 *Client :* {$fullName}\n"
                    ."📱 *WhatsApp :* {$fullPhone}\n"
                    ."🚗 *Véhicule :* {$validated['vehicle_brand']} {$validated['vehicle_model']} ({$plate})\n"
                    ."🛠️ *Prestation(s) :* {$reason}\n"
                    .($totalPrice > 0 ? '💰 *Tarif indicatif :* '.number_format($totalPrice, 0, ',', ' ')." FCFA\n" : '')
                    ."🗓️ *Date & heure :* {$scheduledAt->format('d/m/Y à H:i')}\n"
                    .(! empty($validated['notes']) ? "📝 *Précisions :* ".trim($validated['notes'])."\n" : '')
                    ."\nRetrouvez et confirmez ce rendez-vous dans votre espace MboaCar → *Rendez-vous*.";

                $greenApi->sendMessage($tenant->phone, $whatsappMsg);
            } catch (\Throwable $e) {
                Log::warning('Public appointment WhatsApp dispatch failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return back()->with('success', 'Votre rendez-vous a bien été enregistré ! L\'établissement vient d\'être notifié sur WhatsApp et dans son espace professionnel.');
    }
}
