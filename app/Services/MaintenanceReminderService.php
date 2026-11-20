<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MaintenanceReminderService
{
    public function __construct(private readonly GreenApiService $greenApi) {}

    public function triggerAutomaticCheck(): void
    {
        try {
            if (! Cache::add('mboacar_maint_reminder_throttle', 1, 300)) {
                return;
            }

            $this->sendDueReminders();
        } catch (\Throwable $e) {
            Log::warning('MaintenanceReminderService check skipped', ['error' => $e->getMessage()]);
        }
    }

    public function sendDueReminders(?string $locale = null): int
    {
        $sentCount = 0;

        try {
            if (! $this->greenApi->isConfigured()) {
                return 0;
            }

            $inFifteenDays = now()->addDays(15)->toDateString();
            $cooldownCutoff = now()->subDays(14);

            $vehicles = Vehicle::withoutTenantScope()
                ->with(['customer:id,name,first_name,phone,whatsapp_phone', 'tenant:id,name'])
                ->where(function ($q) use ($inFifteenDays) {
                    $q->where(fn ($sub) => $sub->whereNotNull('next_service_date')->where('next_service_date', '<=', $inFifteenDays))
                        ->orWhere(fn ($sub) => $sub->whereNotNull('technical_inspection_expires_at')->where('technical_inspection_expires_at', '<=', $inFifteenDays))
                        ->orWhere(fn ($sub) => $sub->whereNotNull('insurance_expires_at')->where('insurance_expires_at', '<=', $inFifteenDays));
                })
                ->where(function ($q) use ($cooldownCutoff) {
                    $q->whereNull('maintenance_reminder_sent_at')
                        ->orWhere('maintenance_reminder_sent_at', '<=', $cooldownCutoff);
                })
                ->take(15)
                ->get();

            foreach ($vehicles as $vehicle) {
                if ($this->sendForVehicle($vehicle, $locale)) {
                    $vehicle->update(['maintenance_reminder_sent_at' => now()]);
                    $sentCount++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MaintenanceReminderService error (non-blocking)', ['error' => $e->getMessage()]);
        }

        return $sentCount;
    }

    public function sendForVehicle(Vehicle $vehicle, ?string $locale = null): bool
    {
        try {
            $phone = $vehicle->customer?->whatsapp_phone ?: $vehicle->customer?->phone;
            if (empty($phone)) {
                return false;
            }

            $clientName = trim(($vehicle->customer?->name ?? '').' '.($vehicle->customer?->first_name ?? '')) ?: 'Client';
            $garageName = $vehicle->tenant?->name ?? 'MboaCar';
            $plate = $vehicle->license_plate;

            $alerts = [];
            if ($vehicle->next_service_date) {
                $alerts[] = 'Vidange / Révision : '.$vehicle->next_service_date->format('d/m/Y');
            }
            if ($vehicle->technical_inspection_expires_at) {
                $alerts[] = 'Visite technique : '.$vehicle->technical_inspection_expires_at->format('d/m/Y');
            }
            if ($vehicle->insurance_expires_at) {
                $alerts[] = 'Assurance : '.$vehicle->insurance_expires_at->format('d/m/Y');
            }

            if (empty($alerts)) {
                $alerts[] = 'Entretien périodique recommandé';
            }

            $details = implode(' | ', $alerts);
            $loc = $locale ?: app()->getLocale();

            $templates = [
                'fr' => "Bonjour {$clientName}, rappel d'entretien {$garageName} pour votre véhicule {$plate} ({$details}). Prenez soin de votre véhicule !",
                'en' => "Hello {$clientName}, maintenance reminder from {$garageName} for your vehicle {$plate} ({$details}).",
                'es' => "Hola {$clientName}, recordatorio de mantenimiento de {$garageName} para su vehículo {$plate} ({$details}).",
                'ar' => "مرحباً {$clientName}، تذكير صيانة من {$garageName} لمركبتك {$plate} ({$details}).",
                'zh' => "您好 {$clientName}，{$garageName} 提醒您注意车辆 {$plate} 的保养日期（{$details}）。",
            ];

            $res = $this->greenApi->sendMessage($phone, $templates[$loc] ?? $templates['fr']);

            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp maintenance reminder failed (skipped cleanly)', [
                'vehicle_id' => $vehicle->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
