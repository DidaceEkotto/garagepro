<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RichMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{name: string, url: string, size: int}> $attachmentLinks
     */
    public function __construct(
        public string $garageName,
        public string $recipientName,
        public string $subjectLine,
        public string $bodyText,
        public array $attachmentLinks = [],
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
        $linksHtml = '';
        if (! empty($this->attachmentLinks)) {
            $linksHtml .= '<hr><p><strong>Pièces jointes à télécharger :</strong></p><ul>';
            foreach ($this->attachmentLinks as $link) {
                $sizeKb = max(1, (int) round(($link['size'] ?? 0) / 1024));
                $linksHtml .= '<li><a href="'.e($link['url']).'" target="_blank">'.e($link['name'])."</a> ({$sizeKb} Ko)</li>";
            }
            $linksHtml .= '</ul>';
        }

        return new Content(
            htmlString: '<h3>Bonjour '.e($this->recipientName).',</h3>'
                .'<p>'.nl2br(e($this->bodyText)).'</p>'
                .$linksHtml
                .'<hr><p style="color:#64748b;font-size:12px;">Envoyé depuis <strong>'.e($this->garageName).'</strong></p>'
        );
    }
}
