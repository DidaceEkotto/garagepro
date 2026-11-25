<?php

namespace App\Mail;

use App\Models\PlatformSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $siteName;

    public readonly string $logoUrl;

    public readonly string $officialDomain;

    public readonly string $requestedAt;

    public function __construct(
        public readonly string $code,
        public readonly string $ownerName,
        public readonly string $garageName,
        public readonly string $maskedIp = '',
    ) {
        $site = PlatformSetting::siteSettings();
        $this->siteName = ! empty($site['name']) && $site['name'] !== 'MboaCar' ? $site['name'] : 'MboaCar';
        $rawLogo = $site['logo_url'] ?? '/assets/images/logo.png';
        $this->logoUrl = str_starts_with($rawLogo, 'http://') || str_starts_with($rawLogo, 'https://')
            ? $rawLogo
            : url($rawLogo);
        $this->officialDomain = (string) config('garagepro.tenant_base_domain', 'mboacar.com');
        $this->requestedAt = now()->format('d/m/Y à H:i');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->siteName}] Code de réinitialisation de mot de passe : {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-otp',
        );
    }
}
