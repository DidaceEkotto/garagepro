<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerVehicleTest extends TestCase
{
    use RefreshDatabase;

    public function test_receptionist_can_create_customer_vehicle_and_search_by_plate_or_phone(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Centre', 'slug' => 'centre', 'email' => 'c@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Principale', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Alice Réception',
            'email' => 'alice@centre.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');
        $user->agencies()->attach($agency->id, ['tenant_id' => $tenant->id]);

        $this->actingAs($user, 'web')
            ->post('http://centre.mboacar.com/clients', [
                'name' => 'M. Kamga',
                'first_name' => 'Jean',
                'gender' => 'homme',
                'id_number' => 'CNI-11223344',
                'phone_dial_code' => '+237',
                'phone' => '699112233',
                'type' => 'particulier',
                'city' => 'Douala',
                'profession' => 'Commerçant',
                'description' => 'Client fidèle',
            ])
            ->assertSessionHas('success');

        $customer = Customer::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('+237 699112233', $customer->whatsapp_phone);
        $this->assertSame('CNI-11223344', $customer->id_number);
        $this->assertSame('homme', $customer->gender);

        $this->actingAs($user, 'web')
            ->post('http://centre.mboacar.com/vehicules', [
                'customer_id' => $customer->id,
                'license_plate' => 'LT 123 AB',
                'brand' => 'Toyota',
                'model' => 'Corolla',
                'fuel_type' => 'essence',
                'transmission' => 'automatique',
                'mileage' => 84500,
                'vin' => 'JTDBR32E000123456',
                'year' => 2020,
                'color' => 'Gris',
                'notes' => 'Véhicule entretenu',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('vehicles', [
            'tenant_id' => $tenant->id,
            'license_plate' => 'LT 123 AB',
            'vin' => 'JTDBR32E000123456',
            'year' => 2020,
            'color' => 'Gris',
        ]);

        // Relance client via WhatsApp (Green-API)
        config([
            'services.greenapi.url' => 'https://api.greenapi.test',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'secret-token',
        ]);
        Http::fake([
            'https://api.greenapi.test/*' => Http::response(['idMessage' => 'WA-REM-1'], 200),
        ]);

        $this->actingAs($user, 'web')
            ->post("http://centre.mboacar.com/clients/{$customer->id}/relancer-whatsapp", [
                'message' => 'Bonjour M. Kamga, votre véhicule est prêt.',
            ])
            ->assertSessionHas('success');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'waInstance123456/sendMessage/secret-token')
            && $req['chatId'] === '237699112233@c.us');

        // Recherche par immatriculation sur /clients et /vehicules
        $this->actingAs($user, 'web')
            ->get('http://centre.mboacar.com/clients?q=LT+123')
            ->assertStatus(200)
            ->assertSee('M. Kamga');

        $this->actingAs($user, 'web')
            ->get('http://centre.mboacar.com/vehicules?q=LT+123')
            ->assertStatus(200)
            ->assertSee('LT 123 AB')
            ->assertSee('M. Kamga');
    }

    public function test_customer_and_vehicle_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Garage A', 'slug' => 'ga', 'email' => 'ga@example.com']);
        $tenantB = Tenant::create(['name' => 'Garage B', 'slug' => 'gb', 'email' => 'gb@example.com']);

        app(TenantRoleService::class)->provisionForTenant($tenantA);
        setPermissionsTeamId($tenantA->id);

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'ua@ga.cm',
            'password' => 'Secret123!',
        ]);
        $userA->assignRole('gerant');

        $customerB = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Client Secret B',
            'phone' => '677000000',
            'type' => 'particulier',
        ]);

        // Le global scope renvoie 404 (inexistant pour tenant A)
        $this->actingAs($userA, 'web')
            ->get("http://ga.mboacar.com/clients/{$customerB->id}")
            ->assertStatus(404);
    }
}
