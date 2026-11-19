<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DifferentiatingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_passport_and_live_repair_tracking_with_one_click_quote_approval(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Mboa Test',
            'slug' => 'garage-mboa-test',
            'email' => 'mboatest@test.local',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Douala',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Jean Client',
            'phone' => '699001122',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 237 MC',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2019,
            'mileage' => 84500,
        ]);

        $this->assertNotEmpty($vehicle->public_token);

        $order = RepairOrder::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-2026-0001',
            'status' => RepairOrder::STATUS_DIAGNOSTIC,
            'diagnostic_notes' => 'Plaquettes avant usées',
        ]);

        $this->assertNotEmpty($order->tracking_token);

        $quote = Quote::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'repair_order_id' => $order->id,
            'quote_number' => 'DEV-2026-0001',
            'version' => 1,
            'status' => Quote::STATUS_SENT,
            'total_ht' => 25000,
            'vat_amount' => 0,
            'total_ttc' => 25000,
        ]);

        // 1. Carnet d'entretien numérique public
        $this->get("/carnet/{$vehicle->public_token}")
            ->assertStatus(200)
            ->assertSee('LT 237 MC')
            ->assertSee('OR-2026-0001');

        // 2. Suivi client en direct public
        $this->get("/suivi/{$order->tracking_token}")
            ->assertStatus(200)
            ->assertSee('OR-2026-0001')
            ->assertSee('DEV-2026-0001');

        // 3. Validation du devis en 1 clic par le client
        $this->post("/suivi/{$order->tracking_token}/devis/{$quote->id}/repondre", [
            'decision' => 'accept',
        ])->assertRedirect();

        $this->assertSame(Quote::STATUS_ACCEPTED, $quote->fresh()->status);
    }

    public function test_b2b_marketplace_search_and_vehicle_compatibility_filters(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Garage A',
            'slug' => 'garage-a',
            'email' => 'garagea@test.local',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $tenantB = Tenant::create([
            'name' => 'Garage B',
            'slug' => 'garage-b',
            'email' => 'garageb@test.local',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Patron A',
            'email' => 'patrona@test.local',
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Freinage',
            'slug' => 'freinage',
            'is_active' => true,
        ]);

        // Produit du garage A (ne doit pas apparaître dans sa propre recherche B2B)
        Product::create([
            'tenant_id' => $tenantA->id,
            'category_id' => $category->id,
            'title' => 'Plaquettes Frein Propres',
            'slug' => 'plaquettes-a',
            'vehicle_brand' => 'Peugeot',
            'vehicle_model' => '308',
            'vehicle_year_from' => 2015,
            'vehicle_year_to' => 2021,
            'condition' => 'neuf',
            'price' => 18000,
            'stock_quantity' => 5,
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        // Produit du garage B (doit apparaître dans B2B de A et dans le filtre véhicule Toyota Corolla 2018)
        Product::create([
            'tenant_id' => $tenantB->id,
            'category_id' => $category->id,
            'title' => 'Plaquettes Frein Corolla',
            'slug' => 'plaquettes-corolla-b',
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year_from' => 2014,
            'vehicle_year_to' => 2022,
            'condition' => 'neuf',
            'price' => 22000,
            'stock_quantity' => 8,
            'country_code' => 'CM',
            'is_published' => true,
        ]);

        // B2B search par Garage A
        $res = $this->actingAs($userA, 'web')
            ->getJson('https://garage-a.mboacar.com/marketplace-b2b/rechercher?q=Plaquettes')
            ->assertStatus(200);

        $items = $res->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Plaquettes Frein Corolla', $items[0]['title']);

        // Filtre Marque / Modèle / Année sur /marketplace
        $this->get('https://mboacar.com/marketplace?vehicle_brand=Toyota&vehicle_model=Corolla&vehicle_year=2018')
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Marketplace/Index')
                ->where('hasActiveFilters', true)
                ->has('products.data', 1)
                ->where('products.data.0.title', 'Plaquettes Frein Corolla')
            );
    }
}
