<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppointmentReminderService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_auto_creates_customer_without_queue_jobs(): void
    {

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
                'new_customer_gender' => 'homme',
                'new_customer_id_number' => 'CNI-998877',
                'new_customer_phone' => '655443322',
                'channel' => 'whatsapp',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'reason' => 'entretien',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Nouveau Client',
            'id_number' => 'CNI-998877',
        ]);

        // File d'attente sans rendez-vous
        $this->actingAs($user, 'web')
            ->post('http://rdv.mboacar.com/rendez-vous', [
                'agency_id' => $agency->id,
                'new_customer_name' => 'Client Sans RDV',
                'new_customer_gender' => 'femme',
                'new_customer_id_number' => 'PASS-445566',
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

    public function test_automatic_whatsapp_reminders_2h_and_30m_multilingual_without_jobs(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'msg-ok'], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Garage AutoRDV', 'slug' => 'autordv', 'email' => 'auto@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jean Client',
            'gender' => 'homme',
            'id_number' => 'CNI-112233',
            'phone' => '+237699112233',
        ]);

        $appt2h = Appointment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addMinutes(90),
            'reason' => 'entretien',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $appt30m = Appointment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addMinutes(20),
            'reason' => 'panne',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $service = app(AppointmentReminderService::class);
        $sent = $service->sendDueReminders('fr');

        $this->assertSame(2, $sent);
        $this->assertNotNull($appt2h->fresh()->reminder_2h_sent_at);
        $this->assertNotNull($appt30m->fresh()->reminder_30m_sent_at);

        foreach (['fr', 'en', 'es', 'ar', 'zh'] as $locale) {
            $msg = $service->buildMultilingualMessage($appt2h, '2h', $locale);
            $this->assertNotEmpty($msg);
            $this->assertStringContainsString('Jean Client', $msg);
        }
    }

    public function test_whatsapp_reminder_failure_is_non_blocking(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'gateway down'], 500),
        ]);

        $tenant = Tenant::create(['name' => 'Garage FailSafe', 'slug' => 'failsafe', 'email' => 'fs@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paul Client',
            'gender' => 'homme',
            'id_number' => 'CNI-445566',
            'phone' => '+237699445566',
        ]);

        $appt = Appointment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'scheduled_at' => now()->addMinutes(90),
            'reason' => 'entretien',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $sent = app(AppointmentReminderService::class)->sendDueReminders('fr');

        $this->assertSame(0, $sent);
        $this->assertNull($appt->fresh()->reminder_2h_sent_at);
    }
}
