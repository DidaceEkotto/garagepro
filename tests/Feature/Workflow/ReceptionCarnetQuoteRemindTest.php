<?php

namespace Tests\Feature\Workflow;

use App\Mail\ClientReminderMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReceptionCarnetQuoteRemindTest extends TestCase
{
    use RefreshDatabase;

    private function createGarageAdmin(string $slug, string $name): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'email' => "admin@{$slug}.cm",
            'status' => Tenant::STATUS_ACTIVE,
            'type' => 'garage',
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
            'name' => "Gérant {$name}",
            'email' => "admin@{$slug}.cm",
            'password' => 'Password123!',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_reception_can_be_updated(): void
    {
        [$tenant, $agency, $user] = $this->createGarageAdmin('recedit', 'Garage RecEdit');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Test',
            'phone' => '+237 690000010',
            'type' => 'particulier',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 123 AB',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'mileage' => 50000,
        ]);

        $reception = VehicleReception::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'received_by' => $user->id,
            'visit_reason' => 'entretien',
            'mileage_in' => 50000,
            'fuel_level' => 40,
            'belongings_left' => 'Cric',
            'received_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->put("http://recedit.mboacar.com/receptions/{$reception->id}", [
                'agency_id' => $agency->id,
                'visit_reason' => 'panne',
                'mileage_in' => 52500,
                'fuel_level' => 75,
                'belongings_left' => 'Cric, roue de secours, triangle',
                'notes' => 'Bruit moteur détecté',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reception->refresh();
        $this->assertSame('panne', $reception->visit_reason);
        $this->assertSame(52500, $reception->mileage_in);
        $this->assertSame(75, $reception->fuel_level);
        $this->assertSame('Cric, roue de secours, triangle', $reception->belongings_left);
        $this->assertSame(52500, $vehicle->fresh()->mileage);
    }

    public function test_public_carnet_aggregates_repair_history_across_multiple_garages(): void
    {
        [$tenantA, $agencyA] = $this->createGarageAdmin('garage-alpha', 'Garage Alpha');
        [$tenantB, $agencyB] = $this->createGarageAdmin('garage-beta', 'Garage Beta');

        $custA = Customer::create([
            'tenant_id' => $tenantA->id,
            'agency_id' => $agencyA->id,
            'name' => 'Jean Douala',
            'phone' => '+237 690000011',
            'type' => 'particulier',
        ]);
        $vehA = Vehicle::create([
            'tenant_id' => $tenantA->id,
            'customer_id' => $custA->id,
            'license_plate' => 'CE 234 AB',
            'brand' => 'Toyota',
            'model' => 'RAV4',
            'mileage' => 80000,
            'public_token' => 'token-multi-garage-test-123',
        ]);
        RepairOrder::create([
            'tenant_id' => $tenantA->id,
            'agency_id' => $agencyA->id,
            'customer_id' => $custA->id,
            'vehicle_id' => $vehA->id,
            'order_number' => 'OR-ALPHA-001',
            'status' => 'termine',
            'priority' => 'normale',
            'diagnostic_notes' => 'Vidange complète chez Alpha',
        ]);

        // Même véhicule passé dans un deuxième garage avec un formatage de plaque différent (sans espaces)
        $custB = Customer::create([
            'tenant_id' => $tenantB->id,
            'agency_id' => $agencyB->id,
            'name' => 'Jean Douala',
            'phone' => '+237 690000011',
            'type' => 'particulier',
        ]);
        $vehB = Vehicle::create([
            'tenant_id' => $tenantB->id,
            'customer_id' => $custB->id,
            'license_plate' => 'ce-234-ab',
            'brand' => 'Toyota',
            'model' => 'RAV4',
            'mileage' => 92000,
        ]);
        RepairOrder::create([
            'tenant_id' => $tenantB->id,
            'agency_id' => $agencyB->id,
            'customer_id' => $custB->id,
            'vehicle_id' => $vehB->id,
            'order_number' => 'OR-BETA-002',
            'status' => 'en_cours',
            'priority' => 'urgente',
            'diagnostic_notes' => 'Remplacement plaquettes chez Beta',
        ]);

        $response = $this->get('http://mboacar.com/carnet/token-multi-garage-test-123');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/VehiclePassport')
            ->where('garagesCount', 2)
            ->where('vehicle.mileage', 92000)
            ->has('orders', 2)
        );
    }

    public function test_quote_reminder_follows_email_whatsapp_fallback_and_none_rules(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'secret',
        ]);

        Mail::fake();
        Http::fake(['*' => Http::response(['idMessage' => 'msg-1'], 200)]);

        [$tenant, $agency, $user] = $this->createGarageAdmin('devisrem', 'Garage Devis');

        // 1. Client sans email mais avec WhatsApp -> envoie WhatsApp
        $cWaOnly = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client WA Only',
            'phone' => '+237 699111222',
            'whatsapp_phone' => '+237 699111222',
            'email' => null,
            'type' => 'particulier',
        ]);
        $v1 = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $cWaOnly->id,
            'license_plate' => 'LT 101 AA',
            'brand' => 'Honda',
            'model' => 'CRV',
        ]);
        $q1 = Quote::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $cWaOnly->id,
            'vehicle_id' => $v1->id,
            'quote_number' => 'DEV-0001',
            'version' => 1,
            'status' => Quote::STATUS_DRAFT,
            'total_ttc' => 45000,
        ]);

        $this->actingAs($user, 'web')
            ->post("http://devisrem.mboacar.com/devis/{$q1->id}/rappeler", ['channel' => 'email'])
            ->assertRedirect()
            ->assertSessionHas('success');
        Http::assertSentCount(1);
        Mail::assertNothingSent();

        // 2. Client sans WhatsApp mais avec Email -> envoie Email
        $cEmailOnly = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Email Only',
            'phone' => '',
            'whatsapp_phone' => null,
            'email' => 'client@example.com',
            'type' => 'particulier',
        ]);
        $q2 = Quote::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $cEmailOnly->id,
            'vehicle_id' => $v1->id,
            'quote_number' => 'DEV-0002',
            'version' => 1,
            'status' => Quote::STATUS_DRAFT,
            'total_ttc' => 55000,
        ]);

        $this->actingAs($user, 'web')
            ->post("http://devisrem.mboacar.com/devis/{$q2->id}/rappeler", ['channel' => 'whatsapp'])
            ->assertRedirect()
            ->assertSessionHas('success');
        Mail::assertSent(ClientReminderMail::class, 1);
        Http::assertSentCount(1);

        // 3. Client sans Email et sans WhatsApp -> n'envoie rien
        $cNeither = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Sans Contact',
            'phone' => '',
            'whatsapp_phone' => null,
            'email' => null,
            'type' => 'particulier',
        ]);
        $q3 = Quote::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $cNeither->id,
            'vehicle_id' => $v1->id,
            'quote_number' => 'DEV-0003',
            'version' => 1,
            'status' => Quote::STATUS_DRAFT,
            'total_ttc' => 65000,
        ]);

        $this->actingAs($user, 'web')
            ->post("http://devisrem.mboacar.com/devis/{$q3->id}/rappeler", ['channel' => 'auto'])
            ->assertRedirect()
            ->assertSessionHas('error');
        Mail::assertSent(ClientReminderMail::class, 1);
        Http::assertSentCount(1);
    }
}
