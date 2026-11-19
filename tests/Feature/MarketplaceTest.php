<?php

namespace Tests\Feature;

use App\Mail\MarketplaceNewOrderMail;
use App\Models\Agency;
use App\Models\Category;
use App\Models\MarketplaceBanner;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\PlatformMessage;
use App\Models\Product;
use App\Models\SubCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private function createSellerTenant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Marketplace Pro',
            'slug' => 'garage-mp',
            'email' => 'vendeur@mp.cm',
            'country' => 'CM',
            'city' => 'Douala',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Douala',
            'code' => 'AG01',
            'is_main' => true,
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Vendeur MP',
            'email' => 'vendeur@mp.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_full_marketplace_seller_to_buyer_checkout_and_notifications_flow(): void
    {
        Mail::fake();
        Storage::fake('local');

        [$tenant, $agency, $user] = $this->createSellerTenant();

        // 1. Catégories globales pré-remplies (seed) + création inline si absente + produit multi-images
        $this->assertGreaterThanOrEqual(8, Category::count());
        $globalCategory = Category::whereNull('tenant_id')->firstOrFail();
        $globalSub = SubCategory::where('category_id', $globalCategory->id)->firstOrFail();

        $img1 = UploadedFile::fake()->image('front.jpg');
        $img2 = UploadedFile::fake()->image('box.jpg');

        $this->actingAs($user, 'web')
            ->post('http://garage-mp.mboacar.com/marketplace-gestion/produits', [
                'category_id' => $globalCategory->id,
                'sub_category_id' => $globalSub->id,
                'title' => 'Plaquettes Bosch Avant',
                'brand' => 'Bosch',
                'condition' => 'neuf',
                'price' => 25000,
                'stock_quantity' => 10,
                'country_code' => 'CM',
                'images' => [$img1, $img2],
            ])
            ->assertRedirect();

        $product = Product::withoutTenantScope()->firstOrFail();
        $this->assertCount(2, $product->images);

        // 2. Recherche autocomplétion (min 3 caractères) + Pages publiques
        $this->getJson('/marketplace/api/search?q=Bo')
            ->assertOk()
            ->assertJson(['min_length_required' => true, 'items' => []]);

        $this->getJson('/marketplace/api/search?q=Bosch')
            ->assertOk()
            ->assertJson(['min_length_required' => false])
            ->assertJsonCount(1, 'items');

        $this->get('/marketplace?country=CM')->assertOk()->assertSee('Plaquettes Bosch Avant');
        $this->get('/marketplace')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Marketplace/Index')
            ->has('bestSellers', 1)
            ->has('newProducts', 1)
            ->has('banners.row_1')
            ->has('banners.row_2')
        );
        $this->assertFalse(MarketplaceBanner::where('placement', 'row_1')->first()->isExternalUrl());
        $extBanner = MarketplaceBanner::create([
            'title' => 'Partenaire externe',
            'placement' => 'row_1',
            'button_text' => 'Visiter',
            'button_url' => 'https://example.com',
            'is_active' => true,
        ]);
        $this->assertTrue($extBanner->isExternalUrl());
        $this->get('/marketplace/bons-plans')->assertOk()->assertSee('Plaquettes Bosch Avant');
        $this->get("/marketplace/categorie/{$globalCategory->slug}/{$globalSub->slug}")->assertOk()->assertSee('Plaquettes Bosch Avant');
        $this->get("/marketplace/produit/{$product->id}")->assertStatus(301);
        $this->get("/marketplace/produit/{$product->slug}")->assertOk()->assertSee('Plaquettes Bosch Avant');

        // 3. Panier AJAX sans rechargement + protection doublon + incrémentation
        $this->postJson("/panier/ajouter/{$product->id}", ['quantity' => 1])
            ->assertOk()
            ->assertJson(['already_in_cart' => false]);

        $this->postJson("/panier/ajouter/{$product->id}", ['quantity' => 1])
            ->assertStatus(409)
            ->assertJson(['already_in_cart' => true]);

        $this->postJson("/panier/ajouter/{$product->id}", ['quantity' => 1, 'increment' => true])
            ->assertOk()
            ->assertJsonPath('cart.count', 2);

        $this->assertSame(8, $product->fresh()->stock_quantity);

        // Suppression du panier => stock automatiquement restauré à 10
        $this->deleteJson("/panier/{$product->id}")
            ->assertOk()
            ->assertJsonPath('cart.count', 0);

        $this->assertSame(10, $product->fresh()->stock_quantity);

        // Ré-ajout de 2 unités pour la commande => stock passe à 8
        $this->postJson("/panier/ajouter/{$product->id}", ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('cart.count', 2);

        $this->assertSame(8, $product->fresh()->stock_quantity);

        $this->get('/panier')->assertOk()->assertSee('Plaquettes Bosch Avant');
        $this->get('/commander')->assertOk();

        $this->post('/commander', [
            'buyer_name' => 'Jean Acheteur',
            'buyer_email' => 'acheteur@example.cm',
            'country_code' => 'CM',
            'buyer_whatsapp' => '699112233',
            'delivery_address' => 'Akwa, Douala',
        ])->assertRedirect('/marketplace');

        $order = MarketplaceOrder::firstOrFail();
        $this->assertSame('+237', $order->dial_code);
        $this->assertSame(50000, $order->total_amount);
        $this->assertSame(8, $product->fresh()->stock_quantity);

        // 4. Vendeur notifié par e-mail ET dans son espace interne
        Mail::assertSent(MarketplaceNewOrderMail::class, fn ($m) => $m->hasTo('vendeur@mp.cm'));
        $this->assertSame(1, PlatformMessage::where('tenant_id', $tenant->id)->where('is_read', false)->count());
        $this->assertSame(1, MarketplaceOrderItem::where('tenant_id', $tenant->id)->where('is_seen_by_seller', false)->count());

        // 5. Vendeur consulte ses commandes et change le statut
        $this->actingAs($user, 'web')
            ->get('http://garage-mp.mboacar.com/marketplace-gestion/commandes')
            ->assertOk();

        $orderItem = MarketplaceOrderItem::firstOrFail();
        $this->assertTrue($orderItem->is_seen_by_seller);

        $this->actingAs($user, 'web')
            ->patch("http://garage-mp.mboacar.com/marketplace-gestion/commandes/{$orderItem->id}/statut", [
                'seller_status' => 'confirmee',
            ])
            ->assertRedirect();

        $this->assertSame('confirmee', $orderItem->fresh()->seller_status);
    }

    public function test_multi_seller_cart_splits_orders_and_notifications_per_garage(): void
    {
        Mail::fake();

        $jean = Tenant::create([
            'name' => 'Garage Jean',
            'slug' => 'garage-jean',
            'email' => 'jean@garage.cm',
            'country' => 'CM',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $paul = Tenant::create([
            'name' => 'Garage Paul',
            'slug' => 'garage-paul',
            'email' => 'paul@garage.cm',
            'country' => 'CM',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $cat = Category::create(['name' => 'Moteur', 'slug' => 'moteur-multi', 'icon' => 'bi-gear', 'is_active' => true]);

        $pJean = Product::create([
            'tenant_id' => $jean->id,
            'category_id' => $cat->id,
            'title' => 'Courroie Jean',
            'slug' => 'courroie-jean',
            'condition' => 'neuf',
            'price' => 15000,
            'stock_quantity' => 5,
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        $pPaul = Product::create([
            'tenant_id' => $paul->id,
            'category_id' => $cat->id,
            'title' => 'Bougie Paul',
            'slug' => 'bougie-paul',
            'condition' => 'neuf',
            'price' => 8000,
            'stock_quantity' => 5,
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        $this->postJson("/panier/ajouter/{$pJean->id}", ['quantity' => 1])->assertOk();
        $this->postJson("/panier/ajouter/{$pPaul->id}", ['quantity' => 2])->assertOk();

        $this->post('/commander', [
            'buyer_name' => 'Acheteur Multi',
            'buyer_email' => 'acheteur@multi.cm',
            'country_code' => 'CM',
            'buyer_whatsapp' => '699112233',
            'delivery_address' => 'Yaoundé Bastos',
        ])->assertRedirect('/marketplace')->assertSessionHas('success');

        // Jean et Paul reçoivent chacun leur propre email avec uniquement leurs produits
        Mail::assertSent(MarketplaceNewOrderMail::class, fn ($m) => $m->hasTo('jean@garage.cm') && $m->sellerTotal === 15000);
        Mail::assertSent(MarketplaceNewOrderMail::class, fn ($m) => $m->hasTo('paul@garage.cm') && $m->sellerTotal === 16000);

        // Jean et Paul reçoivent chacun leur propre notification interne et leur propre MarketplaceOrderItem
        $this->assertSame(1, PlatformMessage::where('tenant_id', $jean->id)->count());
        $this->assertSame(1, PlatformMessage::where('tenant_id', $paul->id)->count());
        $this->assertSame(1, MarketplaceOrderItem::where('tenant_id', $jean->id)->where('product_title', 'Courroie Jean')->count());
        $this->assertSame(1, MarketplaceOrderItem::where('tenant_id', $paul->id)->where('product_title', 'Bougie Paul')->count());
    }
}
