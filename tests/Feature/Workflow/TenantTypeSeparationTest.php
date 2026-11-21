<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Country;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantTypeSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_supports_three_types_middleware_separates_spaces_and_bridges_communicate(): void
    {
        Country::seedDefaults();
        Http::fake(['*' => Http::response(['idMessage' => 'ok'], 200)]);

        // 1. Inscription d'un Centre Technique et d'une Assurance
        $this->post('/inscription', [
            'type' => 'centre_technique',
            'garage_name' => 'Centre Auto Contrôle',
            'slug' => 'centre-auto',
            'email' => 'centre@example.com',
            'phone' => '+237699000111',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertRedirect();

        $this->post('/inscription', [
            'type' => 'assurance',
            'garage_name' => 'Mboa Assurance Auto',
            'slug' => 'mboa-assur',
            'email' => 'assur@example.com',
            'phone' => '+237699000222',
            'country' => 'CM',
            'agencies_count' => 1,
            'password' => 'Secret@123',
            'password_confirmation' => 'Secret@123',
        ])->assertRedirect();

        $centreTenant = Tenant::where('slug', 'centre-auto')->firstOrFail();
        $assurTenant = Tenant::where('slug', 'mboa-assur')->firstOrFail();

        $this->assertSame(Tenant::TYPE_TECHNICAL_CENTER, $centreTenant->type);
        $this->assertSame(Tenant::TYPE_INSURANCE, $assurTenant->type);

        // Garage partenaire
        $garageTenant = Tenant::create([
            'name' => 'Garage Partenaire',
            'slug' => 'garage-part',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'garage@example.com',
        ]);
        $garageAgency = Agency::create(['tenant_id' => $garageTenant->id, 'name' => 'Agence G', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($garageTenant);
        setPermissionsTeamId($garageTenant->id);
        $garageUser = User::create([
            'tenant_id' => $garageTenant->id,
            'default_agency_id' => $garageAgency->id,
            'name' => 'Gérant Garage',
            'email' => 'gerant@garagepart.cm',
            'password' => 'Secret123!',
        ]);
        $garageUser->assignRole('gerant');

        $centreUser = User::withoutTenantScope()->where('tenant_id', $centreTenant->id)->firstOrFail();
        $assurUser = User::withoutTenantScope()->where('tenant_id', $assurTenant->id)->firstOrFail();

        // 2. Séparation par middleware
        $this->actingAs($centreUser, 'web')->get('http://centre-auto.mboacar.com/visite-technique')->assertOk();
        $this->actingAs($centreUser, 'web')->get('http://centre-auto.mboacar.com/ordres-reparation')->assertRedirect('/tableau-de-bord');
        $this->actingAs($centreUser, 'web')->get('http://centre-auto.mboacar.com/stock')->assertRedirect('/tableau-de-bord');

        $this->actingAs($assurUser, 'web')->get('http://mboa-assur.mboacar.com/assurances')->assertOk();
        $this->actingAs($assurUser, 'web')->get('http://mboa-assur.mboacar.com/visite-technique')->assertRedirect('/tableau-de-bord');
        $this->actingAs($assurUser, 'web')->get('http://mboa-assur.mboacar.com/ordres-reparation')->assertRedirect('/tableau-de-bord');

        $this->actingAs($garageUser, 'web')->get('http://garage-part.mboacar.com/ordres-reparation')->assertOk();
        $this->actingAs($garageUser, 'web')->get('http://garage-part.mboacar.com/visite-technique')->assertRedirect('/tableau-de-bord');

        // 3. Passerelle Garage -> Assurance -> Garage
        $garageCust = Customer::create([
            'tenant_id' => $garageTenant->id,
            'agency_id' => $garageAgency->id,
            'name' => 'Client Sinistré',
            'gender' => 'homme',
            'id_number' => 'CNI-SIN-1',
            'phone' => '+237699334455',
        ]);
        $garageVehicle = Vehicle::create([
            'tenant_id' => $garageTenant->id,
            'customer_id' => $garageCust->id,
            'license_plate' => 'LT 555 ZZ',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->actingAs($garageUser, 'web')->post('http://garage-part.mboacar.com/assurances/sinistres', [
            'customer_id' => $garageCust->id,
            'vehicle_id' => $garageVehicle->id,
            'insurance_tenant_id' => $assurTenant->id,
            'incident_date' => now()->toDateString(),
            'description' => 'Aile avant endommagée',
            'estimated_amount' => 300000,
        ])->assertSessionHas('success');

        $claim = InsuranceClaim::withoutTenantScope()->firstOrFail();
        $this->assertDatabaseHas('platform_messages', [
            'tenant_id' => $assurTenant->id,
            'sender_name' => 'Garage Partenaire',
        ]);

        // L'assurance approuve -> alerte envoyée au garage
        $this->actingAs($assurUser, 'web')->post("http://mboa-assur.mboacar.com/assurances/sinistres/{$claim->id}/approuver", [
            'approved_amount' => 300000,
            'insurance_share_percent' => 80,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('platform_messages', [
            'tenant_id' => $garageTenant->id,
            'sender_name' => 'Mboa Assurance Auto',
        ]);

        // 4. Passerelle Centre Technique -> Garage (Contre-visite)
        $centreCust = Customer::create([
            'tenant_id' => $centreTenant->id,
            'name' => 'Client CV',
            'gender' => 'homme',
            'id_number' => 'CNI-CV-1',
            'phone' => '+237699667788',
        ]);
        $centreVehicle = Vehicle::create([
            'tenant_id' => $centreTenant->id,
            'customer_id' => $centreCust->id,
            'license_plate' => 'CE 888 CV',
            'brand' => 'Peugeot',
            'model' => '308',
        ]);

        $pv = TechnicalInspection::create([
            'tenant_id' => $centreTenant->id,
            'vehicle_id' => $centreVehicle->id,
            'customer_id' => $centreCust->id,
            'verdict' => TechnicalInspection::VERDICT_COUNTER_VISIT,
            'defects' => [
                ['category' => 'Freinage', 'item' => 'Déséquilibre freins arrière', 'severity' => 'majeur', 'note' => ''],
            ],
        ]);

        $this->post("/pv-visite/{$pv->public_token}/demander-devis", [
            'garage_tenant_id' => $garageTenant->id,
            'client_phone' => '699667788',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('platform_messages', [
            'tenant_id' => $garageTenant->id,
            'subject' => 'Demande de devis suite à contre-visite (CE 888 CV)',
        ]);
    }
}
