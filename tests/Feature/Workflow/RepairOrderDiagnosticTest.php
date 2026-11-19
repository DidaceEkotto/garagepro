<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairOrderDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_chef_atelier_can_create_repair_order_record_diagnostic_and_schedule_task(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Atelier', 'slug' => 'atelier', 'email' => 'a@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $chef = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Chef Atelier',
            'email' => 'chef@atelier.cm',
            'password' => 'Secret123!',
        ]);
        $chef->assignRole('chef_atelier');

        $mecanicien = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Mécanicien 1',
            'email' => 'meca@atelier.cm',
            'password' => 'Secret123!',
        ]);
        $mecanicien->assignRole('mecanicien');

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client OR',
            'phone' => '699887766',
            'type' => 'particulier',
        ]);
        $vehicle = Vehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 999 AA',
            'brand' => 'Hyundai',
            'model' => 'Tucson',
            'mileage' => 60000,
        ]);

        $this->actingAs($chef, 'web')
            ->post('http://atelier.mboacar.com/ordres-reparation', [
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'assigned_mechanic_id' => $mecanicien->id,
                'priority' => 'urgente',
            ])
            ->assertSessionHas('success');

        $order = RepairOrder::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertStringStartsWith('OR-', $order->order_number);

        // Diagnostic avec checklist, codes défauts et chronométrage
        $this->actingAs($mecanicien, 'web')
            ->post("http://atelier.mboacar.com/ordres-reparation/{$order->id}/diagnostic", [
                'action' => 'finish',
                'diagnostic_checklist' => ['freins' => 'usés', 'batterie' => 'ok'],
                'fault_codes' => ['P0300', 'C1201'],
                'diagnostic_notes' => 'Plaquettes avant à remplacer.',
            ])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(RepairOrder::STATUS_DIAGNOSTIC, $order->status);
        $this->assertNotNull($order->diagnostic_ended_at);
        $this->assertSame(['P0300', 'C1201'], $order->fault_codes);

        // Planification tâche (mécanicien + pont + sous-traitant)
        $this->actingAs($chef, 'web')
            ->post("http://atelier.mboacar.com/ordres-reparation/{$order->id}/taches", [
                'title' => 'Peinture pare-chocs avant',
                'assigned_mechanic_id' => $mecanicien->id,
                'bay_name' => 'Pont 2',
                'subcontractor_name' => 'Carrosserie Pro',
                'subcontractor_type' => 'peinture',
                'estimated_minutes' => 120,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('repair_tasks', [
            'repair_order_id' => $order->id,
            'bay_name' => 'Pont 2',
            'subcontractor_type' => 'peinture',
        ]);
    }
}
