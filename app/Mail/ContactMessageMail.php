<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public ?string $senderPhone,
        public string $contactSubject,
        public string $bodyMessage,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[GaragePro Contact] '.$this->contactSubject,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<h2>Nouveau message depuis GaragePro</h2>'
                .'<p><strong>Nom :</strong> '.e($this->senderName).'</p>'
                .'<p><strong>Email :</strong> '.e($this->senderEmail).'</p>'
                .'<p><strong>Téléphone :</strong> '.e($this->senderPhone ?: '-').'</p>'
                .'<p><strong>Objet :</strong> '.e($this->contactSubject).'</p>'
                .'<hr><p>'.nl2br(e($this->bodyMessage)).'</p>'
        );
    }
}
