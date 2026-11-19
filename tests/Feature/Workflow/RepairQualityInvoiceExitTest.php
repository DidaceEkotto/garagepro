<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairQualityInvoiceExitTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_quality_check_invoicing_and_exit_blocking_with_manager_override(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Complet',
            'slug' => 'complet',
            'email' => 'c@example.com',
            'vat_rate' => 19.25,
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Douala', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant',
            'email' => 'gerant@complet.cm',
            'password' => 'Secret123!',
        ]);
        $gerant->assignRole('gerant');

        $reception = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Réceptionniste',
            'email' => 'rec@complet.cm',
            'password' => 'Secret123!',
        ]);
        $reception->assignRole('receptionniste');

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client Final',
            'phone' => '699123456',
            'type' => 'particulier',
        ]);
        $vehicle = Vehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 777 XX',
            'brand' => 'Nissan',
            'model' => 'Qashqai',
            'mileage' => 70000,
        ]);
        $order = RepairOrder::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-2026-00010',
            'status' => RepairOrder::STATUS_IN_PROGRESS,
        ]);
        $task = RepairTask::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'repair_order_id' => $order->id,
            'title' => 'Vidange + filtres',
            'estimated_minutes' => 45,
            'status' => 'en_cours',
        ]);

        // Pointage temps
        $this->actingAs($gerant, 'web')
            ->post("http://complet.mboacar.com/atelier/taches/{$task->id}/pointage", [
                'minutes' => 40,
                'status' => 'termine',
            ])
            ->assertSessionHas('success');
        $this->assertSame(40, $task->fresh()->actual_minutes);

        // Contrôle qualité échoué -> retour atelier, puis validé -> termine
        $this->actingAs($gerant, 'web')
            ->post("http://complet.mboacar.com/atelier/or/{$order->id}/controle-qualite", [
                'road_test_done' => true,
                'road_test_mileage' => 70005,
                'passed' => false,
                'notes' => 'Bruit résiduel train avant',
            ]);
        $this->assertSame(RepairOrder::STATUS_IN_PROGRESS, $order->fresh()->status);

        $this->actingAs($gerant, 'web')
            ->post("http://complet.mboacar.com/atelier/or/{$order->id}/controle-qualite", [
                'road_test_done' => true,
                'road_test_mileage' => 70010,
                'passed' => true,
            ]);
        $this->assertSame(RepairOrder::STATUS_COMPLETED, $order->fresh()->status);

        // Facturation depuis l'OR
        $this->actingAs($gerant, 'web')
            ->post("http://complet.mboacar.com/ordres-reparation/{$order->id}/facturer", [
                'deposit_deducted' => 10000,
                'items' => [[
                    'type' => 'main_oeuvre',
                    'description' => 'Vidange complète',
                    'quantity' => 1,
                    'unit_price' => 50000,
                ]],
            ])
            ->assertSessionHas('success');

        $invoice = Invoice::withoutTenantScope()->where('repair_order_id', $order->id)->firstOrFail();
        $this->assertSame('FAC-'.now()->year.'-00001', $invoice->invoice_number);
        $this->assertSame(59625, $invoice->total_ttc); // 50 000 + 19.25% TVA (9 625)

        // Sortie bloquée pour le réceptionniste car facture impayée
        $this->actingAs($reception, 'web')
            ->post("http://complet.mboacar.com/ordres-reparation/{$order->id}/sortie", [
                'mileage_out' => 70010,
                'belongings_returned' => true,
                'old_parts_returned' => true,
                'work_explained' => true,
            ])
            ->assertSessionHasErrors('exit');
        $this->assertSame(RepairOrder::STATUS_COMPLETED, $order->fresh()->status);

        // Déblocage tracé par le gérant avec motif
        $this->actingAs($gerant, 'web')
            ->post("http://complet.mboacar.com/ordres-reparation/{$order->id}/sortie", [
                'mileage_out' => 70010,
                'belongings_returned' => true,
                'old_parts_returned' => true,
                'work_explained' => true,
                'warranty_months' => 6,
                'override_reason' => 'Client flotte entreprise avec paiement différé fin de mois',
            ])
            ->assertSessionHas('success');

        $this->assertSame(RepairOrder::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertDatabaseHas('vehicle_exits', [
            'repair_order_id' => $order->id,
            'override_by' => $gerant->id,
        ]);
    }
}
