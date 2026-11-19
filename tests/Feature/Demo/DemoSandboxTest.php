<?php

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_can_enter_demo_sandbox_without_registration_and_browse_all_modules(): void
    {
        $response = $this->get('/demo');
        $response->assertRedirect(route('tenant.dashboard'));

        $routes = [
            '/tableau-de-bord',
            '/agences',
            '/clients',
            '/rendez-vous',
            '/receptions',
            '/ordres-reparation',
            '/devis',
            '/atelier/mes-or',
            '/stock',
            '/approvisionnement',
            '/factures',
            '/comptabilite',
            '/rh',
            '/apres-vente',
            '/rapports',
            '/abonnement',
        ];

        foreach ($routes as $uri) {
            $this->get($uri)->assertStatus(200);
        }

        // Réinitialisation de la démo
        $this->post('/demo/reinitialiser')
            ->assertRedirect(route('tenant.dashboard'));

        // Vérification sur un domaine de production sans sous-domaine (ex: mboacar.com)
        $this->get('https://mboacar.com/demo')
            ->assertRedirect('https://mboacar.com/tableau-de-bord');
        $this->get('https://mboacar.com/tableau-de-bord')
            ->assertStatus(200);
    }
}
