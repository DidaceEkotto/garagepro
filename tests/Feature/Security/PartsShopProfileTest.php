<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\Category;
use App\Models\Country;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GreenApiService;
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

    public function test_seller_depart_sends_whatsapp_and_buyer_seller_order_chat_works(): void
    {
        $tenant = Tenant::create([
            'name' => 'Pièces Express Douala',
            'slug' => 'pieces-express-douala',
            'type' => Tenant::TYPE_PARTS_SHOP,
            'email' => 'shop@example.com',
            'phone' => '+237690000888',
            'latitude' => 4.0511,
            'longitude' => 9.7679,
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendeur Pro',
            'email' => 'vendeur@example.com',
            'password' => bcrypt('Secret123@'),
            'role' => 'gerant',
            'is_active' => true,
        ]);
        app(\App\Services\TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $owner->assignRole('gerant');

        $cat = Category::create(['name' => 'Moteur', 'slug' => 'moteur-chat-test', 'is_active' => true]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $cat->id,
            'title' => 'Alternateur 12V',
            'slug' => 'alternateur-12v-test',
            'price' => 45000,
            'stock_quantity' => 3,
            'condition' => 'neuf',
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        $order = MarketplaceOrder::create([
            'order_number' => 'CMD-TEST999',
            'buyer_name' => 'Moussa Client',
            'buyer_email' => 'moussa@example.com',
            'country_code' => 'CM',
            'dial_code' => '+237',
            'buyer_whatsapp' => '677000111',
            'delivery_address' => 'Bonapriso, Douala',
            'delivery_latitude' => 4.0320,
            'delivery_longitude' => 9.7010,
            'total_amount' => 45000,
            'status' => 'nouvelle',
        ]);

        $item = MarketplaceOrderItem::create([
            'marketplace_order_id' => $order->id,
            'marketplace_product_id' => $product->id,
            'tenant_id' => $tenant->id,
            'product_title' => $product->title,
            'unit_price' => 45000,
            'quantity' => 1,
            'subtotal' => 45000,
            'seller_status' => 'nouvelle',
            'is_seen_by_seller' => false,
        ]);

        $mockGreenApi = $this->mock(GreenApiService::class);
        $mockGreenApi->shouldReceive('sendMessage')->atLeast()->once()->andReturn(['ok' => true]);

        // 0. Vendeur ouvre la page dédiée de détails de la commande
        $this->actingAs($owner)
            ->get("/marketplace-gestion/commandes/{$item->id}")
            ->assertOk();

        // 1. Vendeur clique sur Départ -> statut passe en "expediee" + WhatsApp envoyé
        $this->actingAs($owner)
            ->post("/marketplace-gestion/commandes/{$item->id}/depart")
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('expediee', $item->seller_status);

        // 2. Client ouvre sa page de suivi /suivi-commande/{token} et répond au vendeur
        $this->get("/suivi-commande/{$order->tracking_token}")->assertOk();

        $this->postJson("/suivi-commande/{$order->tracking_token}/articles/{$item->id}/messages", [
            'body' => 'Je suis juste devant la pharmacie de Bonapriso.',
        ])->assertOk()->assertJsonPath('ok', true);

        // 3. Vendeur consulte le chat et répond au client
        $this->actingAs($owner)
            ->getJson("/marketplace-gestion/commandes/{$item->id}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'messages');

        $this->actingAs($owner)
            ->postJson("/marketplace-gestion/commandes/{$item->id}/messages", [
                'body' => 'Parfait, j\'arrive dans 5 minutes !',
                'send_whatsapp' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
