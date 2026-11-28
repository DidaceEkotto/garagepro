<?php

namespace Tests\Feature\Workflow;

use App\Services\GreenApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappClickableLinksTest extends TestCase
{
    public function test_normalizes_localhost_markdown_and_formatting_into_clickable_https_links(): void
    {
        config([
            'app.url' => 'http://localhost:8000',
            'garagepro.tenant_base_domain' => 'mboacar.com',
            'garagepro.whatsapp_public_url' => null,
        ]);

        // 1. localhost / 127.0.0.1 réécrit vers https://mboacar.com
        $msg1 = GreenApiService::normalizeClickableLinks('Suivi : http://localhost:8000/suivi-commande/abc123.');
        $this->assertStringContainsString('https://mboacar.com/suivi-commande/abc123', $msg1);
        $this->assertStringNotContainsString('localhost', $msg1);
        $this->assertStringNotContainsString('abc123.', $msg1);

        // 2. Sous-domaine local réécrit vers https://mon-garage.mboacar.com
        $msg2 = GreenApiService::normalizeClickableLinks('Devis : http://mon-garage.localhost:8000/devis/xyz');
        $this->assertStringContainsString('https://mon-garage.mboacar.com/devis/xyz', $msg2);

        // 3. Lien Markdown [Texte](url) et formatage *url* nettoyés
        $msg3 = GreenApiService::normalizeClickableLinks('Voir [votre devis](https://mboacar.com/devis/123) ou *https://mboacar.com/avis/456*');
        $this->assertStringContainsString("votre devis :\nhttps://mboacar.com/devis/123", $msg3);
        $this->assertStringContainsString('https://mboacar.com/avis/456', $msg3);
        $this->assertStringNotContainsString('*https://', $msg3);

        // 4. Domaine nu sans https:// complété, mais e-mail préservé intact
        $msg4 = GreenApiService::normalizeClickableLinks('Site: mboacar.com/marketplace — Contact : support@mboacar.com');
        $this->assertStringContainsString('https://mboacar.com/marketplace', $msg4);
        $this->assertStringContainsString('support@mboacar.com', $msg4);
        $this->assertStringNotContainsString('support@https://', $msg4);
    }

    public function test_send_message_enables_link_preview_and_sends_clickable_url(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'garagepro.tenant_base_domain' => 'mboacar.com',
            'garagepro.whatsapp_public_url' => null,
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '111222',
            'services.greenapi.api_token_instance' => 'secret-token',
        ]);

        Http::fake([
            'https://api.green-api.com/*' => Http::response(['idMessage' => 'WA-LINK-001'], 200),
        ]);

        $service = app(GreenApiService::class);
        $res = $service->sendMessage('+237699000111', "Bonjour, cliquez ici:http://127.0.0.1:8000/suivi-commande/tok999.");

        $this->assertTrue($res['ok']);

        Http::assertSent(function ($request) {
            return $request['linkPreview'] === true
                && str_contains($request['message'], 'https://mboacar.com/suivi-commande/tok999')
                && ! str_contains($request['message'], '127.0.0.1')
                && ! str_contains($request['message'], 'tok999.');
        });
    }
}
