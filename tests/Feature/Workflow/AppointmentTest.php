<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Appointment;
use App\Models\AppointmentLink;
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

    public function test_secure_appointment_link_lifecycle_single_use_24h_alert_account_creation_and_whatsapp_confirmation(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'msg-confirm'], 200),
        ]);

        $tenant = Tenant::create(['name' => 'Garage Lien', 'slug' => 'lienrdv', 'email' => 'lien@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Réceptionniste Lien',
            'email' => 'reclien@rdv.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');

        // 1. Générer le lien sécurisé (valable 24h)
        $this->actingAs($user, 'web')
            ->post('http://lienrdv.mboacar.com/rendez-vous/liens', [
                'agency_id' => $agency->id,
                'label' => 'Pour M. Kamga',
            ])
            ->assertSessionHas('success');

        $link = AppointmentLink::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(AppointmentLink::STATUS_ACTIVE, $link->status);
        $this->assertTrue($link->expires_at->isFuture());

        // 2. Le client ouvre et soumet le formulaire public
        $this->get("http://lienrdv.mboacar.com/rdv/{$link->token}")->assertOk();

        $scheduledAt = now()->addDays(2)->format('Y-m-d 10:00:00');
        $this->post("http://lienrdv.mboacar.com/rdv/{$link->token}", [
            'name' => 'Kamga',
            'first_name' => 'Alain',
            'gender' => 'homme',
            'id_number' => 'CNI-778899',
            'dial_code' => '+237',
            'phone' => '699887766',
            'vehicle_plate' => 'LT 999 AA',
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'RAV4',
            'reason' => 'entretien',
            'scheduled_at' => $scheduledAt,
        ])->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(AppointmentLink::STATUS_SUBMITTED, $link->status);

        // Une alerte a été créée pour le garage
        $this->assertDatabaseHas('platform_messages', [
            'tenant_id' => $tenant->id,
            'subject' => 'Nouvelle demande de rendez-vous via lien sécurisé',
            'is_read' => false,
        ]);

        // 3. Une deuxième personne ne peut pas réutiliser le même lien
        $this->post("http://lienrdv.mboacar.com/rdv/{$link->token}", [
            'name' => 'Autre',
            'gender' => 'homme',
            'id_number' => 'CNI-000111',
            'phone' => '677001122',
            'reason' => 'panne',
            'scheduled_at' => $scheduledAt,
        ])->assertSessionHasErrors('link');

        // 4. Le garage valide la demande, crée le compte client et confirme -> WhatsApp envoyé + lien mort
        $this->actingAs($user, 'web')
            ->post("http://lienrdv.mboacar.com/rendez-vous/liens/{$link->id}/valider", [
                'agency_id' => $agency->id,
                'create_customer' => true,
                'scheduled_at' => $scheduledAt,
                'bay_name' => 'Pont 1',
            ])
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(AppointmentLink::STATUS_CONFIRMED, $link->status);
        $this->assertNotNull($link->confirmed_at);
        $this->assertNotNull($link->customer_id);
        $this->assertNotNull($link->appointment_id);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Kamga',
            'id_number' => 'CNI-778899',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'tenant_id' => $tenant->id,
            'license_plate' => 'LT 999 AA',
        ]);

        // 5. Relance manuelle WhatsApp
        $this->actingAs($user, 'web')
            ->post("http://lienrdv.mboacar.com/rendez-vous/{$link->appointment_id}/rappel-whatsapp", [
                'custom_message' => 'Bonjour M. Kamga, nous vous attendons au Pont 1.',
            ])
            ->assertSessionHas('success');

        // 6. Lien expiré (> 24h) est inutilisable
        $expiredLink = AppointmentLink::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'token' => bin2hex(random_bytes(32)),
            'status' => AppointmentLink::STATUS_ACTIVE,
            'expires_at' => now()->subHour(),
        ]);

        $this->post("http://lienrdv.mboacar.com/rdv/{$expiredLink->token}", [
            'name' => 'Expiré',
            'gender' => 'homme',
            'id_number' => 'CNI-111222',
            'phone' => '699112233',
            'reason' => 'entretien',
            'scheduled_at' => $scheduledAt,
        ])->assertSessionHasErrors('link');

        $this->assertSame(AppointmentLink::STATUS_EXPIRED, $expiredLink->fresh()->status);
    }
}
