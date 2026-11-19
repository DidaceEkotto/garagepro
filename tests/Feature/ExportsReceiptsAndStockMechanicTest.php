<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Part;
use App\Models\Payment;
use App\Models\RepairOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportsReceiptsAndStockMechanicTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Pro Test',
            'slug' => 'garage-export',
            'email' => 'gerant@export.cm',
            'country' => 'CM',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'code' => 'AG01',
            'is_main' => true,
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant Export',
            'email' => 'gerant@export.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_stock_exit_requires_mechanic_selection_and_records_it(): void
    {
        [$tenant, $agency, $user] = $this->setupTenant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Stock',
            'phone' => '+237 699001122',
            'type' => 'particulier',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE-555-AA',
            'brand' => 'Peugeot',
            'model' => '308',
            'mileage' => 80000,
        ]);

        $order = RepairOrder::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'created_by' => $user->id,
            'order_number' => 'OR-2026-0001',
            'status' => 'en_cours',
        ]);

        $part = Part::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'sku' => 'FLT-001',
            'name' => 'Filtre à huile',
            'category' => 'piece',
            'condition' => 'neuve',
            'quantity_on_hand' => 10,
            'min_threshold' => 2,
            'purchase_price' => 3000,
            'selling_price' => 5000,
        ]);

        $mechanic = Employee::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'full_name' => 'Paul Mécanicien',
            'role_title' => 'Mécanicien',
            'base_salary' => 150000,
            'is_active' => true,
        ]);

        // Sans garagiste => erreur de validation
        $this->actingAs($user, 'web')
            ->post("http://garage-export.mboacar.com/stock/pieces/{$part->id}/sortie", [
                'repair_order_id' => $order->id,
                'quantity' => 2,
            ])
            ->assertSessionHasErrors('requested_by_employee_id');

        // Avec garagiste => succès et enregistré
        $this->actingAs($user, 'web')
            ->post("http://garage-export.mboacar.com/stock/pieces/{$part->id}/sortie", [
                'repair_order_id' => $order->id,
                'requested_by_employee_id' => $mechanic->id,
                'quantity' => 2,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $movement = StockMovement::withoutTenantScope()->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame($mechanic->id, $movement->requested_by_employee_id);
        $this->assertSame(8, $part->fresh()->quantity_on_hand);
    }

    public function test_excel_exports_and_printable_receipts_work(): void
    {
        [$tenant, $agency, $user] = $this->setupTenant();

        foreach (['clients', 'factures', 'stock', 'rapports', 'rh'] as $type) {
            $res = $this->actingAs($user, 'web')
                ->get("http://garage-export.mboacar.com/export-excel/{$type}");
            $res->assertOk();
            $this->assertStringContainsString('application/vnd.ms-excel', $res->headers->get('Content-Type'));
        }

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'payable_type' => Tenant::class,
            'payable_id' => $tenant->id,
            'payment_method' => 'especes',
            'amount' => 10000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'idempotency_key' => 'k-rcpt-1',
            'reference_code' => 'PAY-RCPT1',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->get("http://garage-export.mboacar.com/paiements/{$payment->id}/imprimer")
            ->assertOk()
            ->assertSee('PAY-RCPT1')
            ->assertSee('window.print()', false);

        $this->actingAs($user, 'web')
            ->get("http://garage-export.mboacar.com/paiements/{$payment->id}/recu-pdf")
            ->assertOk();
    }
}
