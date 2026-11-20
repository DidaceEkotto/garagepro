<?php

namespace App\Http\Controllers;

use App\Models\AppointmentLink;
use App\Models\Country;
use App\Models\PlatformMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublicAppointmentController extends Controller
{
    public function show(string $token): Response
    {
        $link = AppointmentLink::withoutTenantScope()
            ->with(['tenant:id,name,country,slug,settings', 'agency:id,name'])
            ->where('token', $token)
            ->first();

        if (! $link) {
            return Inertia::render('Public/AppointmentBooking', [
                'linkStatus' => 'not_found',
                'token' => $token,
            ]);
        }

        if ($link->status === AppointmentLink::STATUS_ACTIVE && $link->isExpired()) {
            $link->update(['status' => AppointmentLink::STATUS_EXPIRED]);
        }

        $tenant = $link->tenant;
        $defaultDialCode = Country::where('code', $tenant?->country ?: 'CM')->value('dial_code') ?? '+237';

        return Inertia::render('Public/AppointmentBooking', [
            'linkStatus' => $link->status,
            'token' => $link->token,
            'label' => $link->label,
            'expiresAt' => $link->expires_at?->toIso8601String(),
            'expiresAtFormatted' => $link->expires_at?->format('d/m/Y à H:i'),
            'tenantName' => $tenant?->name ?? 'Garage',
            'agencyName' => $link->agency?->name,
            'defaultDialCode' => $defaultDialCode,
            'countries' => Country::active()->orderBy('name')->get(['code', 'name', 'dial_code']),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', Rule::in(['homme', 'femme', 'autre'])],
            'id_number' => ['required', 'string', 'min:3', 'max:100'],
            'dial_code' => ['nullable', 'string', 'max:15'],
            'phone' => ['required', 'string', 'min:6', 'max:50'],
            'profession' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
            'vehicle_brand' => ['nullable', 'string', 'max:80'],
            'vehicle_model' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', Rule::in(['entretien', 'panne', 'controle', 'accident'])],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $outcome = DB::transaction(function () use ($token, $validated) {
            $link = AppointmentLink::withoutTenantScope()
                ->with('tenant:id,name,country')
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $link) {
                return 'not_found';
            }

            if ($link->status === AppointmentLink::STATUS_ACTIVE && $link->isExpired()) {
                $link->update(['status' => AppointmentLink::STATUS_EXPIRED]);

                return 'expired';
            }

            if (! $link->isUsable()) {
                return 'already_used';
            }

            $dialCode = trim((string) ($validated['dial_code'] ?? ''));
            if ($dialCode === '') {
                $dialCode = Country::where('code', $link->tenant?->country ?: 'CM')->value('dial_code') ?? '+237';
            }

            $rawPhone = trim((string) $validated['phone']);
            $dialDigits = preg_replace('/\D+/', '', $dialCode);
            $phoneDigits = preg_replace('/\D+/', '', $rawPhone);
            if (str_starts_with($rawPhone, '+')) {
                $formattedPhone = $rawPhone;
            } elseif ($dialDigits !== '' && str_starts_with($phoneDigits, $dialDigits)) {
                $formattedPhone = '+'.$phoneDigits;
            } else {
                $formattedPhone = $dialCode.' '.$phoneDigits;
            }

            $payload = [
                'name' => $validated['name'],
                'first_name' => $validated['first_name'] ?? null,
                'gender' => $validated['gender'],
                'id_number' => $validated['id_number'],
                'dial_code' => $dialCode,
                'phone' => $formattedPhone,
                'profession' => $validated['profession'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'description' => $validated['description'] ?? null,
                'vehicle_plate' => $validated['vehicle_plate'] ?? null,
                'vehicle_brand' => $validated['vehicle_brand'] ?? null,
                'vehicle_model' => $validated['vehicle_model'] ?? null,
                'reason' => $validated['reason'],
                'scheduled_at' => $validated['scheduled_at'],
                'notes' => $validated['notes'] ?? null,
            ];

            $link->update([
                'status' => AppointmentLink::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_payload' => $payload,
            ]);

            $fullName = trim($validated['name'].' '.($validated['first_name'] ?? ''));
            $plateText = ! empty($validated['vehicle_plate']) ? " ({$validated['vehicle_plate']})" : '';

            PlatformMessage::create([
                'tenant_id' => $link->tenant_id,
                'sender_type' => PlatformMessage::TYPE_CUSTOMER,
                'sender_name' => $fullName,
                'recipient_type' => PlatformMessage::TYPE_TENANT,
                'subject' => 'Nouvelle demande de rendez-vous via lien sécurisé',
                'body' => "{$fullName}{$plateText} a soumis une demande de rendez-vous ({$validated['reason']}) pour le {$validated['scheduled_at']}. Ouvrez « Rendez-vous » pour créer son compte et confirmer.",
                'is_read' => false,
            ]);

            return 'ok';
        });

        if ($outcome !== 'ok') {
            return back()->withErrors([
                'link' => 'Ce lien de rendez-vous est expiré ou a déjà été utilisé.',
            ]);
        }

        return back()->with('success', 'Votre demande de rendez-vous a bien été envoyée ! Vous recevrez un message WhatsApp dès que le garage aura confirmé.');
    }
}
