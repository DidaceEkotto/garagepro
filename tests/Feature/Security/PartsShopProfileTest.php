<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\Category;
use App\Models\Country;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartsShopProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_subscription_price_for_parts_shop_vs_garage(): void
    {
        PlatformSetting::setValue('subscription_price', 25000);
        PlatformSetting::setValue('parts_shop_subscription_price', 12000);

        $garage = Tenant::create([
            'name' => 'Garage Test',
            'slug' => 'garage-test-price',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'g@example.com',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $shop = Tenant::create([
            'name' => 'Boutique Pièces Test',
            'slug' => 'shop-test-price',
            'type' => Tenant::TYPE_PARTS_SHOP,
            'email' => 's@example.com',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->assertSame(25000, PlatformSetting::subscriptionPriceForTenant($garage));
        $this->assertSame(12000, PlatformSetting::subscriptionPriceForTenant($shop));
    }

    public function test_parts_shop_registration_forces_single_agency_and_blocks_workshop_routes(): void
    {
        Country::seedDefaults();

        $resp = $this->post('/inscription', [
            'type' => Tenant::TYPE_PARTS_SHOP,
            'garage_name' => 'Auto Pièces Akwa',
            'owner_last_name' => 'Mbappe',
            'owner_first_name' => 'Eric',
            'slug' => 'auto-pieces-akwa',
            'email' => 'eric@autopieces.cm',
            'phone' => '+237690112233',
            'country' => 'CM',
            'city' => 'Douala',
            'agencies_count' => 5, // doit être forcé à 1
            'password' => 'Secret123@',
            'password_confirmation' => 'Secret123@',
        ]);

        $resp->assertRedirect();

        $tenant = Tenant::where('slug', 'auto-pieces-akwa')->firstOrFail();
        $this->assertSame(Tenant::TYPE_PARTS_SHOP, $tenant->type);
        $this->assertSame(1, Agency::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $owner = User::withoutTenantScope()->where('email', 'eric@autopieces.cm')->firstOrFail();

        // Impossible de créer une 2e agence
        $this->actingAs($owner)
            ->post('/agences', ['name' => 'Agence 2', 'city' => 'Yaoundé'])
            ->assertRedirect();
        $this->assertSame(1, Agency::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Accès bloqué aux modules purement atelier/garage (redirigé vers tableau de bord)
        $this->actingAs($owner)->get('/receptions')->assertRedirect('/tableau-de-bord');
        $this->actingAs($owner)->get('/ordres-reparation')->assertRedirect('/tableau-de-bord');

        // Accès autorisé au tableau de bord vendeur et au catalogue Marketplace
        $this->actingAs($owner)->get('/tableau-de-bord')->assertOk();
        $this->actingAs($owner)->get('/marketplace-gestion/produits')->assertOk();
        $this->actingAs($owner)->get('/marketplace-gestion/categories')->assertOk();

        // Publication d'une pièce qui apparaît directement sur le Marketplace public
        $cat = Category::create([
            'name' => 'Freinage',
            'slug' => 'freinage',
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $cat->id,
            'title' => 'Plaquettes de frein Toyota Corolla',
            'slug' => 'plaquettes-frein-corolla-test',
            'price' => 18500,
            'stock_quantity' => 10,
            'condition' => 'neuf',
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        $this->get('/marketplace')->assertOk()->assertSee('Plaquettes de frein Toyota Corolla');
    }
}
