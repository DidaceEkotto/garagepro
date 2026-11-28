<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Category;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function createSellerWithProductAndOrder(string $tenantType, string $slug, int $stock = 5, int $qty = 2): array
    {
        $tenant = Tenant::create([
            'name' => "Vendeur {$slug}",
            'slug' => $slug,
            'type' => $tenantType,
            'email' => "{$slug}@mboacar.test",
            'phone' => '+237690000111',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addDays(30),
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'is_main' => true,
        ]);

        app(TenantRoleService::class)->provisionForTenant($tenant);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Gérant Test',
            'email' => "gerant-{$slug}@mboacar.test",
            'password' => 'Password123!',
            'role' => 'gerant',
            'is_active' => true,
        ]);
        setPermissionsTeamId($tenant->id);
        $user->assignRole('gerant');

        $category = Category::create([
            'tenant_id' => null,
            'name' => 'Freinage',
            'slug' => "freinage-{$slug}",
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'title' => 'Plaquettes de frein Bosch',
            'slug' => "plaquettes-{$slug}",
            'price' => 15000,
            'stock_quantity' => $stock,
            'is_published' => true,
        ]);

        $order = MarketplaceOrder::create([
            'order_number' => 'MKP-'.strtoupper($slug),
            'buyer_name' => 'Paul Client',
            'buyer_email' => 'paul@client.test',
            'country_code' => 'CM',
            'dial_code' => '+237',
            'buyer_whatsapp' => '699112233',
            'delivery_address' => 'Akwa, Douala',
            'total_amount' => 15000 * $qty,
            'status' => 'nouvelle',
        ]);

        $item = MarketplaceOrderItem::create([
            'marketplace_order_id' => $order->id,
            'marketplace_product_id' => $product->id,
            'tenant_id' => $tenant->id,
            'product_title' => $product->title,
            'unit_price' => 15000,
            'quantity' => $qty,
            'subtotal' => 15000 * $qty,
            'seller_status' => 'nouvelle',
            'is_seen_by_seller' => false,
        ]);

        return [$tenant, $user, $product, $order, $item];
    }

    public function test_confirm_and_deliver_workflow_sends_whatsapp_locks_order_and_updates_stats_for_garage_and_technical_center(): void
    {
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-abc',
        ]);

        foreach ([Tenant::TYPE_GARAGE, Tenant::TYPE_TECHNICAL_CENTER, Tenant::TYPE_PARTS_SHOP] as $idx => $type) {
            Http::fake([
                'https://api.green-api.com/*' => Http::response(['idMessage' => "WA-{$idx}"], 200),
            ]);

            $slug = "seller-{$idx}";
            [$tenant, $user, $product, $order, $item] = $this->createSellerWithProductAndOrder($type, $slug, stock: 5, qty: 2);
            $baseUrl = "http://{$slug}.mboacar.com";

            // 1. Confirmer la commande -> WhatsApp envoyé
            $this->actingAs($user, 'web')
                ->patch("{$baseUrl}/marketplace-gestion/commandes/{$item->id}/statut", ['seller_status' => 'confirmee'])
                ->assertRedirect();

            $this->assertSame('confirmee', $item->fresh()->seller_status);
            Http::assertSent(fn ($req) => str_contains($req['message'], 'COMMANDE CONFIRMÉE'));

            // 2. Marquer comme livrée -> WhatsApp de remerciement envoyé + verrouillage + stats boutique
            $this->actingAs($user, 'web')
                ->patch("{$baseUrl}/marketplace-gestion/commandes/{$item->id}/statut", ['seller_status' => 'livree'])
                ->assertRedirect();

            $this->assertSame('livree', $item->fresh()->seller_status);
            Http::assertSent(fn ($req) => str_contains($req['message'], 'MERCI POUR VOTRE ACHAT'));

            // 3. Impossible de modifier ou d'annuler une commande déjà livrée
            $this->actingAs($user, 'web')
                ->patch("{$baseUrl}/marketplace-gestion/commandes/{$item->id}/statut", ['seller_status' => 'annulee'])
                ->assertRedirect()
                ->assertSessionHas('error');

            $this->assertSame('livree', $item->fresh()->seller_status);
            $this->assertSame(5, $product->fresh()->stock_quantity);

            // 4. Les statistiques de la boutique comptabilisent bien cette vente livrée
            $this->actingAs($user, 'web')
                ->get("{$baseUrl}/marketplace-gestion/commandes")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Tenant/Marketplace/Orders')
                    ->where('shopStats.deliveredOrders', 1)
                    ->where('shopStats.soldUnits', 2)
                    ->where('shopStats.soldRevenue', 30000)
                );
        }
    }

    public function test_cancelling_order_restores_product_stock_once_and_locks_order(): void
    {
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-abc',
        ]);

        Http::fake([
            'https://api.green-api.com/*' => Http::response(['idMessage' => 'WA-CANCEL'], 200),
        ]);

        [$tenant, $user, $product, $order, $item] = $this->createSellerWithProductAndOrder(
            Tenant::TYPE_GARAGE,
            'cancel-garage',
            stock: 4,
            qty: 3
        );
        $baseUrl = 'http://cancel-garage.mboacar.com';

        // Annulation : le stock passe de 4 -> 7 (+3)
        $this->actingAs($user, 'web')
            ->patch("{$baseUrl}/marketplace-gestion/commandes/{$item->id}/statut", ['seller_status' => 'annulee'])
            ->assertRedirect();

        $this->assertSame('annulee', $item->fresh()->seller_status);
        $this->assertSame(7, $product->fresh()->stock_quantity);
        Http::assertSent(fn ($req) => str_contains($req['message'], 'ANNULATION DE COMMANDE'));

        // Tentative de ré-annuler ou livrer : bloquée, stock reste à 7 (pas de double restauration)
        $this->actingAs($user, 'web')
            ->patch("{$baseUrl}/marketplace-gestion/commandes/{$item->id}/statut", ['seller_status' => 'livree'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('annulee', $item->fresh()->seller_status);
        $this->assertSame(7, $product->fresh()->stock_quantity);
    }
}
