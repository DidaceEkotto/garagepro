<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\PlatformDashboard;
use App\Models\Admin;
use App\Models\Agency;
use App\Models\Category;
use App\Models\GlobalCustomer;
use App\Models\InsuranceClaim;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\SecureUploadService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityAuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_restricted_admin_cannot_invoke_unauthorized_livewire_actions(): void
    {
        $marketingAdmin = Admin::create([
            'name' => 'Marketing Admin',
            'email' => 'marketing@mboacar.test',
            'role' => Admin::ROLE_MARKETING,
            'password' => 'Secret@123',
        ]);

        $tenant = Tenant::create([
            'name' => 'Garage Cible',
            'slug' => 'garage-cible',
            'email' => 'cible@test.com',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($marketingAdmin, 'admin');

        Livewire::test(PlatformDashboard::class, ['section' => 'overview'])
            ->call('saveSettings')
            ->assertForbidden();

        Livewire::test(PlatformDashboard::class, ['section' => 'overview'])
            ->call('suspendTenant', $tenant->id)
            ->assertForbidden();

        Livewire::test(PlatformDashboard::class, ['section' => 'overview'])
            ->call('impersonateTenant', $tenant->id)
            ->assertForbidden();

        Livewire::test(PlatformDashboard::class, ['section' => 'overview'])
            ->set('newIp', '203.0.113.10')
            ->set('newIpReason', 'Test')
            ->call('blockIpManual')
            ->assertForbidden();

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
    }

    public function test_html_sanitizer_and_marketplace_strip_xss_payloads(): void
    {
        $dirty = '<p onclick="alert(1)">Texte <strong>valide</strong></p><script>alert("xss")</script><a href="javascript:alert(2)">Lien</a><a href="https://mboacar.com" target="_blank">Safe</a><iframe src="https://evil.test"></iframe>';
        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringContainsString('Texte', $clean);
        $this->assertStringContainsString('<strong>valide</strong>', $clean);
        $this->assertStringContainsString('https://mboacar.com', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);

        // Marketplace product description strips all HTML
        $tenant = Tenant::create([
            'name' => 'Garage MP',
            'slug' => 'garage-mp',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'mp@test.com',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addDays(20),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'A1', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant MP',
            'email' => 'gmp@test.com',
            'password' => 'Secret@123',
        ]);
        $gerant->assignRole('gerant');

        $cat = Category::create(['name' => 'Moteur', 'slug' => 'moteur-sec', 'is_active' => true]);

        $this->actingAs($gerant, 'web')
            ->post('http://garage-mp.mboacar.com/marketplace-gestion/produits', [
                'category_id' => $cat->id,
                'title' => 'Filtre à huile',
                'condition' => 'neuf',
                'price' => 5000,
                'stock_quantity' => 10,
                'country_code' => 'CM',
                'description' => '<p>Bon filtre</p><b>Texte</b> <img src="x">',
            ])->assertSessionHas('success');

        $product = Product::withoutTenantScope()->firstOrFail();
        $this->assertStringNotContainsString('<p', (string) $product->description);
        $this->assertStringNotContainsString('<img', (string) $product->description);
        $this->assertStringNotContainsString('<b>', (string) $product->description);
        $this->assertStringContainsString('Bon filtre', (string) $product->description);
    }

    public function test_create_symlink_route_is_removed(): void
    {
        $this->get('/create-symlink')->assertNotFound();
    }

    public function test_mechanic_is_blocked_from_sensitive_tenant_modules(): void
    {
        $tenant = Tenant::create(['name' => 'Garage RBAC', 'slug' => 'garage-rbac', 'email' => 'rbac@test.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'A1', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $mechanic = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Mécanicien',
            'email' => 'meca@rbac.test',
            'password' => 'Secret@123',
        ]);
        $mechanic->assignRole('mecanicien');

        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/export-excel/customers')->assertForbidden();
        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/affiliation')->assertForbidden();
        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/marketplace-gestion/produits')->assertForbidden();
        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/flottes')->assertForbidden();
        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/sous-traitance')->assertForbidden();
        $this->actingAs($mechanic, 'web')->get('http://garage-rbac.mboacar.com/messagerie')->assertForbidden();
    }

    public function test_customer_portal_otp_is_hashed_non_enumerating_and_locks_after_5_attempts(): void
    {
        $global = GlobalCustomer::create([
            'name' => 'Client Portal',
            'phone' => '+237699778899',
            'whatsapp_phone' => '+237699778899',
            'otp_code' => Hash::make('54321'),
            'otp_expires_at' => now()->addMinutes(5),
        ]);

        // Numéro inconnu => message générique (pas d'erreur d'énumération)
        $this->post('/mon-espace-client/otp', ['phone' => '+237600000000'])
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        // 5 mauvais essais invalident définitivement l'OTP
        for ($i = 0; $i < 5; $i++) {
            $this->post('/mon-espace-client/verifier', [
                'phone' => '+237699778899',
                'code' => '00000',
            ])->assertSessionHasErrors('code');
        }

        $this->assertNull($global->fresh()->otp_code);
    }

    public function test_saspay_webhook_rejects_invalid_hmac_immediately_without_outbound_call(): void
    {
        config([
            'saspay.webhook_secret' => 'whsec_strict_test',
            'saspay.secret_key' => 'sk_test_dummy',
        ]);
        Http::fake();

        $this->postJson('/webhooks/saspay', [
            'event_id' => 'evt_bad_sig_1',
            'data' => ['id' => 'pay_999', 'status' => 'SUCCESS'],
        ], ['X-Saspay-Signature' => 'invalid-hmac'])
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_cross_tenant_claim_photo_accessible_only_to_linked_insurer(): void
    {
        Storage::fake('local');

        $garage = Tenant::create(['name' => 'G1', 'slug' => 'g1-sec', 'type' => Tenant::TYPE_GARAGE, 'email' => 'g1@sec.test']);
        $insurer = Tenant::create(['name' => 'A1', 'slug' => 'a1-sec', 'type' => Tenant::TYPE_INSURANCE, 'email' => 'a1@sec.test']);
        $stranger = Tenant::create(['name' => 'G2', 'slug' => 'g2-sec', 'type' => Tenant::TYPE_GARAGE, 'email' => 'g2@sec.test']);

        $insurerUser = User::create(['tenant_id' => $insurer->id, 'name' => 'U1', 'email' => 'u1@sec.test', 'password' => 'Secret@123']);
        $strangerUser = User::create(['tenant_id' => $stranger->id, 'name' => 'U2', 'email' => 'u2@sec.test', 'password' => 'Secret@123']);

        $path = "tenants/{$garage->id}/insurance-claims/photo1.jpg";
        Storage::disk('local')->put($path, 'fake-image-bytes');

        InsuranceClaim::withoutTenantScope()->create([
            'tenant_id' => $garage->id,
            'insurance_tenant_id' => $insurer->id,
            'garage_tenant_id' => $garage->id,
            'incident_date' => now()->toDateString(),
            'description' => 'Test',
            'estimated_amount' => 100000,
            'photos' => [$path],
            'status' => 'declare',
        ]);

        URL::forceRootUrl('http://a1-sec.mboacar.com');
        try {
            $signedUrlForInsurer = app(SecureUploadService::class)->signedUrl($path, 30);
        } finally {
            URL::forceRootUrl(null);
        }

        // Assureur lié => autorisé (200)
        $this->actingAs($insurerUser, 'web')
            ->get($signedUrlForInsurer)
            ->assertOk();

        URL::forceRootUrl('http://g2-sec.mboacar.com');
        try {
            $signedUrlForStranger = app(SecureUploadService::class)->signedUrl($path, 30);
        } finally {
            URL::forceRootUrl(null);
        }

        // Tenant tiers non lié => interdit (403)
        $this->actingAs($strangerUser, 'web')
            ->get($signedUrlForStranger)
            ->assertForbidden();
    }
}
