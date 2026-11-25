<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithOwnerAndEmployee(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Sécurité',
            'slug' => 'secu-garage',
            'email' => 'owner@secu.cm',
            'phone' => '+237699123456',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'is_main' => true,
        ]);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Propriétaire Principal',
            'email' => 'owner@secu.cm',
            'phone' => '+237699123456',
            'password' => Hash::make('OldPass@123'),
            'is_active' => true,
        ]);

        $employee = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Employé Mécanicien',
            'email' => 'employe@secu.cm',
            'phone' => '+237699654321',
            'password' => Hash::make('EmpPass@123'),
            'is_active' => true,
        ]);

        return [$tenant, $owner, $employee];
    }

    public function test_only_account_owner_can_request_password_reset_and_email_contains_logo_and_anti_phishing(): void
    {
        Mail::fake();
        RateLimiter::clear('pwd_reset_ip:'.sha1('127.0.0.1'));

        [$tenant, $owner, $employee] = $this->createTenantWithOwnerAndEmployee();

        // 1. Un employé (non propriétaire) est bloqué
        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie', [
            'email' => $employee->email,
            'channel' => 'email',
        ])->assertSessionHasErrors('email');

        Mail::assertNothingSent();

        // 2. Le propriétaire demande un code OTP par e-mail
        $capturedCode = null;
        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie', [
            'email' => $owner->email,
            'channel' => 'email',
        ])->assertRedirect(route('password.otp'));

        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use ($owner, &$capturedCode) {
            $capturedCode = $mail->code;
            $html = $mail->render();

            return $mail->hasTo($owner->email)
                && strlen($mail->code) === 5
                && str_contains($html, '<img src=')
                && str_contains($html, 'Protection anti-phishing');
        });

        $this->assertNotNull($capturedCode);

        // 3. Vérification OTP + définition du nouveau mot de passe fort
        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie/verification-otp', [
            'code' => $capturedCode,
        ])->assertRedirect(route('password.reset.form'));

        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie/nouveau-mot-de-passe', [
            'password' => 'NewSecure@2026',
            'password_confirmation' => 'NewSecure@2026',
        ])->assertRedirect(route('tenant.login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewSecure@2026', $owner->fresh()->password));
    }

    public function test_owner_can_request_otp_via_whatsapp_and_honeypot_blocks_bots(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'secret',
        ]);

        Http::fake(['*' => Http::response(['idMessage' => 'wa-otp-1'], 200)]);
        Mail::fake();

        [$tenant, $owner] = $this->createTenantWithOwnerAndEmployee();

        // 1. Honeypot rempli par un bot -> bloqué sans appel API
        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie', [
            'email' => $owner->email,
            'channel' => 'whatsapp',
            'website' => 'https://spam-bot.evil',
        ])->assertSessionHasErrors('email');

        Http::assertNothingSent();

        // 2. Envoi légitime par WhatsApp
        $this->post('http://secu-garage.mboacar.com/mot-de-passe-oublie', [
            'email' => $owner->email,
            'channel' => 'whatsapp',
        ])->assertRedirect(route('password.otp'));

        Http::assertSentCount(1);

        // 3. Vérifie les en-têtes de sécurité anti-phishing / anti-XSS / anti-clickjacking
        $res = $this->get('http://secu-garage.mboacar.com/mot-de-passe-oublie');
        $res->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $res->headers->get('Content-Security-Policy'));
    }
}
