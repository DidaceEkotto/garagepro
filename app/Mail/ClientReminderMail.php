<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $garageName,
        public string $clientName,
        public string $subjectLine,
        public string $bodyText,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->garageName}] {$this->subjectLine}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<h3>Bonjour '.e($this->clientName).',</h3>'
                .'<p>'.nl2br(e($this->bodyText)).'</p>'
                .'<hr><p style="color:#64748b;font-size:12px;">Cordialement,<br><strong>'.e($this->garageName).'</strong></p>'
        );
    }
}
