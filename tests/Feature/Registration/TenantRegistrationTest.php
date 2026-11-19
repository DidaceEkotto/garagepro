<?php

namespace Tests\Feature\Registration;

use App\Mail\WelcomeTenantMail;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_tenant_registration_creates_tenant_agencies_gerant_and_sso_redirect(): void
    {
        Mail::fake();

        $response = $this->post('https://mboacar.com/inscription', [
            'garage_name' => 'Garage Horizon',
            'slug' => 'horizon',
            'email' => 'gerant@horizon.cm',
            'phone' => '+237 699000000',
            'country' => 'CM',
            'city' => 'Douala',
            'agencies_count' => 2,
            'password' => 'MotDePasse@123',
            'password_confirmation' => 'MotDePasse@123',
        ]);

        // Redirection vers le sous-domaine avec lien signé /sso/...
        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('horizon.mboacar.com/sso/', $targetUrl);

        $tenant = Tenant::where('slug', 'horizon')->first();
        $this->assertNotNull($tenant);
        $this->assertSame(Tenant::STATUS_TRIALING, $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertCount(2, Agency::withoutTenantScope()->where('tenant_id', $tenant->id)->get());

        $user = User::withoutTenantScope()->where('email', 'gerant@horizon.cm')->first();
        $this->assertNotNull($user);
        setPermissionsTeamId($tenant->id);
        $this->assertTrue($user->hasRole('gerant'));

        Mail::assertQueued(WelcomeTenantMail::class, fn ($mail) => $mail->hasTo('gerant@horizon.cm'));

        // Consommation du lien SSO signé (usage unique)
        $ssoResponse = $this->get($targetUrl);
        $ssoResponse->assertRedirect(route('tenant.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');

        // Une deuxième utilisation du même lien doit être refusée (403)
        auth('web')->logout();
        $replayResponse = $this->get($targetUrl);
        $replayResponse->assertStatus(403);
    }

    public function test_registration_works_dynamically_on_local_ip_and_port(): void
    {
        Mail::fake();

        $response = $this->post('http://127.0.0.1:5675/inscription', [
            'garage_name' => 'Garage Local',
            'slug' => 'garage-local',
            'email' => 'local@example.cm',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'MotDePasse@123',
            'password_confirmation' => 'MotDePasse@123',
        ]);

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringStartsWith('http://127.0.0.1:5675/sso/', $targetUrl);

        $this->get($targetUrl)->assertRedirect(route('tenant.dashboard'));
        $this->get('http://127.0.0.1:5675/tableau-de-bord')->assertStatus(200);
    }

    public function test_reserved_subdomains_are_rejected(): void
    {
        foreach (['www', 'admin', 'api', 'app'] as $reserved) {
            $response = $this->post('/inscription', [
                'garage_name' => 'Test Garage',
                'slug' => $reserved,
                'email' => "{$reserved}@example.com",
                'agencies_count' => 1,
                'password' => 'MotDePasse123!',
                'password_confirmation' => 'MotDePasse123!',
            ]);

            $response->assertSessionHasErrors('slug');
        }
    }
}
