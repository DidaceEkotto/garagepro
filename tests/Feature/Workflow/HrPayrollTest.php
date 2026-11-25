<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HrAttendanceAlertService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_attendance_tolerance_overtime_advances_payslips_pdf_and_whatsapp(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake(['*' => Http::response(['idMessage' => 'ok'], 200)]);

        $tenant = Tenant::create([
            'name' => 'Garage RH Pro',
            'slug' => 'rhpro',
            'email' => 'rh@example.com',
            'settings' => [
                'work_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'work_start_time' => '08:00',
                'work_end_time' => '17:00',
                'late_penalty_per_hour' => 1000,
                'late_tolerance_minutes' => 10,
                'overtime_rate_per_hour' => 2000,
            ],
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant RH',
            'email' => 'gerant@rhpro.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'full_name' => 'Talla Mécanicien',
            'role_title' => 'Mécanicien',
            'phone' => '+237699112233',
            'base_salary' => 150000,
            'is_active' => true,
        ]);

        // Ouvre /rh deux fois le même jour -> ne doit pas provoquer d'erreur UNIQUE sous SQLite
        $today = now()->toDateString();
        $this->actingAs($user, 'web')->get("http://rhpro.mboacar.com/rh?date={$today}")->assertOk();
        $this->actingAs($user, 'web')->get("http://rhpro.mboacar.com/rh?date={$today}")->assertOk();

        $att = EmployeeAttendance::withoutTenantScope()->where('employee_id', $employee->id)->firstOrFail();

        // Arrivée avec 30 min de retard (> 10 min tolérance) -> 500 FCFA de retenue
        $this->actingAs($user, 'web')->post("http://rhpro.mboacar.com/rh/pointages/{$att->id}/arrivee", [
            'time' => '08:30',
        ])->assertSessionHas('success');

        $att->refresh();
        $this->assertSame('retard', $att->status);
        $this->assertSame(30, $att->late_minutes);
        $this->assertSame(500, $att->late_penalty);

        // Départ à 18:00 (+60 min sup à 2000/h = +2000 FCFA)
        $this->actingAs($user, 'web')->post("http://rhpro.mboacar.com/rh/pointages/{$att->id}/depart", [
            'time' => '18:00',
        ])->assertSessionHas('success');

        $att->refresh();
        $this->assertSame(60, $att->overtime_minutes);
        $this->assertSame(2000, $att->overtime_bonus);

        // Avance 25 000 + Prime 10 000
        $this->actingAs($user, 'web')->post('http://rhpro.mboacar.com/rh/ajustements', [
            'employee_id' => $employee->id,
            'type' => 'avance',
            'amount' => 25000,
            'label' => 'Acompte mi-mois',
            'effective_date' => $today,
        ])->assertSessionHas('success');

        $this->actingAs($user, 'web')->post('http://rhpro.mboacar.com/rh/ajustements', [
            'employee_id' => $employee->id,
            'type' => 'prime',
            'amount' => 10000,
            'label' => 'Prime transport',
            'effective_date' => $today,
        ])->assertSessionHas('success');

        // Générer les bulletins du mois
        $periodMonth = now()->format('Y-m');
        $this->actingAs($user, 'web')->post('http://rhpro.mboacar.com/rh/bulletins/generer', [
            'period_month' => $periodMonth,
        ])->assertSessionHas('success');

        $payslip = Payslip::withoutTenantScope()->where('employee_id', $employee->id)->firstOrFail();
        // Net = 150000 + 10000 + 2000 - 500 - 25000 = 136500
        $this->assertSame(136500, $payslip->net_salary);

        // Payer le bulletin -> crée une dépense dans salaires
        $this->actingAs($user, 'web')->post("http://rhpro.mboacar.com/rh/bulletins/{$payslip->id}/payer", [
            'payment_method' => 'mobile_money',
        ])->assertSessionHas('success');

        $this->assertSame(Payslip::STATUS_PAID, $payslip->fresh()->status);
        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $tenant->id,
            'category' => 'salaires',
            'amount' => 136500,
        ]);

        // Télécharger PDF + Envoi WhatsApp
        $this->actingAs($user, 'web')->get("http://rhpro.mboacar.com/rh/bulletins/{$payslip->id}/pdf")->assertOk();
        $this->actingAs($user, 'web')->post("http://rhpro.mboacar.com/rh/bulletins/{$payslip->id}/whatsapp")->assertSessionHas('success');
        $this->assertNotNull($payslip->fresh()->whatsapp_sent_at);
    }

    public function test_missing_attendance_sends_automatic_whatsapp_alert_to_account_owner_once_per_workday(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake(['*' => Http::response(['idMessage' => 'ok'], 200)]);
        Cache::flush();

        $todayKey = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][now()->dayOfWeek];

        $tenant = Tenant::create([
            'name' => 'Garage Alerte RH',
            'slug' => 'alerterh',
            'email' => 'owner@alerterh.cm',
            'phone' => '+237690001122',
            'status' => Tenant::STATUS_ACTIVE,
            'settings' => [
                'work_days' => [$todayKey],
                'work_start_time' => '08:00',
                'work_end_time' => '17:00',
            ],
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Atelier Principal',
            'is_main' => true,
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Fondateur Garage',
            'email' => 'owner@alerterh.cm',
            'phone' => '+237699112233',
            'password' => 'Password123!',
            'is_active' => true,
        ]);

        Employee::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'full_name' => 'Paul Mécanicien',
            'role_title' => 'Mécanicien',
            'base_salary' => 120000,
            'is_active' => true,
        ]);

        $service = app(HrAttendanceAlertService::class);

        // Premier appel : aucune fiche remplie -> 1 alerte envoyée au fondateur
        $sentFirst = $service->sendMissingAttendanceAlerts(true);
        $this->assertSame(1, $sentFirst);

        // Deuxième appel le même jour : ne renvoie pas (cache journalier)
        $sentSecond = $service->sendMissingAttendanceAlerts(true);
        $this->assertSame(0, $sentSecond);

        Http::assertSentCount(1);
    }

    public function test_customer_can_be_updated_from_clients_route(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Client Edit',
            'slug' => 'clientedit',
            'email' => 'admin@clientedit.cm',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'is_main' => true,
        ]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant',
            'email' => 'admin@clientedit.cm',
            'password' => 'Password123!',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Ancien Nom',
            'phone' => '+237 690000001',
            'type' => 'particulier',
        ]);

        $this->actingAs($user, 'web')
            ->put("http://clientedit.mboacar.com/clients/{$customer->id}", [
                'name' => 'Nouveau Nom',
                'first_name' => 'Jean',
                'gender' => 'homme',
                'id_number' => 'CM99887766',
                'phone_dial_code' => '+237',
                'phone' => '699887766',
                'email' => 'jean@example.com',
                'type' => 'entreprise',
                'city' => 'Douala',
                'company_name' => 'Transport SARL',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('Nouveau Nom', $customer->name);
        $this->assertSame('Jean', $customer->first_name);
        $this->assertSame('entreprise', $customer->type);
        $this->assertSame('Douala', $customer->city);
        $this->assertSame('+237 699887766', $customer->phone);
    }
}
