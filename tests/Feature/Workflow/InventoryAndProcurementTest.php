<?php

namespace Tests\Feature\Workflow;

use App\Mail\PurchaseOrderMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\RepairOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InventoryAndProcurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_reservation_issue_po_reception_and_inter_agency_transfer(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Stock', 'slug' => 'stock', 'email' => 's@example.com']);
        $agency1 = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Douala', 'is_main' => true]);
        $agency2 = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Yaoundé', 'is_main' => false]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $magasinier = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency1->id,
            'name' => 'Magasinier',
            'email' => 'mag@stock.cm',
            'password' => 'Secret123!',
        ]);
        $magasinier->assignRole('magasinier');

        $this->actingAs($magasinier, 'web')
            ->post('http://stock.mboacar.com/stock/pieces', [
                'agency_id' => $agency1->id,
                'sku' => 'FIL-HUILE-01',
                'name' => 'Filtre à huile Toyota',
                'category' => 'piece',
                'condition' => 'neuve',
                'location' => 'Rayon A1',
                'quantity_on_hand' => 10,
                'min_threshold' => 2,
                'purchase_price' => 3500,
                'selling_price' => 6000,
            ])
            ->assertSessionHas('success');

        $part = Part::withoutTenantScope()->where('sku', 'FIL-HUILE-01')->firstOrFail();

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client Stock',
            'phone' => '699000111',
            'type' => 'particulier',
        ]);
        $vehicle = Vehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 404 ST',
            'brand' => 'Toyota',
            'model' => 'Yaris',
            'mileage' => 50000,
        ]);
        $order = RepairOrder::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency1->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-2026-00001',
            'status' => RepairOrder::STATUS_IN_PROGRESS,
        ]);

        // Réservation puis sortie vers OR
        $this->actingAs($magasinier, 'web')
            ->post("http://stock.mboacar.com/stock/pieces/{$part->id}/reserver", [
                'repair_order_id' => $order->id,
                'quantity' => 2,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, $part->fresh()->quantity_reserved);

        $mechanic = \App\Models\Employee::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency1->id,
            'full_name' => 'Marc Garagiste',
            'role_title' => 'Mécanicien',
            'base_salary' => 120000,
            'is_active' => true,
        ]);

        $this->actingAs($magasinier, 'web')
            ->post("http://stock.mboacar.com/stock/pieces/{$part->id}/sortie", [
                'repair_order_id' => $order->id,
                'requested_by_employee_id' => $mechanic->id,
                'quantity' => 2,
                'from_reservation' => true,
            ])
            ->assertSessionHas('success');

        $part->refresh();
        $this->assertSame(8, $part->quantity_on_hand);
        $this->assertSame(0, $part->quantity_reserved);

        // Transfert inter-agences (3 unités vers Yaoundé)
        $this->actingAs($magasinier, 'web')
            ->post("http://stock.mboacar.com/stock/pieces/{$part->id}/transfert", [
                'target_agency_id' => $agency2->id,
                'quantity' => 3,
            ])
            ->assertSessionHas('success');

        $this->assertSame(5, $part->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('parts', [
            'agency_id' => $agency2->id,
            'sku' => 'FIL-HUILE-01',
            'quantity_on_hand' => 3,
        ]);

        // Fournisseur (email obligatoire) & bon de commande + envoi email + relance + réception
        Mail::fake();

        $this->actingAs($magasinier, 'web')
            ->post('http://stock.mboacar.com/stock/fournisseurs', [
                'name' => 'CFAO Pièces',
                'email' => 'commandes@cfao.cm',
                'phone' => '233420000',
                'city' => 'Douala',
            ])
            ->assertSessionHas('success');

        $supplier = Supplier::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($magasinier, 'web')
            ->post('http://stock.mboacar.com/approvisionnement', [
                'agency_id' => $agency1->id,
                'supplier_id' => $supplier->id,
                'items' => [[
                    'part_id' => $part->id,
                    'description' => 'Filtre à huile Toyota',
                    'quantity_ordered' => 5,
                    'unit_cost' => 3500,
                ]],
            ])
            ->assertSessionHas('success');

        Mail::assertQueued(PurchaseOrderMail::class, fn ($m) => $m->hasTo('commandes@cfao.cm') && ! $m->isReminder);

        $po = PurchaseOrder::withoutTenantScope()->with('items')->where('tenant_id', $tenant->id)->firstOrFail();

        // Relance fournisseur
        $this->actingAs($magasinier, 'web')
            ->post("http://stock.mboacar.com/approvisionnement/{$po->id}/relancer")
            ->assertSessionHas('success');

        Mail::assertQueued(PurchaseOrderMail::class, fn ($m) => $m->hasTo('commandes@cfao.cm') && $m->isReminder);

        $item = $po->items->first();

        $this->actingAs($magasinier, 'web')
            ->post("http://stock.mboacar.com/approvisionnement/{$po->id}/reception", [
                'received' => [$item->id => 5],
            ])
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $po->fresh()->status);
        $this->assertSame(10, $part->fresh()->quantity_on_hand);
    }
}
