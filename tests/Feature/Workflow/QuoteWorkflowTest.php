<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_calculation_partial_acceptance_conversion_to_ro_and_pdf(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Devis',
            'slug' => 'devis',
            'email' => 'd@example.com',
            'vat_rate' => 19.25,
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $chef = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Chef',
            'email' => 'chef@devis.cm',
            'password' => 'Secret123!',
        ]);
        $chef->assignRole('chef_atelier');

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client Devis',
            'phone' => '690001122',
            'type' => 'particulier',
        ]);
        $vehicle = Vehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE 555 ZZ',
            'brand' => 'Toyota',
            'model' => 'Hilux',
            'mileage' => 110000,
        ]);

        $this->actingAs($chef, 'web')
            ->post('http://devis.mboacar.com/devis', [
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'items' => [
                    [
                        'type' => 'piece',
                        'part_condition' => 'neuve',
                        'urgency' => 'urgent',
                        'description' => 'Kit embrayage complet',
                        'quantity' => 1,
                        'unit_price' => 100000,
                    ],
                    [
                        'type' => 'main_oeuvre',
                        'urgency' => 'optionnel',
                        'description' => 'Nettoyage climatisation',
                        'quantity' => 1,
                        'unit_price' => 20000,
                    ],
                ],
            ])
            ->assertSessionHas('success');

        $quote = Quote::withoutTenantScope()->with('items')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(120000, $quote->subtotal_ht);
        $this->assertSame(23100, $quote->vat_amount); // 19.25% de 120 000 = 23 100 FCFA
        $this->assertSame(143100, $quote->total_ttc);

        // Nouvelle version (v2)
        $this->actingAs($chef, 'web')
            ->post("http://devis.mboacar.com/devis/{$quote->id}/nouvelle-version")
            ->assertSessionHas('success');
        $this->assertDatabaseHas('quotes', ['quote_number' => $quote->quote_number, 'version' => 2]);

        // Acceptation partielle (uniquement l'item urgent) -> conversion automatique en OR
        $urgentItem = $quote->items->firstWhere('urgency', 'urgent');
        $this->actingAs($chef, 'web')
            ->post("http://devis.mboacar.com/devis/{$quote->id}/valider", [
                'accepted_item_ids' => [$urgentItem->id],
            ])
            ->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame(Quote::STATUS_PARTIALLY_ACCEPTED, $quote->status);
        $this->assertSame(100000, $quote->subtotal_ht);
        $this->assertNotNull($quote->repair_order_id);

        $order = RepairOrder::withoutTenantScope()->findOrFail($quote->repair_order_id);
        $this->assertSame(RepairOrder::STATUS_IN_PROGRESS, $order->status);
        $this->assertCount(1, $order->tasks);

        // Génération PDF
        $pdfResponse = $this->actingAs($chef, 'web')
            ->get("http://devis.mboacar.com/devis/{$quote->id}/pdf");
        $pdfResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('Content-Type'));
    }
}
