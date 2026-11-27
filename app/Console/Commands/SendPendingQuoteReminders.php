<?php

namespace App\Console\Commands;

use App\Mail\ClientReminderMail;
use App\Models\AuditLog;
use App\Models\Quote;
use App\Services\GreenApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPendingQuoteReminders extends Command
{
    protected $signature = 'quotes:remind-pending';

    protected $description = 'Relance automatiquement sur WhatsApp (ou e-mail) les devis envoyés sans réponse après 48h (J+2).';

    public function handle(GreenApiService $greenApi): int
    {
        $cutoff = now()->subHours(48);

        $quotes = Quote::withoutTenantScope()
            ->with(['tenant:id,name', 'customer:id,name,first_name,email,phone,whatsapp_phone', 'vehicle:id,license_plate,brand,model'])
            ->where('status', Quote::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->whereNull('auto_reminded_at')
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', today());
            })
            ->get();

        $sentCount = 0;

        foreach ($quotes as $quote) {
            $customer = $quote->customer;
            if (! $customer) {
                continue;
            }

            $garageName = $quote->tenant?->name ?? config('app.name', 'MboaCar');
            $clientName = trim(($customer->first_name ? $customer->first_name.' ' : '').$customer->name);
            $plate = $quote->vehicle?->license_plate ? " ({$quote->vehicle->license_plate})" : '';
            $totalFormatted = number_format((int) $quote->total_ttc, 0, ',', ' ');
            $publicUrl = $quote->publicUrl();

            $message = "🔔 Rappel amical — Bonjour {$clientName},\n"
                ."Votre devis {$quote->quote_number} (v{$quote->version}) d'un montant de {$totalFormatted} FCFA pour votre véhicule{$plate} chez {$garageName} attend toujours votre retour.\n\n"
                ."👉 Cliquez ici pour le valider, sélectionner vos options ou demander un réajustement :\n"
                ."{$publicUrl}";

            $phone = trim((string) ($customer->whatsapp_phone ?: ($customer->phone ?? '')));
            $email = trim((string) ($customer->email ?? ''));
            $delivered = false;

            if ($phone !== '' && $greenApi->isConfigured()) {
                try {
                    $res = $greenApi->sendMessage($phone, $message);
                    $delivered = (bool) ($res['ok'] ?? false);
                } catch (\Throwable $e) {
                    Log::warning('Auto J+2 quote WhatsApp reminder failed', [
                        'quote_id' => $quote->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $delivered && $email !== '') {
                try {
                    Mail::to($email)->send(new ClientReminderMail(
                        garageName: $garageName,
                        clientName: $clientName,
                        subjectLine: "Rappel : votre devis {$quote->quote_number} vous attend — {$garageName}",
                        bodyText: $message,
                    ));
                    $delivered = true;
                } catch (\Throwable $e) {
                    Log::warning('Auto J+2 quote email reminder failed', [
                        'quote_id' => $quote->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($delivered) {
                $quote->update(['auto_reminded_at' => now()]);
                AuditLog::record('quote.auto_reminded_j2', subject: $quote, tenantId: $quote->tenant_id);
                $sentCount++;
            }
        }

        $this->info("Relances automatiques J+2 envoyées : {$sentCount}");

        return self::SUCCESS;
    }
}
