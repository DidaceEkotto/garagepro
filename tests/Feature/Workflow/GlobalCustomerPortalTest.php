<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\GlobalCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalCustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_global_customer_links_across_garages_prefills_and_works_in_portal(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'msg-otp'], 200),
        ]);

        // Garage A
        $tenantA = Tenant::create(['name' => 'Garage Alpha', 'slug' => 'alpha', 'email' => 'alpha@example.com']);
        $agencyA = Agency::create(['tenant_id' => $tenantA->id, 'name' => 'Agence A', 'is_main' => true]);

        // Garage B
        $tenantB = Tenant::create(['name' => 'Garage Beta', 'slug' => 'beta', 'email' => 'beta@example.com']);
        $agencyB = Agency::create(['tenant_id' => $tenantB->id, 'name' => 'Agence B', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenantB);
        setPermissionsTeamId($tenantB->id);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'default_agency_id' => $agencyB->id,
            'name' => 'Réceptionniste B',
            'email' => 'rec@beta.cm',
            'password' => 'Secret123!',
        ]);
        $userB->assignRole('receptionniste');

        // Client créé au Garage A -> crée automatiquement le GlobalCustomer
        $custA = Customer::create([
            'tenant_id' => $tenantA->id,
            'agency_id' => $agencyA->id,
            'name' => 'Dupont',
            'first_name' => 'Jean',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'phone_dial_code' => '+237',
            'phone' => '+237699123456',
            'whatsapp_phone' => '+237699123456',
            'profession' => 'Ingénieur',
            'notes' => 'Note privée Garage A',
        ]);

        Vehicle::create([
            'tenant_id' => $tenantA->id,
            'customer_id' => $custA->id,
            'license_plate' => 'LT 100 AA',
            'brand' => 'Toyota',
            'model' => 'Corolla',
        ]);

        $this->assertNotNull($custA->global_customer_id);
        $this->assertSame(1, GlobalCustomer::count());

        // Garage B utilise la recherche de pré-remplissage par téléphone
        $lookupRes = $this->actingAs($userB, 'web')
            ->getJson('http://beta.mboacar.com/clients/lookup-global?phone=699123456');

        $lookupRes->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('data.name', 'Dupont')
            ->assertJsonPath('data.id_number', 'CNI-GLOBAL-01')
            ->assertJsonMissing(['notes' => 'Note privée Garage A']);

        // Client créé au Garage B avec le même WhatsApp -> relié au même GlobalCustomer
        $custB = Customer::create([
            'tenant_id' => $tenantB->id,
            'agency_id' => $agencyB->id,
            'name' => 'Dupont',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'phone' => '+237699123456',
            'whatsapp_phone' => '+237699123456',
        ]);

        $this->assertSame($custA->global_customer_id, $custB->global_customer_id);
        $this->assertSame(1, GlobalCustomer::count());

        // Connexion au Portail Client Unique (/mon-espace-client)
        $this->get('/mon-espace-client')->assertOk();

        $this->post('/mon-espace-client/otp', [
            'phone' => '+237699123456',
        ])->assertSessionHas('success');

        $global = GlobalCustomer::firstOrFail();
        $this->assertNotNull($global->otp_code);

        $this->post('/mon-espace-client/verifier', [
            'phone' => '+237699123456',
            'code' => $global->otp_code,
        ])->assertSessionHas('success');

        // Mise à jour du profil unique -> synchronisé dans les deux garages
        $this->put('/mon-espace-client/profil', [
            'name' => 'Dupont-Mbarga',
            'first_name' => 'Jean',
            'gender' => 'homme',
            'id_number' => 'CNI-GLOBAL-01',
            'profession' => 'Architecte',
        ])->assertSessionHas('success');

        $this->assertSame('Dupont-Mbarga', $custA->fresh()->name);
        $this->assertSame('Dupont-Mbarga', $custB->fresh()->name);
        $this->assertSame('Architecte', $custB->fresh()->profession);
    }
}
