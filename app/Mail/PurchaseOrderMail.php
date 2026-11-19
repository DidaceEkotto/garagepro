<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly bool $isReminder = false,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->isReminder ? '[RELANCE] ' : '';

        return new Envelope(
            subject: "{$prefix}Bon de commande {$this->purchaseOrder->po_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-order',
        );
    }
}
