<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\PlatformDashboard;
use App\Mail\SubscriptionReminderMail;
use App\Models\Admin;
use App\Models\BlockedIp;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBackofficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_tenants_settings_and_blocked_ips_via_livewire(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@mboacar.com',
            'password' => 'AdminPass123!',
        ]);

        $tenant = Tenant::create([
            'name' => 'Garage Test',
            'slug' => 'test-livewire',
            'email' => 'tl@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($admin, 'admin');

        Livewire::test(PlatformDashboard::class)
            ->set('subscriptionPrice', 30000)
            ->call('saveSettings')
            ->call('extendTrial', $tenant->id, 7)
            ->call('suspendTenant', $tenant->id)
            ->set('newIp', '198.51.100.55')
            ->set('newIpReason', 'Abus manuel')
            ->call('blockIpManual');

        $this->assertSame(30000, PlatformSetting::subscriptionPrice());
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked('198.51.100.55'));

        // Témoignage géré côté admin et affiché sur la landing page
        Livewire::test(PlatformDashboard::class)
            ->set('testiAuthor', 'Jean-Luc T.')
            ->set('testiRole', 'Directeur')
            ->set('testiGarage', 'Auto Pro Yaoundé')
            ->set('testiCity', 'Yaoundé')
            ->set('testiQuote', 'GaragePro a transformé notre suivi atelier et comptable.')
            ->call('addTestimonial');

        $this->get('/')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->has('testimonials', 5)
                ->where('testimonials.0.garage_name', 'Auto Pro Yaoundé')
            );
    }

    public function test_admin_overview_analytics_all_tenant_types_and_7day_reminders(): void
    {
        Mail::fake();
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'test-token',
        ]);
        Http::fake([
            'https://api.green-api.com/*' => Http::response(['idMessage' => 'WA-MSG-123'], 200),
        ]);

        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin-analytics@mboacar.com',
            'password' => 'AdminPass123!',
        ]);

        $garage = Tenant::create([
            'name' => 'Garage Douala Express',
            'slug' => 'douala-express',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'garage@douala.test',
            'phone' => '+237699000111',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addDays(4),
        ]);

        $ct = Tenant::create([
            'name' => 'Centre Technique Yaoundé',
            'slug' => 'ct-yaounde',
            'type' => Tenant::TYPE_TECHNICAL_CENTER,
            'email' => 'ct@yaounde.test',
            'phone' => '+237699000222',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(3),
        ]);

        $assurance = Tenant::create([
            'name' => 'Assurance Auto Cameroun',
            'slug' => 'assur-cm',
            'type' => Tenant::TYPE_INSURANCE,
            'email' => 'assur@cm.test',
            'phone' => '+237699000333',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addDays(25),
        ]);

        // Paiements mois dernier (10 000 FCFA) et mois en cours (25 000 FCFA) => En hausse
        Payment::create([
            'tenant_id' => $garage->id,
            'payable_type' => Tenant::class,
            'payable_id' => $garage->id,
            'payment_method' => 'saspay_checkout',
            'amount' => 10000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'idempotency_key' => 'idem-prev-1',
            'reference_code' => 'PAY-PREV-1',
            'paid_at' => now()->subMonth()->startOfMonth()->addDays(2),
        ]);

        Payment::create([
            'tenant_id' => $garage->id,
            'payable_type' => Tenant::class,
            'payable_id' => $garage->id,
            'payment_method' => 'saspay_checkout',
            'amount' => 25000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'idempotency_key' => 'idem-curr-1',
            'reference_code' => 'PAY-CURR-1',
            'paid_at' => now()->startOfMonth()->addDay(),
        ]);

        $this->actingAs($admin, 'admin');

        // 1. Vue d'ensemble affiche CA, comparatif mensuel et tendance en hausse
        $this->get('/admin')
            ->assertStatus(200)
            ->assertSee('35 000 FCFA')
            ->assertSee('25 000 FCFA')
            ->assertSee('En hausse')
            ->assertSee('Comparatif Mensuel');

        // 2. /admin/garages affiche TOUS les types (Garage, Centre Technique, Assurance Auto)
        $this->get('/admin/garages')
            ->assertStatus(200)
            ->assertSee('Garage Douala Express')
            ->assertSee('Centre Technique Yaoundé')
            ->assertSee('Assurance Auto Cameroun')
            ->assertSee('Centre Technique')
            ->assertSee('Assurance Auto');

        // 3. Relance E-mail et WhatsApp autorisée à <= 7 jours (garage J-4), refusée à > 7 jours (assurance J-25)
        Livewire::test(PlatformDashboard::class, ['section' => 'tenants'])
            ->call('remindTenantEmail', $garage->id)
            ->assertSet('feedbackError', '')
            ->call('remindTenantWhatsapp', $garage->id)
            ->assertSet('feedbackError', '')
            ->call('remindTenantEmail', $assurance->id)
            ->assertNotSet('feedbackError', '');

        Mail::assertSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo('garage@douala.test'));
        Mail::assertNotSent(SubscriptionReminderMail::class, fn ($mail) => $mail->hasTo('assur@cm.test'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && str_contains($req['chatId'], '237699000111'));
    }
}
