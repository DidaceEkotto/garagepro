<?php

namespace App\Mail;

use App\Models\GlobalCustomer;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerProfileUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public GlobalCustomer $customer,
        public bool $photoUpdated = false,
    ) {
    }

    public function envelope(): Envelope
    {
        $fullName = trim(($this->customer->first_name ? $this->customer->first_name.' ' : '').$this->customer->name);

        return new Envelope(
            subject: "[MboaCar] Mise à jour du profil client — {$fullName}",
        );
    }

    public function content(): Content
    {
        $fullName = trim(($this->customer->first_name ? $this->customer->first_name.' ' : '').$this->customer->name);
        $typeLabel = match ($this->tenant->type) {
            Tenant::TYPE_TECHNICAL_CENTER => 'Centre de Visite Technique',
            Tenant::TYPE_INSURANCE => 'Compagnie / Courtier d\'Assurance',
            default => 'Garage Auto',
        };

        $html = '<div style="font-family: Arial, sans-serif; color: #0f172a; max-width: 600px; margin: 0 auto;">'
            .'<h2 style="color: #1d4ed8; margin-bottom: 8px;">Profil client mis à jour sur MboaCar</h2>'
            .'<p style="color: #475569; margin-top: 0;">Bonjour <strong>'.e($this->tenant->name).'</strong> ('.e($typeLabel).'),</p>'
            .'<p>Votre client <strong>'.e($fullName).'</strong> vient de mettre à jour ses informations depuis son Espace Client Unique MboaCar. Sa fiche client chez vous a été synchronisée automatiquement.</p>'
            .'<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin: 16px 0;">'
            .'<p style="margin: 4px 0;"><strong>Nom complet :</strong> '.e($fullName).'</p>'
            .'<p style="margin: 4px 0;"><strong>Téléphone / WhatsApp :</strong> '.e($this->customer->whatsapp_phone ?: $this->customer->phone ?: '—').'</p>'
            .'<p style="margin: 4px 0;"><strong>E-mail :</strong> '.e($this->customer->email ?: '—').'</p>'
            .'<p style="margin: 4px 0;"><strong>N° CNI / Passeport :</strong> '.e($this->customer->id_number ?: '—').'</p>'
            .'<p style="margin: 4px 0;"><strong>Profession :</strong> '.e($this->customer->profession ?: '—').'</p>'
            .'<p style="margin: 4px 0;"><strong>Contact d\'urgence :</strong> '.e($this->customer->emergency_contact ?: '—').'</p>'
            .($this->photoUpdated ? '<p style="margin: 4px 0; color: #15803d;"><strong>Photo de profil :</strong> Mise à jour</p>' : '')
            .'</div>'
            .'<p style="font-size: 12px; color: #64748b;">Notification automatique MboaCar — envoyée uniquement aux établissements où ce client possède une fiche active.</p>'
            .'</div>';

        return new Content(htmlString: $html);
    }
}
