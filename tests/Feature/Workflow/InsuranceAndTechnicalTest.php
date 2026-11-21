<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\InsuranceClaim;
use App\Models\Tenant;
use App\Models\TechnicalInspection;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InsuranceAndTechnicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_inspection_pv_and_insurance_policies_and_claims_work(): void
    {
        config([
            'services.green_api.url' => 'https://api.green-api.com',
            'services.green_api.id_instance' => '123456',
            'services.green_api.api_token_instance' => 'token-secret',
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
    }
}
