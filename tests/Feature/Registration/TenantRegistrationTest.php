<?php

namespace Tests\Feature\Registration;

use App\Mail\WelcomeTenantMail;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            'phone' => '+237 699000001',
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

    public function test_three_step_whatsapp_otp_with_email_fallback_and_password_creation(): void
    {
        Mail::fake();
        config([
            'services.greenapi.url' => 'https://api.greenapi.test',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'secret-token',
        ]);
        Http::fake([
            'https://api.greenapi.test/*' => Http::response(['idMessage' => 'MSG-1'], 200),
        ]);

        // 1. Numéro WhatsApp obligatoire
        $this->post('/inscription', [
            'garage_name' => 'Garage WhatsApp',
            'slug' => 'garage-whatsapp',
            'email' => 'wa@test.cm',
            'country' => 'CM',
            'agencies_count' => 1,
        ])->assertSessionHasErrors('phone');

        // 2. Inscription sans mot de passe -> envoi OTP WhatsApp + redirection /inscription/verification-otp
        $this->post('/inscription', [
            'garage_name' => 'Garage WhatsApp',
            'slug' => 'garage-whatsapp',
            'owner_first_name' => 'Marc',
            'owner_last_name' => 'Benoit',
            'gender' => 'homme',
            'email' => 'wa@test.cm',
            'phone' => '699112233',
            'country' => 'CM',
            'city' => 'Douala',
            'agencies_count' => 1,
        ])->assertRedirect(route('register.otp'));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'waInstance123456/sendMessage/secret-token')
            && $req['chatId'] === '237699112233@c.us');

        $this->get(route('register.otp'))->assertStatus(200);

        // 3. Renvoi bloqué avant 2 minutes
        $this->post(route('register.otp.resend'))
            ->assertSessionHasErrors('code');

        // 4. Après 3 tentatives invalides -> bascule vers l'email
        $this->post(route('register.otp.verify'), ['code' => '00000'])->assertSessionHasErrors('code');
        $this->post(route('register.otp.verify'), ['code' => '00000'])->assertSessionHasErrors('code');
        $this->post(route('register.otp.verify'), ['code' => '00000'])->assertSessionHas('warning');

        $sentCode = null;
        Mail::assertSent(\App\Mail\RegistrationOtpMail::class, function ($mail) use (&$sentCode) {
            $sentCode = $mail->code;
            return $mail->hasTo('wa@test.cm') && strlen($mail->code) === 5;
        });
        $this->assertNotNull($sentCode);

        // 5. Validation avec le code email -> redirection vers la page mot de passe
        $this->post(route('register.otp.verify'), ['code' => $sentCode])
            ->assertRedirect(route('register.password'));

        $this->get(route('register.password'))->assertStatus(200);

        // 6. Création du mot de passe -> création du garage + redirection SSO
        $finalRes = $this->post(route('register.password.store'), [
            'password' => 'Garage@2026',
            'password_confirmation' => 'Garage@2026',
        ]);

        $finalRes->assertRedirect();
        $this->assertNotNull(Tenant::where('slug', 'garage-whatsapp')->first());
    }
}
