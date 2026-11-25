<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\TechnicalInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InsuranceAndTechnicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_inspection_pv_and_insurance_policies_and_claims_work(): void
    {
        Storage::fake('local');
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        Http::fake([
            '*' => Http::response(['idMessage' => 'msg-ok'], 200),
        ]);

        $tenant = Tenant::create([
            'name' => 'Centre & Assurance Mboa',
            'slug' => 'centremboa',
            'type' => Tenant::TYPE_TECHNICAL_CENTER,
            'email' => 'centre@example.com',
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Contrôleur Technique',
            'email' => 'ctrl@centremboa.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Mbarga Paul',
            'gender' => 'homme',
            'id_number' => 'CNI-VT-01',
            'phone' => '+237699001122',
            'whatsapp_phone' => '+237699001122',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE 456 XY',
            'brand' => 'Peugeot',
            'model' => '308',
        ]);

        // 1. Visite Technique (PV + QR public + WhatsApp)
        $this->actingAs($user, 'web')->get('http://centremboa.mboacar.com/visite-technique')->assertOk();

        $expiresDate = now()->addYear()->toDateString();
        $this->actingAs($user, 'web')->post('http://centremboa.mboacar.com/visite-technique', [
            'vehicle_id' => $vehicle->id,
            'inspection_line' => 'Ligne 1',
            'mileage' => 85000,
            'verdict' => 'favorable',
            'expires_at' => $expiresDate,
            'defects' => [
                ['category' => 'Freinage', 'item' => 'Frein de service', 'severity' => 'ok', 'note' => ''],
                ['category' => 'Éclairage', 'item' => 'Feux stop', 'severity' => 'mineur', 'note' => 'Ampoule faible'],
            ],
        ])->assertSessionHas('success');

        $pv = TechnicalInspection::withoutTenantScope()->firstOrFail();
        $this->assertSame($expiresDate, $vehicle->fresh()->technical_inspection_expires_at->toDateString());

        // Page publique PV
        $this->get("/pv-visite/{$pv->public_token}")->assertOk();

        // Envoi WhatsApp
        $this->actingAs($user, 'web')
            ->post("http://centremboa.mboacar.com/visite-technique/{$pv->id}/whatsapp")
            ->assertSessionHas('success');
        $this->assertNotNull($pv->fresh()->whatsapp_sent_at);

        // 2. Assurances : Police + Sinistre + Accord Prise en charge (sur un tenant Assurance)
        $tenant->update(['type' => Tenant::TYPE_INSURANCE]);
        $this->actingAs($user, 'web')->get('http://centremboa.mboacar.com/assurances')->assertOk();

        $policyExpires = now()->addMonths(6)->toDateString();
        $this->actingAs($user, 'web')->post('http://centremboa.mboacar.com/assurances/polices', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'policy_number' => 'POL-2026-889',
            'insurer_name' => 'Activa Assurances',
            'coverage_type' => 'tous_risques',
            'premium_amount' => 180000,
            'deductible_amount' => 50000,
            'starts_at' => now()->toDateString(),
            'expires_at' => $policyExpires,
        ])->assertSessionHas('success');

        $this->assertSame($policyExpires, $vehicle->fresh()->insurance_expires_at->toDateString());

        $this->actingAs($user, 'web')->post('http://centremboa.mboacar.com/assurances/sinistres', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'incident_date' => now()->toDateString(),
            'description' => 'Choc pare-chocs arrière',
            'estimated_amount' => 500000,
        ])->assertSessionHas('success');

        $claim = InsuranceClaim::withoutTenantScope()->firstOrFail();

        $this->actingAs($user, 'web')->post("http://centremboa.mboacar.com/assurances/sinistres/{$claim->id}/approuver", [
            'approved_amount' => 500000,
            'insurance_share_percent' => 80,
            'expert_name' => 'Expert Talla',
        ])->assertSessionHas('success');

        $claim->refresh();
        $this->assertSame('accord_prise_en_charge', $claim->status);
        $this->assertSame(100000, $claim->client_deductible_amount);

        // 3. Vérification PDF police, PDF sinistre, édition police
        $policy = InsurancePolicy::withoutTenantScope()->firstOrFail();
        $this->actingAs($user, 'web')
            ->get("http://centremboa.mboacar.com/assurances/polices/{$policy->id}/pdf")
            ->assertOk();
        $this->actingAs($user, 'web')
            ->get("http://centremboa.mboacar.com/assurances/sinistres/{$claim->id}/pdf")
            ->assertOk();

        $this->actingAs($user, 'web')
            ->put("http://centremboa.mboacar.com/assurances/polices/{$policy->id}", [
                'policy_number' => 'POL-2026-889',
                'insurer_name' => 'Activa Assurances',
                'coverage_type' => 'tous_risques',
                'premium_amount' => 195000,
                'deductible_amount' => 45000,
                'starts_at' => now()->toDateString(),
                'expires_at' => now()->addYear()->toDateString(),
                'status' => 'active',
            ])->assertSessionHas('success');
        $this->assertSame(195000, $policy->fresh()->premium_amount);
    }

    public function test_verdict_coherence_cross_tenant_claim_security_and_permissions(): void
    {
        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);
        Http::fake(['*' => Http::response(['idMessage' => 'msg-ok'], 200)]);

        // Centre technique : rejet verdict favorable avec défaut critique + PDF PV + contre-visite liée
        $ctTenant = Tenant::create([
            'name' => 'CT Sécurité',
            'slug' => 'ct-secu',
            'type' => Tenant::TYPE_TECHNICAL_CENTER,
            'email' => 'ct@secu.test',
        ]);
        $ctAgency = Agency::create(['tenant_id' => $ctTenant->id, 'name' => 'CT1', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($ctTenant);
        setPermissionsTeamId($ctTenant->id);

        $ctGerant = User::create([
            'tenant_id' => $ctTenant->id,
            'default_agency_id' => $ctAgency->id,
            'name' => 'Gérant CT',
            'email' => 'gerant@ctsecu.test',
            'password' => 'Secret123!',
        ]);
        $ctGerant->assignRole('gerant');

        $ctMechanic = User::create([
            'tenant_id' => $ctTenant->id,
            'default_agency_id' => $ctAgency->id,
            'name' => 'Mécanicien CT',
            'email' => 'meca@ctsecu.test',
            'password' => 'Secret123!',
        ]);
        $ctMechanic->assignRole('mecanicien');

        $customer = Customer::create([
            'tenant_id' => $ctTenant->id,
            'agency_id' => $ctAgency->id,
            'name' => 'Client CT',
            'gender' => 'homme',
            'id_number' => 'CNI-CT-2',
            'phone' => '+237699112233',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $ctTenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 888 AA',
            'brand' => 'Renault',
            'model' => 'Clio',
        ]);

        // Un mécanicien ne peut pas accéder à /visite-technique
        $this->actingAs($ctMechanic, 'web')
            ->get('http://ct-secu.mboacar.com/visite-technique')
            ->assertForbidden();

        // Verdict favorable refusé s'il y a un défaut critique
        $this->actingAs($ctGerant, 'web')
            ->post('http://ct-secu.mboacar.com/visite-technique', [
                'vehicle_id' => $vehicle->id,
                'verdict' => 'favorable',
                'defects' => [
                    ['category' => 'Freinage', 'item' => 'Frein de service', 'severity' => 'critique', 'note' => 'HS'],
                ],
            ])->assertSessionHasErrors('verdict');

        // Création PV contre-visite puis PV favorable lié en contre-visite
        $this->actingAs($ctGerant, 'web')
            ->post('http://ct-secu.mboacar.com/visite-technique', [
                'vehicle_id' => $vehicle->id,
                'verdict' => 'contre_visite',
                'defects' => [
                    ['category' => 'Freinage', 'item' => 'Frein de service', 'severity' => 'majeur', 'note' => 'Usé'],
                ],
            ])->assertSessionHas('success');

        $initialPv = TechnicalInspection::withoutTenantScope()->firstOrFail();

        $this->actingAs($ctGerant, 'web')
            ->post('http://ct-secu.mboacar.com/visite-technique', [
                'vehicle_id' => $vehicle->id,
                'parent_inspection_id' => $initialPv->id,
                'verdict' => 'favorable',
                'defects' => [
                    ['category' => 'Freinage', 'item' => 'Frein de service', 'severity' => 'ok', 'note' => 'Corrigé'],
                ],
            ])->assertSessionHas('success');

        $followUpPv = TechnicalInspection::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame($initialPv->id, $followUpPv->parent_inspection_id);

        // PDF PV authentifié et public
        $this->actingAs($ctGerant, 'web')
            ->get("http://ct-secu.mboacar.com/visite-technique/{$followUpPv->id}/pdf")
            ->assertOk();
        $this->get("/pv-visite/{$followUpPv->public_token}/pdf")->assertOk();

        // Demande de devis contre-visite bloquée sur un PV favorable
        $garageTenant = Tenant::create([
            'name' => 'Garage Partenaire Secu',
            'slug' => 'garage-secu',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'garage@secu.test',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $this->post("/pv-visite/{$followUpPv->public_token}/demander-devis", [
            'garage_tenant_id' => $garageTenant->id,
        ])->assertSessionHasErrors('garage_tenant_id');

        // Passerelle Garage -> Assurance : l'assureur voit bien le client/véhicule et le garage NE PEUT PAS auto-approuver
        $assurTenant = Tenant::create([
            'name' => 'Assurance Secu',
            'slug' => 'assur-secu',
            'type' => Tenant::TYPE_INSURANCE,
            'email' => 'assur@secu.test',
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $garageAgency = Agency::create(['tenant_id' => $garageTenant->id, 'name' => 'AG', 'is_main' => true]);
        $assurAgency = Agency::create(['tenant_id' => $assurTenant->id, 'name' => 'AA', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($garageTenant);
        app(TenantRoleService::class)->provisionForTenant($assurTenant);

        setPermissionsTeamId($garageTenant->id);
        $garageGerant = User::create([
            'tenant_id' => $garageTenant->id,
            'default_agency_id' => $garageAgency->id,
            'name' => 'Gérant Garage',
            'email' => 'g@garagesecu.test',
            'password' => 'Secret123!',
        ]);
        $garageGerant->assignRole('gerant');

        setPermissionsTeamId($assurTenant->id);
        $assurGerant = User::create([
            'tenant_id' => $assurTenant->id,
            'default_agency_id' => $assurAgency->id,
            'name' => 'Gérant Assurance',
            'email' => 'a@assursecu.test',
            'password' => 'Secret123!',
        ]);
        $assurGerant->assignRole('gerant');

        $gCustomer = Customer::create([
            'tenant_id' => $garageTenant->id,
            'agency_id' => $garageAgency->id,
            'name' => 'Client Sinistré Cross',
            'gender' => 'homme',
            'id_number' => 'CNI-CROSS-1',
            'phone' => '+237699445566',
            'whatsapp_phone' => '+237699445566',
        ]);
        $gVehicle = Vehicle::create([
            'tenant_id' => $garageTenant->id,
            'customer_id' => $gCustomer->id,
            'license_plate' => 'CE 999 XX',
            'brand' => 'Toyota',
            'model' => 'RAV4',
        ]);

        // Le garage déclare un sinistre avec photo vers l'assurance partenaire
        $this->actingAs($garageGerant, 'web')
            ->post('http://garage-secu.mboacar.com/assurances/sinistres', [
                'customer_id' => $gCustomer->id,
                'vehicle_id' => $gVehicle->id,
                'insurance_tenant_id' => $assurTenant->id,
                'incident_date' => now()->toDateString(),
                'description' => 'Portière enfoncée',
                'estimated_amount' => 400000,
                'photos' => [UploadedFile::fake()->image('degat.jpg', 300, 200)],
            ])->assertSessionHas('success');

        $claim = InsuranceClaim::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertNotEmpty($claim->photos);

        // Le garage NE PEUT PAS approuver son propre sinistre soumis à l'assurance externe
        $this->actingAs($garageGerant, 'web')
            ->post("http://garage-secu.mboacar.com/assurances/sinistres/{$claim->id}/approuver", [
                'approved_amount' => 400000,
                'insurance_share_percent' => 100,
            ])->assertForbidden();

        // L'assurance voit le client et le véhicule du garage (withoutTenantScope) et peut approuver
        $this->actingAs($assurGerant, 'web')
            ->get('http://assur-secu.mboacar.com/assurances')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('claims.0.customer.name', 'Client Sinistré Cross')
                ->where('claims.0.vehicle.license_plate', 'CE 999 XX')
                ->where('claims.0.can_approve', true)
            );

        $this->actingAs($assurGerant, 'web')
            ->post("http://assur-secu.mboacar.com/assurances/sinistres/{$claim->id}/approuver", [
                'approved_amount' => 350000,
                'insurance_share_percent' => 80,
                'expert_name' => 'Expert Cross',
            ])->assertSessionHas('success');

        // WhatsApp bien envoyé au client du garage grâce au withoutTenantScope sur customer/vehicle
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && str_contains($req['chatId'], '237699445566'));
    }
}
