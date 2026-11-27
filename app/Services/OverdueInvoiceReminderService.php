<?php

namespace App\Services;

use App\Mail\ClientReminderMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Relances automatiques zéro-cron des factures échues (J+3, J+7, J+15) par WhatsApp et E-mail.
 */
class OverdueInvoiceReminderService
{
    public const STAGE_DAYS = [
        1 => 3,
        2 => 7,
        3 => 15,
    ];

    public function __construct(
        private readonly GreenApiService $greenApi,
    ) {}

    /**
     * Exécution légère (throttlée à 1 fois par heure par tenant) déclenchée au chargement des pages.
     */
    public function dispatchDueForTenantThrottled(Tenant $tenant): int
    {
        $key = "overdue_invoice_reminders_ran:{$tenant->id}";
        if (! Cache::add($key, true, now()->addMinutes(60))) {
            return 0;
        }

        return $this->dispatchDueForTenant($tenant);
    }

    /**
     * Scanne les factures impayées/partielles échues et envoie la relance correspondant au palier (J+3, J+7, J+15).
     */
    public function dispatchDueForTenant(Tenant $tenant, int $limit = 15): int
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->with('customer')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->where('type', Invoice::TYPE_INVOICE)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->subDays(3))
            ->where('overdue_reminder_stage', '<', 3)
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        $sentCount = 0;

        foreach ($invoices as $invoice) {
            $balance = $invoice->balanceDue();
            if ($balance <= 0) {
                continue;
            }

            $daysOverdue = (int) $invoice->due_at->diffInDays(now());
            $targetStage = $this->resolveTargetStage($daysOverdue);
            if ($targetStage <= (int) $invoice->overdue_reminder_stage) {
                continue;
            }

            // Anti-spam : ne pas envoyer deux relances à moins de 48h d'intervalle
            if ($invoice->last_reminder_sent_at && $invoice->last_reminder_sent_at->gt(now()->subHours(48))) {
                continue;
            }

            if ($this->sendReminder($invoice, $tenant, $targetStage, $daysOverdue, $balance)) {
                $invoice->forceFill([
                    'overdue_reminder_stage' => $targetStage,
                    'last_reminder_sent_at' => now(),
                ])->save();
                $sentCount++;
            }
        }

        return $sentCount;
    }

    public function resolveTargetStage(int $daysOverdue): int
    {
        if ($daysOverdue >= 15) {
            return 3;
        }
        if ($daysOverdue >= 7) {
            return 2;
        }
        if ($daysOverdue >= 3) {
            return 1;
        }

        return 0;
    }

    private function sendReminder(Invoice $invoice, Tenant $tenant, int $stage, int $daysOverdue, int $balance): bool
    {
        $customer = $invoice->customer;
        if (! $customer) {
            return false;
        }

        $formattedBalance = number_format($balance, 0, ',', ' ').' FCFA';
        $stageLabel = match ($stage) {
            1 => 'Relance 1 (J+3)',
            2 => 'Relance 2 (J+7)',
            default => 'Dernière relance (J+15)',
        };

        $sentAny = false;

        if (! empty($customer->phone)) {
            try {
                $waMessage = "Bonjour {$customer->name},\n\n"
                    ."{$stageLabel} — *{$tenant->name}* vous rappelle que la facture *{$invoice->invoice_number}* est échue depuis {$daysOverdue} jours.\n"
                    ."Solde restant à régler : *{$formattedBalance}*.\n\n"
                    ."Merci de régulariser ou de nous contacter pour convenir d'un échéancier.";

                $res = $this->greenApi->sendMessage($customer->phone, $waMessage);
                if (! empty($res['ok'])) {
                    $sentAny = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Overdue invoice WhatsApp reminder failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        if (! empty($customer->email)) {
            try {
                Mail::to($customer->email)->send(new ClientReminderMail(
                    garageName: $tenant->name,
                    clientName: $customer->name,
                    subjectLine: "{$stageLabel} — Facture {$invoice->invoice_number}",
                    bodyText: "Votre facture {$invoice->invoice_number} (échue depuis {$daysOverdue} jours) présente un solde impayé de {$formattedBalance}.\n\nMerci de procéder au règlement ou de nous contacter pour mettre en place un paiement échelonné.",
                ));
                $sentAny = true;
            } catch (\Throwable $e) {
                Log::warning('Overdue invoice email reminder failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        if ($sentAny) {
            AuditLog::record(
                'invoice.overdue_reminded',
                subject: $invoice,
                tenantId: $tenant->id,
                metadata: ['stage' => $stage, 'days_overdue' => $daysOverdue, 'balance' => $balance]
            );
        }

        return $sentAny;
    }
}
