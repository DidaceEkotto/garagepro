<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\ClientReminderMail;
use App\Models\Agency;
use App\Models\AppointmentLink;
use App\Models\Vehicle;
use App\Services\GreenApiService;
use App\Services\MaintenanceReminderService;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceReminderController extends Controller
{
    public function index(): Response
    {
        $vehicles = Vehicle::with('customer:id,name,first_name,phone,whatsapp_phone,email')
            ->orderBy('license_plate')
            ->get()
            ->map(function (Vehicle $v) {
                $dueSoon = $this->computeDueReasons($v);

                return [
                    'id' => $v->id,
                    'license_plate' => $v->license_plate,
                    'brand' => $v->brand,
                    'model' => $v->model,
                    'mileage' => $v->mileage,
                    'customer' => $v->customer,
                    'next_service_date' => $v->next_service_date?->format('Y-m-d'),
                    'next_service_mileage' => $v->next_service_mileage,
                    'technical_inspection_expires_at' => $v->technical_inspection_expires_at?->format('Y-m-d'),
                    'insurance_expires_at' => $v->insurance_expires_at?->format('Y-m-d'),
                    'next_belt_brake_date' => $v->next_belt_brake_date ? \Illuminate\Support\Carbon::parse($v->next_belt_brake_date)->format('Y-m-d') : null,
                    'next_belt_brake_mileage' => $v->next_belt_brake_mileage,
                    'maintenance_reminder_sent_at' => $v->maintenance_reminder_sent_at?->format('d/m/Y H:i'),
                    'due_reasons' => $dueSoon,
                    'is_due_soon' => count($dueSoon) > 0,
                ];
            });

        return Inertia::render('Tenant/MaintenanceReminders/Index', [
            'vehicles' => $vehicles,
            'dueCount' => $vehicles->where('is_due_soon', true)->count(),
        ]);
    }

    public function updateDates(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'next_service_date' => ['nullable', 'date'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'technical_inspection_expires_at' => ['nullable', 'date'],
            'insurance_expires_at' => ['nullable', 'date'],
            'next_belt_brake_date' => ['nullable', 'date'],
            'next_belt_brake_mileage' => ['nullable', 'integer', 'min:0'],
        ]);

        $vehicle->update($validated);

        return back()->with('success', 'Échéances d\'entretien et documents mises à jour.');
    }

    public function sendWhatsapp(Vehicle $vehicle, MaintenanceReminderService $service): RedirectResponse
    {
        $vehicle->loadMissing(['customer', 'tenant']);
        $sent = $service->sendForVehicle($vehicle, app()->getLocale());

        if (! $sent) {
            return back()->with('warning', 'Le rappel WhatsApp n\'a pas pu être remis pour le moment.');
        }

        $vehicle->update(['maintenance_reminder_sent_at' => now()]);

        return back()->with('success', 'Rappel périodique envoyé sur WhatsApp.');
    }

    /**
     * Envoi automatique des rappels prédictifs (15 jours avant vidange, visite technique, assurance, courroie/plaquettes)
     * avec lien direct de prise de rendez-vous en 1 clic (/rdv/{token}).
     */
    public function runPredictiveReminders(
        Request $request,
        TenantContext $tenantContext,
        GreenApiService $greenApi
    ): RedirectResponse {
        $tenant = $tenantContext->getTenant();
        $agencyId = Agency::where('is_active', true)->value('id');

        $vehicles = Vehicle::with('customer')
            ->where(function ($q) {
                $q->whereNull('maintenance_reminder_sent_at')
                    ->orWhere('maintenance_reminder_sent_at', '<=', now()->subDays(10));
            })
            ->get();

        $sentCount = 0;

        foreach ($vehicles as $vehicle) {
            $reasons = $this->computeDueReasons($vehicle);
            if (empty($reasons) || ! $vehicle->customer) {
                continue;
            }

            $link = AppointmentLink::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'customer_id' => $vehicle->customer_id,
                'vehicle_id' => $vehicle->id,
                'created_by' => $request->user('web')->id,
                'token' => Str::random(32),
                'status' => 'pending',
                'expires_at' => now()->addDays(20),
            ]);

            $rdvUrl = url("/rdv/{$link->token}");
            $reasonsText = implode(', ', $reasons);
            $customer = $vehicle->customer;
            $sentAny = false;

            if (! empty($customer->phone)) {
                try {
                    $msg = "Bonjour {$customer->name},\n\n"
                        ."*{$tenant->name}* vous rappelle que votre véhicule *{$vehicle->brand} {$vehicle->model} ({$vehicle->license_plate})* arrive bientôt à échéance pour : *{$reasonsText}*.\n\n"
                        ."Prenez rendez-vous en 1 clic ici :\n{$rdvUrl}";
                    $res = $greenApi->sendMessage($customer->phone, $msg);
                    if (! empty($res['ok'])) {
                        $sentAny = true;
                    }
                } catch (\Throwable) {
                    // continue with email fallback
                }
            }

            if (! empty($customer->email)) {
                try {
                    Mail::to($customer->email)->send(new ClientReminderMail(
                        garageName: $tenant->name,
                        clientName: $customer->name,
                        subjectLine: "Rappel entretien véhicule {$vehicle->license_plate}",
                        bodyText: "Votre véhicule {$vehicle->brand} {$vehicle->model} ({$vehicle->license_plate}) approche d'une échéance importante : {$reasonsText}.\n\nRéservez votre créneau en 1 clic : {$rdvUrl}",
                    ));
                    $sentAny = true;
                } catch (\Throwable) {
                    // ignore mail error
                }
            }

            if ($sentAny) {
                $vehicle->update(['maintenance_reminder_sent_at' => now()]);
                $sentCount++;
            }
        }

        return back()->with(
            $sentCount > 0 ? 'success' : 'info',
            $sentCount > 0
                ? "{$sentCount} rappel(s) d'entretien prédictif(s) avec lien RDV 1-clic envoyé(s)."
                : 'Aucun véhicule n\'est à relancer dans les 15 prochains jours.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function computeDueReasons(Vehicle $v): array
    {
        $reasons = [];
        $horizon = now()->addDays(15)->endOfDay();

        if ($v->next_service_date && $v->next_service_date->lte($horizon)) {
            $reasons[] = 'Vidange ('.$v->next_service_date->format('d/m/Y').')';
        } elseif ($v->next_service_mileage && $v->mileage && ($v->next_service_mileage - $v->mileage) <= 1000) {
            $reasons[] = "Vidange ({$v->next_service_mileage} km)";
        }

        if ($v->technical_inspection_expires_at && $v->technical_inspection_expires_at->lte($horizon)) {
            $reasons[] = 'Visite technique ('.$v->technical_inspection_expires_at->format('d/m/Y').')';
        }

        if ($v->insurance_expires_at && $v->insurance_expires_at->lte($horizon)) {
            $reasons[] = 'Assurance ('.$v->insurance_expires_at->format('d/m/Y').')';
        }

        if ($v->next_belt_brake_date && \Illuminate\Support\Carbon::parse($v->next_belt_brake_date)->lte($horizon)) {
            $reasons[] = 'Courroie / Plaquettes ('.\Illuminate\Support\Carbon::parse($v->next_belt_brake_date)->format('d/m/Y').')';
        } elseif ($v->next_belt_brake_mileage && $v->mileage && ($v->next_belt_brake_mileage - $v->mileage) <= 1000) {
            $reasons[] = "Courroie / Plaquettes ({$v->next_belt_brake_mileage} km)";
        }

        return $reasons;
    }
}
