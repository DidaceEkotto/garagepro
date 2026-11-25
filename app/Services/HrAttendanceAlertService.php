<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Envoie automatiquement une alerte WhatsApp au propriétaire du compte
 * si personne n'a rempli la fiche de pointage (/rh) lors d'une journée ouvrée.
 * Fonctionne sans cron/jobs et de manière strictement non-bloquante.
 */
class HrAttendanceAlertService
{
    public function __construct(private readonly GreenApiService $greenApi) {}

    public function triggerAutomaticCheck(): void
    {
        try {
            if (! Cache::add('mboacar_hr_missing_check_throttle', 1, 300)) {
                return;
            }

            $this->sendMissingAttendanceAlerts();
        } catch (\Throwable $e) {
            Log::warning('HrAttendanceAlertService check skipped', ['error' => $e->getMessage()]);
        }
    }

    public function sendMissingAttendanceAlerts(bool $ignoreStartTime = false): int
    {
        $sentCount = 0;

        try {
            if (! $this->greenApi->isConfigured()) {
                return 0;
            }

            $now = now();
            $todayStr = $now->toDateString();
            $dayKey = strtolower($now->format('D')); // mon, tue, ...

            $tenants = Tenant::whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])->get();

            foreach ($tenants as $tenant) {
                $settings = $tenant->settings ?? [];
                $workDays = $settings['work_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
                if (! in_array($dayKey, $workDays, true)) {
                    continue;
                }

                $workStart = $settings['work_start_time'] ?? '08:00';
                if (! $ignoreStartTime) {
                    $alertAfter = Carbon::parse("{$todayStr} {$workStart}")->addHour();
                    if ($now->lt($alertAfter)) {
                        continue;
                    }
                }

                $hasActiveEmployees = Employee::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasActiveEmployees) {
                    continue;
                }

                // Vérifie si au moins un pointage (arrivée ou statut non-present) a été rempli aujourd'hui
                $hasFilledToday = EmployeeAttendance::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->whereDate('work_date', $todayStr)
                    ->where(function ($q) {
                        $q->whereNotNull('checked_in_at')
                            ->orWhereIn('status', ['absent', 'conge', 'permission']);
                    })
                    ->exists();

                if ($hasFilledToday) {
                    continue;
                }

                $dailyKey = "mboacar_hr_missing_alert_{$tenant->id}_{$todayStr}";
                if (! Cache::add($dailyKey, 1, now()->endOfDay())) {
                    continue;
                }

                $owner = User::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('id')
                    ->first();

                $phone = $owner?->phone ?: $tenant->phone;
                if (empty($phone)) {
                    continue;
                }

                $ownerName = $owner?->name ?: $tenant->name;
                $dateFr = $now->format('d/m/Y');
                $msg = "Bonjour {$ownerName}, alerte pointage MboaCar ({$tenant->name}) : aucune fiche de pointage n'a encore été remplie aujourd'hui ({$dateFr}). Pensez à vérifier le pointage de votre équipe dans /rh.";

                $res = $this->greenApi->sendMessage($phone, $msg);
                if ($res['ok'] ?? false) {
                    $sentCount++;
                } else {
                    Cache::forget($dailyKey);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('HrAttendanceAlertService error (non-blocking)', ['error' => $e->getMessage()]);
        }

        return $sentCount;
    }
}
