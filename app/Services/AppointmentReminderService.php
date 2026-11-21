<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AppointmentReminderService
{
    public function __construct(private readonly GreenApiService $greenApi) {}

    /**
     * Déclenche l'envoi automatique des rappels WhatsApp (2h et 30min avant le RDV)
     * sans passer par des jobs ou cron, de manière non-bloquante.
     */
    public function triggerAutomaticCheck(): void
    {
        try {
            if (! Cache::add('mboacar_appt_reminder_throttle', 1, 60)) {
                return;
            }

            $this->sendDueReminders();
        } catch (\Throwable $e) {
            Log::warning('AppointmentReminderService check skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envoie les rappels dus (2h avant et 30min avant) sans jamais bloquer l'application.
     */
    public function sendDueReminders(?string $locale = null): int
    {
        $sentCount = 0;

        try {
            if (! $this->greenApi->isConfigured()) {
                return 0;
            }

            $now = now();
            $inTwoHours = $now->copy()->addHours(2);
            $inThirtyMinutes = $now->copy()->addMinutes(30);

            // 1. Rappels 2 heures avant le rendez-vous (entre 30 min et 2h)
            $due2h = Appointment::withoutTenantScope()
                ->with(['customer:id,name,first_name,phone,whatsapp_phone', 'vehicle:id,license_plate,brand,model', 'tenant:id,name'])
                ->where('status', Appointment::STATUS_CONFIRMED)
                ->whereNotNull('scheduled_at')
                ->whereNull('reminder_2h_sent_at')
                ->where('scheduled_at', '>', $inThirtyMinutes)
                ->where('scheduled_at', '<=', $inTwoHours)
                ->take(20)
                ->get();

            foreach ($due2h as $appointment) {
                if ($this->sendSingleReminder($appointment, '2h', $locale)) {
                    $appointment->update(['reminder_2h_sent_at' => now(), 'reminder_sent_at' => now()]);
                    $sentCount++;
                }
            }

            // 2. Rappels 30 minutes avant le rendez-vous
            $due30m = Appointment::withoutTenantScope()
                ->with(['customer:id,name,first_name,phone,whatsapp_phone', 'vehicle:id,license_plate,brand,model', 'tenant:id,name'])
                ->where('status', Appointment::STATUS_CONFIRMED)
                ->whereNotNull('scheduled_at')
                ->whereNull('reminder_30m_sent_at')
                ->where('scheduled_at', '>', $now)
                ->where('scheduled_at', '<=', $inThirtyMinutes)
                ->take(20)
                ->get();

            foreach ($due30m as $appointment) {
                if ($this->sendSingleReminder($appointment, '30m', $locale)) {
                    $appointment->update(['reminder_30m_sent_at' => now(), 'reminder_sent_at' => now()]);
                    $sentCount++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AppointmentReminderService error (non-blocking)', ['error' => $e->getMessage()]);
        }

        return $sentCount;
    }

    public function sendSingleReminder(Appointment $appointment, string $window = '2h', ?string $locale = null, ?string $customMessage = null): bool
    {
        try {
            $phone = $appointment->customer?->whatsapp_phone ?: $appointment->customer?->phone;
            if (empty($phone)) {
                return false;
            }

            $message = ($customMessage !== null && trim($customMessage) !== '')
                ? trim($customMessage)
                : $this->buildMultilingualMessage($appointment, $window, $locale ?: app()->getLocale());
            $res = $this->greenApi->sendMessage($phone, $message);

            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp appointment reminder failed (skipped cleanly)', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendConfirmationMessage(Appointment $appointment, ?string $locale = null): bool
    {
        try {
            $phone = $appointment->customer?->whatsapp_phone ?: $appointment->customer?->phone;
            if (empty($phone)) {
                return false;
            }

            $loc = $locale ?: app()->getLocale();
            $clientName = trim(($appointment->customer?->name ?? '') . ' ' . ($appointment->customer?->first_name ?? '')) ?: 'Client';
            $garageName = $appointment->tenant?->name ?? 'MboaCar';
            $plate = $appointment->vehicle?->license_plate ? " ({$appointment->vehicle->license_plate})" : '';
            $time = $appointment->scheduled_at?->format('d/m/Y H:i') ?? 'à confirmer';

            $templates = [
                'fr' => "Bonjour {$clientName}, votre rendez-vous chez {$garageName}{$plate} est CONFIRMÉ pour le {$time}. À bientôt !",
                'en' => "Hello {$clientName}, your appointment at {$garageName}{$plate} is CONFIRMED for {$time}. See you soon!",
                'es' => "Hola {$clientName}, su cita en {$garageName}{$plate} está CONFIRMADA para el {$time}. ¡Hasta pronto!",
                'pt' => "Olá {$clientName}, a sua marcação em {$garageName}{$plate} está CONFIRMADA para {$time}. Até breve!",
                'ar' => "مرحباً {$clientName}، تم تأكيد موعدك في {$garageName}{$plate} ليوم {$time}. نراكم قريباً!",
                'zh' => "您好 {$clientName}，您在 {$garageName}{$plate} 的预约已确认，时间为 {$time}。期待您的光临！",
            ];

            $message = $templates[$loc] ?? $templates['fr'];
            $res = $this->greenApi->sendMessage($phone, $message);

            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp appointment confirmation failed (skipped cleanly)', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function buildMultilingualMessage(Appointment $appointment, string $window = '2h', string $locale = 'fr'): string
    {
        $clientName = trim(($appointment->customer?->name ?? '') . ' ' . ($appointment->customer?->first_name ?? '')) ?: 'Client';
        $garageName = $appointment->tenant?->name ?? 'MboaCar';
        $plate = $appointment->vehicle?->license_plate ? " ({$appointment->vehicle->license_plate})" : '';
        $time = $appointment->scheduled_at?->format('d/m/Y H:i') ?? '';

        $delayLabels = [
            'fr' => $window === '30m' ? 'dans 30 minutes' : 'dans 2 heures',
            'en' => $window === '30m' ? 'in 30 minutes' : 'in 2 hours',
            'es' => $window === '30m' ? 'en 30 minutos' : 'en 2 horas',
            'ar' => $window === '30m' ? 'خلال 30 دقيقة' : 'خلال ساعتين',
            'zh' => $window === '30m' ? '30分钟后' : '2小时后',
        ];

        $templates = [
            'fr' => "Bonjour {$clientName}, rappel de votre rendez-vous chez {$garageName}{$plate} {$delayLabels['fr']} ({$time}).",
            'en' => "Hello {$clientName}, reminder for your appointment at {$garageName}{$plate} {$delayLabels['en']} ({$time}).",
            'es' => "Hola {$clientName}, recordatorio de su cita en {$garageName}{$plate} {$delayLabels['es']} ({$time}).",
            'ar' => "مرحباً {$clientName}، تذكير بموعدك في {$garageName}{$plate} {$delayLabels['ar']} ({$time}).",
            'zh' => "您好 {$clientName}，提醒您在 {$garageName}{$plate} 的预约将于 {$delayLabels['zh']} 开始 ({$time})。",
        ];

        return $templates[$locale] ?? $templates['fr'];
    }
}
