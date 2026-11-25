<?php

namespace App\Mail;

use App\Models\MarketplaceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MarketplaceNewOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{title: string, quantity: int, unit_price: int, subtotal: int}> $sellerItems
     */
    public function __construct(
        public string $sellerGarageName,
        public MarketplaceOrder $order,
        public array $sellerItems,
        public int $sellerTotal,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Marketplace MboaCar] Nouvelle commande {$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $rowsHtml = '';
        foreach ($this->sellerItems as $item) {
            $sub = number_format($item['subtotal'], 0, ',', ' ').' FCFA';
            $rowsHtml .= '<li>'.e($item['title'])." &times; {$item['quantity']} — <strong>{$sub}</strong></li>";
        }
        $totalFormatted = number_format($this->sellerTotal, 0, ',', ' ').' FCFA';

        return new Content(
            htmlString: '<h2>Nouvelle commande reçue sur le Marketplace !</h2>'
                .'<p>Bonjour <strong>'.e($this->sellerGarageName).'</strong>,</p>'
                .'<p>Un client vient de passer la commande <strong>'.e($this->order->order_number).'</strong> :</p>'
                .'<ul>'
                .'<li><strong>Client :</strong> '.e($this->order->buyer_name).'</li>'
                .'<li><strong>Email :</strong> '.e($this->order->buyer_email).'</li>'
                .'<li><strong>WhatsApp :</strong> '.e($this->order->dial_code.' '.$this->order->buyer_whatsapp).'</li>'
                .'<li><strong>Livraison :</strong> '.e($this->order->delivery_address).'</li>'
                .'</ul>'
                .'<p><strong>Articles commandés :</strong></p>'
                ."<ul>{$rowsHtml}</ul>"
                ."<p><strong>Total pour votre garage : {$totalFormatted}</strong></p>"
                .'<p>Connectez-vous à votre espace MboaCar (menu Marketplace &rarr; Commandes) pour traiter cette commande.</p>'
        );
    }
}
