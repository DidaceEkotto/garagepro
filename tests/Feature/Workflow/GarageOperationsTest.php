<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Appointment;
use App\Models\BulkConsumable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SubcontractIntervention;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Models\WorkshopTool;
use App\Services\MaintenanceReminderService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GarageOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_six_garage_operations_modules_work_without_errors(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'msg-ok'], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Garage Pro Plus', 'slug' => 'proplus', 'email' => 'proplus@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence Principale', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Chef Atelier',
            'email' => 'chef@proplus.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Transports Express',
            'gender' => 'homme',
            'id_number' => 'CNI-554433',
            'phone' => '+237699554433',
            'whatsapp_phone' => '+237699554433',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 777 BB',
            'brand' => 'Toyota',
            'model' => 'Hilux',
        ]);

        // 1. Inspections Visuelles (DVI)
        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/inspections')->assertOk();
        $this->actingAs($user, 'web')->post('http://proplus.mboacar.com/inspections', [
            'agency_id' => $agency->id,
            'vehicle_id' => $vehicle->id,
            'checklist' => [
                ['category' => 'Freinage', 'item' => 'Plaquettes avant', 'state' => 'urgent', 'note' => 'Usées à 90%'],
                ['category' => 'Pneus', 'item' => 'Pression', 'state' => 'ok', 'note' => ''],
            ],
            'summary_notes' => 'Remplacement urgent des plaquettes.',
        ])->assertSessionHas('success');

        $inspection = VehicleInspection::withoutTenantScope()->firstOrFail();
        $this->actingAs($user, 'web')
            ->post("http://proplus.mboacar.com/inspections/{$inspection->id}/whatsapp")
            ->assertSessionHas('success');
        $this->assertNotNull($inspection->fresh()->whatsapp_sent_at);

        // 2. Planning Atelier
        $appt = Appointment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addHours(3),
            'reason' => 'entretien',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/planning-atelier')->assertOk();
        $this->actingAs($user, 'web')->patch("http://proplus.mboacar.com/planning-atelier/rdv/{$appt->id}", [
            'bay_name' => 'Pont 1',
            'assigned_mechanic_id' => $user->id,
        ])->assertSessionHas('success');
        $this->assertSame('Pont 1', $appt->fresh()->bay_name);

        // 3. Rappels Périodiques (Vidange / Visite Technique / Assurance)
        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/rappels-entretien')->assertOk();
        $this->actingAs($user, 'web')->patch("http://proplus.mboacar.com/rappels-entretien/{$vehicle->id}", [
            'next_service_date' => now()->addDays(5)->toDateString(),
            'next_service_mileage' => 120000,
            'technical_inspection_expires_at' => now()->addDays(10)->toDateString(),
            'insurance_expires_at' => now()->addDays(30)->toDateString(),
        ])->assertSessionHas('success');

        $sentMaint = app(MaintenanceReminderService::class)->sendDueReminders('fr');
        $this->assertSame(1, $sentMaint);
        $this->assertNotNull($vehicle->fresh()->maintenance_reminder_sent_at);

        // 4. Flottes Entreprises (B2B) & Facture Mensuelle Groupée
        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/flottes')->assertOk();
        $this->actingAs($user, 'web')->post("http://proplus.mboacar.com/flottes/{$customer->id}/parametres", [
            'company_name' => 'Transports Express SARL',
            'credit_limit' => 1500000,
            'fleet_manager_name' => 'M. Ndongo',
        ])->assertSessionHas('success');

        $this->actingAs($user, 'web')->post("http://proplus.mboacar.com/flottes/{$customer->id}/facture-mensuelle", [
            'month' => now()->format('Y-m'),
            'flat_amount' => 250000,
            'description' => 'Forfait entretien flotte mensuel',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('invoices', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'is_deferred_monthly' => true,
            'subtotal_ht' => 250000,
        ]);

        // 5. Sous-traitance & Dépannage / Remorquage
        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/sous-traitance')->assertOk();
        $this->actingAs($user, 'web')->post('http://proplus.mboacar.com/sous-traitance', [
            'type' => 'remorquage',
            'provider_name' => 'SOS Dépannage Douala',
            'description' => 'Remorquage autoroute Est',
            'cost_amount' => 25000,
            'billed_amount' => 40000,
        ])->assertSessionHas('success');

        $sub = SubcontractIntervention::withoutTenantScope()->firstOrFail();
        $this->actingAs($user, 'web')->patch("http://proplus.mboacar.com/sous-traitance/{$sub->id}/statut", [
            'status' => 'termine',
        ])->assertSessionHas('success');
        $this->assertSame('termine', $sub->fresh()->status);

        // 6. Outillage & Consommables en Vrac
        $this->actingAs($user, 'web')->get('http://proplus.mboacar.com/outillage-vrac')->assertOk();
        $this->actingAs($user, 'web')->post('http://proplus.mboacar.com/outillage-vrac/outils', [
            'name' => 'Valise Diagnostic Autel',
            'code' => 'DIAG-01',
            'category' => 'Diagnostic',
        ])->assertSessionHas('success');

        $tool = WorkshopTool::withoutTenantScope()->firstOrFail();
        $this->actingAs($user, 'web')->post("http://proplus.mboacar.com/outillage-vrac/outils/{$tool->id}/emprunter", [
            'borrowed_by_user_id' => $user->id,
        ])->assertSessionHas('success');
        $this->assertSame('emprunte', $tool->fresh()->status);

        $this->actingAs($user, 'web')->post("http://proplus.mboacar.com/outillage-vrac/outils/{$tool->id}/rendre")
            ->assertSessionHas('success');
        $this->assertSame('disponible', $tool->fresh()->status);

        $this->actingAs($user, 'web')->post('http://proplus.mboacar.com/outillage-vrac/vrac', [
            'name' => 'Huile Moteur 5W30',
            'unit' => 'L',
            'container_capacity' => 200,
            'current_quantity' => 200,
            'alert_threshold' => 20,
        ])->assertSessionHas('success');

        $bulk = BulkConsumable::withoutTenantScope()->firstOrFail();
        $this->actingAs($user, 'web')->post("http://proplus.mboacar.com/outillage-vrac/vrac/{$bulk->id}/mouvement", [
            'type' => 'sortie',
            'quantity' => 5.5,
            'notes' => 'Vidange Hilux',
        ])->assertSessionHas('success');
        $this->assertEquals(194.5, (float) $bulk->fresh()->current_quantity);
    }
}
