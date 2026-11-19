<?php

namespace Tests\Feature\Workflow;

use App\Jobs\SendAppointmentReminderJob;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_auto_creates_customer_and_dispatches_reminder_job(): void
    {
        Queue::fake();

        $tenant = Tenant::create(['name' => 'Garage RDV', 'slug' => 'rdv', 'email' => 'rdv@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Réceptionniste',
            'email' => 'rec@rdv.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');

        $this->actingAs($user, 'web')
            ->post('http://rdv.mboacar.com/rendez-vous', [
                'agency_id' => $agency->id,
                'new_customer_name' => 'Nouveau Client',
                'new_customer_phone' => '655443322',
                'channel' => 'whatsapp',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'reason' => 'entretien',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Nouveau Client',
        ]);

        Queue::assertPushed(SendAppointmentReminderJob::class);

        // File d'attente sans rendez-vous
        $this->actingAs($user, 'web')
            ->post('http://rdv.mboacar.com/rendez-vous', [
                'agency_id' => $agency->id,
                'new_customer_name' => 'Client Sans RDV',
                'new_customer_phone' => '655000111',
                'channel' => 'sans_rdv',
                'reason' => 'panne',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $tenant->id,
            'channel' => 'sans_rdv',
            'status' => Appointment::STATUS_WALK_IN_QUEUE,
        ]);
    }
}
