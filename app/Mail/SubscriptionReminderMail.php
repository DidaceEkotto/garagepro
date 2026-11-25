<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly Tenant $tenant,
        public readonly int $priceFcfa,
        public readonly ?int $daysRemaining = null,
        public readonly string $contextType = 'subscription',
    ) {}

    public function envelope(): Envelope
    {
        $siteName = \App\Models\PlatformSetting::siteSettings()['name'] ?? 'MboaCar';
        $prefix = $this->daysRemaining !== null && $this->daysRemaining > 0
            ? "Rappel (J-{$this->daysRemaining}) : Renouvellement de votre abonnement {$siteName}"
            : "Renouvellement de votre abonnement {$siteName}";

        return new Envelope(
            subject: "{$prefix} — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        $site = \App\Models\PlatformSetting::siteSettings();
        $baseDomain = config('garagepro.tenant_base_domain', 'mboacar.com');
        $renewUrl = "https://{$this->tenant->slug}.{$baseDomain}/abonnement";

        return new Content(
            view: 'emails.subscription-reminder',
            with: [
                'siteName' => $site['name'] ?? 'MboaCar',
                'logoUrl' => $site['logo_url'] ?? asset('assets/images/logo.png'),
                'renewUrl' => $renewUrl,
            ],
        );
    }
}
