<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionReminderMail;
use App\Mail\TrialReminderMail;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\GreenApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Tâche planifiée quotidienne :
 * - Rappels d'essai (J-2 et J-0)
 * - Passage en lecture seule (expired) à l'expiration de l'essai
 * - Passage en past_due à l'échéance d'un abonnement actif avec délai de grâce
 * - Suspension après dépassement du délai de grâce
 */
class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'garagepro:process-subscriptions';

    protected $description = 'Traite les rappels d\'essai, expirations, relances et suspensions d\'abonnements.';

    public function handle(): int
    {
        $graceDays = PlatformSetting::gracePeriodDays();
        $price = PlatformSetting::subscriptionPrice();

        // 1. Rappels d'essai J-2 (entre 24h et 48h avant expiration)
        Tenant::where('status', Tenant::STATUS_TRIALING)
            ->whereBetween('trial_ends_at', [now()->addDay(), now()->addDays(2)->endOfDay()])
            ->each(function (Tenant $tenant): void {
                $settings = $tenant->settings ?? [];
                if (empty($settings['trial_reminder_j2_sent_at'])) {
                    Mail::to($tenant->email)->queue(new TrialReminderMail($tenant, 2));
                    $settings['trial_reminder_j2_sent_at'] = now()->toIso8601String();
                    $tenant->update(['settings' => $settings]);
                }
            });

        // 2. Rappels d'essai J-0 (expire aujourd'hui, encore dans le futur proche)
        Tenant::where('status', Tenant::STATUS_TRIALING)
            ->whereBetween('trial_ends_at', [now(), now()->endOfDay()])
            ->each(function (Tenant $tenant): void {
                $settings = $tenant->settings ?? [];
                if (empty($settings['trial_reminder_j0_sent_at'])) {
                    Mail::to($tenant->email)->queue(new TrialReminderMail($tenant, 0));
                    $settings['trial_reminder_j0_sent_at'] = now()->toIso8601String();
                    $tenant->update(['settings' => $settings]);
                }
            });

        // 3. Essais expirés -> 2 jours de grâce puis passage en lecture seule (expired)
        Tenant::where('status', Tenant::STATUS_TRIALING)
            ->where('trial_ends_at', '<=', now())
            ->each(function (Tenant $tenant): void {
                $trialGraceEnd = $tenant->trial_ends_at->copy()->addDays(2);
                if (now()->lte($trialGraceEnd)) {
                    $settings = $tenant->settings ?? [];
                    if (empty($settings['trial_grace_notified_at'])) {
                        Mail::to($tenant->email)->queue(new TrialReminderMail($tenant, 0));
                        $settings['trial_grace_notified_at'] = now()->toIso8601String();
                        $tenant->update([
                            'grace_ends_at' => $trialGraceEnd,
                            'settings' => $settings,
                        ]);
                    }
                } else {
                    $tenant->update(['status' => Tenant::STATUS_EXPIRED]);
                    AuditLog::record('subscription.trial_expired', subject: $tenant, tenantId: $tenant->id);
                }
            });

        // 4a. Abonnements actifs arrivant à échéance dans les 7 prochains jours -> rappel tous les 2 jours (Email + WhatsApp)
        $greenApi = app(GreenApiService::class);
        $baseDomain = config('garagepro.tenant_base_domain', 'mboacar.com');
        $siteName = PlatformSetting::siteSettings()['name'] ?? 'MboaCar';

        Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->whereBetween('subscription_ends_at', [now(), now()->addDays(7)])
            ->with(['users' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('id')])
            ->each(function (Tenant $tenant) use ($price, $greenApi, $baseDomain, $siteName): void {
                $settings = $tenant->settings ?? [];
                $lastSent = ! empty($settings['last_sub_reminder_at']) ? \Illuminate\Support\Carbon::parse($settings['last_sub_reminder_at']) : null;
                if (! $lastSent || $lastSent->lte(now()->subDays(2))) {
                    $daysRemaining = max(0, (int) now()->startOfDay()->diffInDays($tenant->subscription_ends_at->copy()->startOfDay(), false));
                    Mail::to($tenant->email)->queue(new SubscriptionReminderMail($tenant, $price, $daysRemaining, 'subscription'));
                    $settings['last_sub_reminder_at'] = now()->toIso8601String();
                    $settings['last_email_reminder_at'] = now()->toIso8601String();

                    $phone = $tenant->phone ?: $tenant->users->first()?->phone;
                    if ($phone && $greenApi->isConfigured()) {
                        $msg = "Bonjour {$tenant->name},\n\nIl ne reste plus que {$daysRemaining} jour(s) avant l'échéance de votre abonnement {$siteName} ("
                            .number_format($price, 0, ',', ' ')." FCFA/mois).\n"
                            ."Renouvelez dès maintenant depuis votre espace : https://{$tenant->slug}.{$baseDomain}/abonnement\n\nL'équipe {$siteName}";
                        $wa = $greenApi->sendMessage($phone, $msg);
                        if ($wa['ok']) {
                            $settings['last_whatsapp_reminder_at'] = now()->toIso8601String();
                        }
                    }

                    $tenant->update(['settings' => $settings]);
                }
            });

        // 4b. Abonnements actifs arrivés à échéance -> past_due + 5 jours de grâce + relance
        Tenant::where('status', Tenant::STATUS_ACTIVE)
            ->where('subscription_ends_at', '<=', now())
            ->each(function (Tenant $tenant) use ($graceDays, $price): void {
                $settings = $tenant->settings ?? [];
                $settings['last_sub_reminder_at'] = now()->toIso8601String();
                $tenant->update([
                    'status' => Tenant::STATUS_PAST_DUE,
                    'grace_ends_at' => $tenant->subscription_ends_at->copy()->addDays($graceDays),
                    'settings' => $settings,
                ]);

                Mail::to($tenant->email)->queue(new SubscriptionReminderMail($tenant, $price));
                AuditLog::record('subscription.past_due', subject: $tenant, tenantId: $tenant->id);
            });

        // 4c. En mode grâce (past_due non expiré) -> continuer à envoyer des relances tous les 2 jours
        Tenant::where('status', Tenant::STATUS_PAST_DUE)
            ->where('grace_ends_at', '>', now())
            ->each(function (Tenant $tenant) use ($price): void {
                $settings = $tenant->settings ?? [];
                $lastSent = ! empty($settings['last_sub_reminder_at']) ? \Illuminate\Support\Carbon::parse($settings['last_sub_reminder_at']) : null;
                if (! $lastSent || $lastSent->lte(now()->subDays(2))) {
                    Mail::to($tenant->email)->queue(new SubscriptionReminderMail($tenant, $price));
                    $settings['last_sub_reminder_at'] = now()->toIso8601String();
                    $tenant->update(['settings' => $settings]);
                }
            });

        // 5. Délai de grâce dépassé -> suspension (lecture seule) & arrêt des emails de rappel
        Tenant::where('status', Tenant::STATUS_PAST_DUE)
            ->where('grace_ends_at', '<=', now())
            ->each(function (Tenant $tenant): void {
                $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
                AuditLog::record('subscription.suspended', subject: $tenant, tenantId: $tenant->id);
            });

        $this->info('Cycle des abonnements traité avec succès.');

        return self::SUCCESS;
    }
}
