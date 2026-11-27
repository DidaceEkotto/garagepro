<?php

namespace App\Console\Commands;

use App\Services\QuoteAutoReminderService;
use Illuminate\Console\Command;

class SendPendingQuoteReminders extends Command
{
    protected $signature = 'quotes:remind-pending';

    protected $description = 'Relance automatiquement sur WhatsApp (ou e-mail) les devis envoyés sans réponse après 48h (J+2).';

    public function handle(QuoteAutoReminderService $service): int
    {
        $sentCount = $service->dispatchDueReminders(50);

        $this->info("Relances automatiques J+2 envoyées : {$sentCount}");

        return self::SUCCESS;
    }
}
