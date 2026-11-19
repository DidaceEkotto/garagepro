<?php

namespace Tests\Feature\Tenant;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAndAfterSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_attendance_sheet_late_penalty_calculation_after_sales_and_reports(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage RH',
            'slug' => 'rh',
            'email' => 'rh@example.com',
            'settings' => [
                'work_start_time' => '08:00',
                'work_end_time' => '17:30',
                'work_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'late_penalty_per_hour' => 2000,
            ],
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Douala', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant RH',
            'email' => 'gerant@rh.cm',
            'password' => 'Secret123!',
        ]);
        $gerant->assignRole('gerant');

        // Ajout d'un professionnel via /professionnels (crée automatiquement la fiche RH)
        $this->actingAs($gerant, 'web')
            ->post('http://rh.mboacar.com/professionnels', [
                'name' => 'Moussa Mécanicien',
                'email' => 'moussa@rh.cm',
                'role' => 'mecanicien',
                'agency_id' => $agency->id,
                'gender' => 'M',
                'marital_status' => 'celibataire',
                'base_salary' => 150000,
                'commission_rate' => 5.0,
                'password' => 'Secret123!',
            ])
            ->assertSessionHas('success');

        $employee = Employee::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

        // L'ouverture de /rh pour un lundi génère automatiquement la fiche de pointage
        $monday = '2026-11-16';
        $this->actingAs($gerant, 'web')
            ->get("http://rh.mboacar.com/rh?date={$monday}")
            ->assertStatus(200);

        $attendance = EmployeeAttendance::withoutTenantScope()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $monday)
            ->firstOrFail();

        // Pointage d'arrivée à 09:00 (1h de retard => 2000 FCFA de retenue)
        $this->actingAs($gerant, 'web')
            ->post("http://rh.mboacar.com/rh/pointages/{$attendance->id}/arrivee", ['time' => '09:00'])
            ->assertSessionHas('success');

        $attendance->refresh();
        $this->assertSame(60, $attendance->late_minutes);
        $this->assertSame(2000, $attendance->late_penalty);

        // Règle de cohérence : impossible de modifier l'arrivée à 07:00 une fois déjà pointée
        $this->actingAs($gerant, 'web')
            ->post("http://rh.mboacar.com/rh/pointages/{$attendance->id}/arrivee", ['time' => '07:00'])
            ->assertSessionHas('error');

        // Règle de cohérence : impossible de pointer le départ avant l'heure d'arrivée (08:30 < 09:00)
        $this->actingAs($gerant, 'web')
            ->post("http://rh.mboacar.com/rh/pointages/{$attendance->id}/depart", ['time' => '08:30'])
            ->assertSessionHas('error');
        $this->assertNull($attendance->fresh()->checked_out_at);

        // Pointage départ valide à 17:30
        $this->actingAs($gerant, 'web')
            ->post("http://rh.mboacar.com/rh/pointages/{$attendance->id}/depart", ['time' => '17:30'])
            ->assertSessionHas('success');
        $this->assertNotNull($attendance->fresh()->checked_out_at);

        // Après-vente & fidélité
        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client Fidèle',
            'phone' => '699445566',
            'type' => 'particulier',
        ]);

        $this->actingAs($gerant, 'web')
            ->post('http://rh.mboacar.com/apres-vente/fidelite', [
                'customer_id' => $customer->id,
                'points' => 350,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('loyalty_cards', [
            'customer_id' => $customer->id,
            'points' => 350,
            'tier' => 'argent',
        ]);

        $this->actingAs($gerant, 'web')
            ->get('http://rh.mboacar.com/rapports')
            ->assertStatus(200);
    }
}
