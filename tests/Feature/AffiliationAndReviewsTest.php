<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Country;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliationAndReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_reviews_prevent_duplicates_and_seller_self_rating(): void
    {
        Country::seedDefaults();

        $tenant = Tenant::create([
            'name' => 'Garage Vendeur',
            'slug' => 'garage-vendeur',
            'email' => 'vendeur@test.local',
            'country' => 'CM',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $sellerUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendeur',
            'email' => 'vendeur@test.local',
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Freinage', 'slug' => 'freinage-test', 'icon' => 'bi-disc', 'is_active' => true]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'title' => 'Disque de frein ventilé',
            'slug' => 'disque-de-frein-ventile',
            'condition' => 'neuf',
            'price' => 35000,
            'stock_quantity' => 5,
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        // Un acheteur externe peut noter
        $this->post("/marketplace/produit/{$product->slug}/avis", [
            'reviewer_name' => 'Jean Client',
            'reviewer_email' => 'jean@client.local',
            'rating' => 5,
            'comment' => 'Très bonne qualité',
        ])->assertRedirect();

        $this->assertSame(1, ProductReview::where('product_id', $product->id)->count());

        // Doublon avec le même email bloqué
        $this->post("/marketplace/produit/{$product->slug}/avis", [
            'reviewer_name' => 'Jean Client',
            'reviewer_email' => 'jean@client.local',
            'rating' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(1, ProductReview::where('product_id', $product->id)->count());

        // Le vendeur ne peut pas noter son propre produit
        $this->actingAs($sellerUser, 'web')
            ->post("/marketplace/produit/{$product->slug}/avis", [
                'reviewer_name' => 'Auto Note',
                'reviewer_email' => 'autonote@test.local',
                'rating' => 5,
            ])->assertSessionHas('error');

        $this->assertSame(1, ProductReview::where('product_id', $product->id)->count());
    }

    public function test_affiliation_lifecycle_and_default_admin_credentials(): void
    {
        Country::seedDefaults();
        PlatformSetting::setValue('subscription_price', '2500');

        $tenant = Tenant::create([
            'name' => 'Garage Parrain',
            'slug' => 'garage-parrain',
            'email' => 'parrain@test.local',
            'country' => 'CM',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(7),
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Gérant Parrain',
            'email' => 'parrain@test.local',
            'password' => 'Secret@123',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        // Création du lien d'affiliation unique
        $this->actingAs($user, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/affiliation', ['code' => 'PARRAIN24'])
            ->assertRedirect();

        $link = AffiliateLink::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('PARRAIN24', $link->code);

        // Impossible de créer un second lien
        $this->actingAs($user, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/affiliation', ['code' => 'AUTRE99'])
            ->assertSessionHas('error');

        // Inscription avec un code promo inexistant -> rejetée avec message spécifique
        auth()->guard('web')->logout();
        $this->withSession([])->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post('/inscription', [
            'garage_name' => 'Garage Bad Code',
            'slug' => 'garage-bad-code',
            'email' => 'badcode@test.local',
            'country' => 'CM',
            'phone' => '+237 699000000',
            'city' => 'Douala',
            'agencies_count' => 1,
            'promo_code' => 'INEXISTANT99',
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertSessionHasErrors('promo_code');

        // Inscription avec un code promo bloqué -> rejetée avec message spécifique
        $link->update(['is_blocked' => true, 'blocked_reason' => 'Fraude']);
        $this->withSession([])->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post('/inscription', [
            'garage_name' => 'Garage Blocked Code',
            'slug' => 'garage-blocked-code',
            'email' => 'blockedcode@test.local',
            'country' => 'CM',
            'phone' => '+237 699000000',
            'city' => 'Douala',
            'agencies_count' => 1,
            'promo_code' => 'PARRAIN24',
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertSessionHasErrors('promo_code');

        // Déblocage + commission personnalisée depuis Admin (PlatformSetting) en mode "signup"
        $link->update(['is_blocked' => false, 'blocked_reason' => null]);
        PlatformSetting::setValue('affiliate_commission', '3000');
        PlatformSetting::setValue('affiliate_commission_trigger', 'signup');
        PlatformSetting::setValue('affiliate_referral_discount', '5000');

        // Inscription d'un filleul (en tant que visiteur non connecté) avec le code promo
        $this->withSession([])->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post('/inscription', [
            'garage_name' => 'Garage Filleul',
            'slug' => 'garage-filleul',
            'owner_first_name' => 'Jean',
            'owner_last_name' => 'Filleul',
            'owner_gender' => 'homme',
            'email' => 'filleul@test.local',
            'country' => 'CM',
            'phone' => '+237 699000000',
            'city' => 'Douala',
            'agencies_count' => 1,
            'promo_code' => 'PARRAIN24',
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertRedirect();

        $link->refresh();
        $this->assertSame(3000, $link->balance);

        // Mode "first_payment" : commission différée au 1er paiement + réduction filleul 4000 FCFA
        PlatformSetting::setValue('affiliate_commission', '3500');
        PlatformSetting::setValue('affiliate_commission_trigger', 'first_payment');
        PlatformSetting::setValue('affiliate_referral_discount', '4000');
        PlatformSetting::setValue('subscription_price', '10000');

        $this->withSession([])->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->post('/inscription', [
            'garage_name' => 'Garage Filleul 2',
            'slug' => 'garage-filleul-2',
            'owner_first_name' => 'Paul',
            'owner_last_name' => 'Filleul',
            'owner_gender' => 'homme',
            'email' => 'filleul2@test.local',
            'country' => 'CM',
            'phone' => '+237 699000000',
            'city' => 'Douala',
            'agencies_count' => 1,
            'promo_code' => 'PARRAIN24',
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $link->refresh();
        // Pas encore crédité avant le 1er paiement du filleul 2
        $this->assertSame(3000, $link->balance);

        $filleul2 = Tenant::where('slug', 'garage-filleul-2')->firstOrFail();
        $this->assertSame(4000, \App\Models\AffiliateReferral::availableDiscountForTenant($filleul2));

        // Simulation du 1er paiement d'abonnement réussi du filleul 2
        $payment = \App\Models\Payment::create([
            'tenant_id' => $filleul2->id,
            'payable_type' => Tenant::class,
            'payable_id' => $filleul2->id,
            'reference_code' => 'SUB-TEST-'.strtoupper(\Illuminate\Support\Str::random(8)),
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'saspay',
            'payment_method' => 'saspay_checkout',
            'amount' => 6000,
            'currency' => 'XAF',
            'status' => \App\Models\Payment::STATUS_PENDING,
        ]);

        app(\App\Services\PaymentService::class)->markPaymentSucceeded($payment);

        $link->refresh();
        $this->assertSame(6500, $link->balance);
        $this->assertSame(0, \App\Models\AffiliateReferral::availableDiscountForTenant($filleul2));
        $this->assertSame(Tenant::STATUS_ACTIVE, $filleul2->fresh()->status);

        // Paiement de l'abonnement via le solde d'affiliation (6500 FCFA disponibles)
        PlatformSetting::setValue('subscription_price', '6500');
        $this->actingAs($user, 'web')
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post('/affiliation/payer-abonnement')
            ->assertSessionHas('success');

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);

        // Vérification connexion Admin par défaut admin@admin.test / 000000
        $this->assertTrue(Admin::where('email', 'admin@admin.test')->exists());
        $this->post('/admin/login', [
            'email' => 'admin@admin.test',
            'password' => '000000',
        ])->assertRedirect(route('admin.dashboard'));
    }
}
