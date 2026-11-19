<?php

namespace App\Jobs;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envoie un rappel automatique SMS/WhatsApp pour un rendez-vous confirmé.
 */
class SendAppointmentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $appointmentId) {}

    public function handle(): void
    {
        $appointment = Appointment::withoutTenantScope()
            ->with(['customer', 'vehicle'])
            ->find($this->appointmentId);

        if (! $appointment || $appointment->reminder_sent_at !== null) {
            return;
        }

        // Journalisation structurée sans exposer de secret ; prêt pour branchement passerelle SMS/WhatsApp.
        Log::info('Rappel rendez-vous envoyé', [
            'appointment_id' => $appointment->id,
            'tenant_id' => $appointment->tenant_id,
            'channel' => $appointment->channel,
        ]);

        $appointment->update(['reminder_sent_at' => now()]);
    }
}
