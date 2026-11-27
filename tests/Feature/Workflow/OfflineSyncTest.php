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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithOwner(string $slug, string $type): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => $type,
            'email' => "{$slug}@mboacar.test",
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => "Agence {$slug}",
            'is_main' => true,
        ]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => "Owner {$slug}",
            'email' => "owner@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $owner->assignRole('gerant');

        return [$tenant, $agency, $owner];
    }

    public function test_ping_and_bootstrap_are_tenant_isolated(): void
    {
        [$tenantA, $agencyA, $ownerA] = $this->createTenantWithOwner('alpha-off', Tenant::TYPE_GARAGE);
        [$tenantB, $agencyB] = $this->createTenantWithOwner('beta-off', Tenant::TYPE_GARAGE);

        Customer::create([
            'tenant_id' => $tenantA->id,
            'agency_id' => $agencyA->id,
            'name' => 'Client Alpha',
            'gender' => 'homme',
            'phone' => '+237690000011',
        ]);
        Customer::create([
            'tenant_id' => $tenantB->id,
            'agency_id' => $agencyB->id,
            'name' => 'Client Beta Secret',
            'gender' => 'homme',
            'phone' => '+237690000022',
        ]);

        $this->actingAs($ownerA, 'web')
            ->getJson('http://alpha-off.mboacar.com/offline/ping')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tenant_id', $tenantA->id)
            ->assertJsonStructure(['csrf_token', 'server_time']);

        $bootstrap = $this->actingAs($ownerA, 'web')
            ->getJson('http://alpha-off.mboacar.com/offline/bootstrap')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Client Alpha');

        $this->assertStringNotContainsString('Client Beta Secret', $bootstrap->getContent());
    }

    public function test_offline_sync_creates_idempotently_and_handles_last_write_wins_conflicts(): void
    {
        [$tenant, , $owner] = $this->createTenantWithOwner('visite-off', Tenant::TYPE_TECHNICAL_CENTER);

        // 1. Création client hors ligne (idempotente)
        $custPayload = [
            'client_uuid' => 'uuid-cust-001',
            'entity' => 'customer',
            'action' => 'create',
            'payload' => [
                'name' => 'Nkeng',
                'first_name' => 'Samuel',
                'gender' => 'homme',
                'phone_dial_code' => '+237',
                'phone' => '+237691234567',
                'type' => 'particulier',
            ],
        ];

        $res1 = $this->actingAs($owner, 'web')
            ->postJson('http://visite-off.mboacar.com/offline/sync', $custPayload)
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $customerId = $res1->json('id');

        // Rejouer exactement le même client_uuid -> aucun doublon !
        $this->actingAs($owner, 'web')
            ->postJson('http://visite-off.mboacar.com/offline/sync', $custPayload)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('id', $customerId);

        $this->assertSame(1, Customer::where('tenant_id', $tenant->id)->count());

        // 2. Création véhicule + PV Visite Technique
        $vehRes = $this->actingAs($owner, 'web')
            ->postJson('http://visite-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-veh-001',
                'entity' => 'vehicle',
                'action' => 'create',
                'payload' => [
                    'customer_id' => $customerId,
                    'license_plate' => 'lt 888 zz',
                    'brand' => 'Toyota',
                    'model' => 'RAV4',
                    'mileage' => 84500,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('label', 'LT 888 ZZ');

        $vehicleId = $vehRes->json('id');

        $pvRes = $this->actingAs($owner, 'web')
            ->postJson('http://visite-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-pv-001',
                'entity' => 'technical_inspection',
                'action' => 'create',
                'payload' => [
                    'vehicle_id' => $vehicleId,
                    'inspection_line' => 'Ligne 2',
                    'mileage' => 85000,
                    'verdict' => TechnicalInspection::VERDICT_FAVORABLE,
                    'expires_at' => now()->addYear()->toDateString(),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, TechnicalInspection::where('tenant_id', $tenant->id)->count());
        $this->assertNotNull(Vehicle::find($vehicleId)->technical_inspection_expires_at);

        // 3. Conflit Last-Write-Wins : simuler une modification serveur plus récente que base_updated_at
        $staleBase = now()->subMinutes(10)->toIso8601String();
        $conflictRes = $this->actingAs($owner, 'web')
            ->postJson('http://visite-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-cust-upd-001',
                'entity' => 'customer',
                'action' => 'update',
                'record_id' => $customerId,
                'base_updated_at' => $staleBase,
                'payload' => [
                    'name' => 'Nkeng-Updated',
                    'gender' => 'homme',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('conflict', true);

        $this->assertNotEmpty($conflictRes->json('conflict_message'));
        $this->assertSame('Nkeng-Updated', Customer::find($customerId)->name);
        $this->assertNotNull($pvRes->json('id'));
    }

    public function test_offline_sync_handles_insurance_policy_and_claim_with_photo_blob(): void
    {
        Storage::fake('public');

        [$tenant, $agency, $owner] = $this->createTenantWithOwner('assur-off', Tenant::TYPE_INSURANCE);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Bello',
            'gender' => 'homme',
            'phone' => '+237699000111',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE 450 BB',
            'brand' => 'Peugeot',
            'model' => '3008',
        ]);

        // Police d'assurance hors ligne
        $polRes = $this->actingAs($owner, 'web')
            ->postJson('http://assur-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-pol-001',
                'entity' => 'insurance_policy',
                'action' => 'create',
                'payload' => [
                    'customer_id' => $customer->id,
                    'vehicle_id' => $vehicle->id,
                    'policy_number' => 'POL-OFF-2026',
                    'insurer_name' => 'AXA Cameroun',
                    'coverage_type' => 'tous_risques',
                    'premium_amount' => 185000,
                    'starts_at' => now()->toDateString(),
                    'expires_at' => now()->addYear()->toDateString(),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('label', 'POL-OFF-2026');

        $policyId = $polRes->json('id');
        $this->assertSame(1, InsurancePolicy::where('tenant_id', $tenant->id)->count());

        // Sinistre hors ligne avec photo envoyée en multipart/form-data
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $photo = UploadedFile::fake()->createWithContent('degat.png', $pngBytes);

        $claimRes = $this->actingAs($owner, 'web')
            ->post('http://assur-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-claim-001',
                'entity' => 'insurance_claim',
                'action' => 'create',
                'payload' => json_encode([
                    'insurance_policy_id' => $policyId,
                    'customer_id' => $customer->id,
                    'vehicle_id' => $vehicle->id,
                    'incident_date' => now()->toDateString(),
                    'description' => 'Choc arrière déclaré hors ligne',
                    'estimated_amount' => 250000,
                ]),
                'photos' => [$photo],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('photos_count', 1);

        $claim = InsuranceClaim::findOrFail($claimRes->json('id'));
        $this->assertCount(1, $claim->photos);
        Storage::disk('public')->assertExists($claim->photos[0]);

        // Un tenant assurance ne peut pas synchroniser une Visite Technique (403)
        $this->actingAs($owner, 'web')
            ->postJson('http://assur-off.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-pv-forbidden',
                'entity' => 'technical_inspection',
                'action' => 'create',
                'payload' => [
                    'vehicle_id' => $vehicle->id,
                    'verdict' => TechnicalInspection::VERDICT_FAVORABLE,
                ],
            ])
            ->assertForbidden();
    }

    public function test_offline_lease_is_capped_by_subscription_and_universal_replay_is_idempotent(): void
    {
        [$tenant, , $owner] = $this->createTenantWithOwner('lease-off', Tenant::TYPE_GARAGE);

        // Abonnement expire dans 3 jours (+ 2j de grâce = 5j) -> le bail doit être plafonné à ~5j (et non 14j)
        $tenant->update(['subscription_ends_at' => now()->addDays(3)]);

        $ping = $this->actingAs($owner, 'web')
            ->getJson('http://lease-off.mboacar.com/offline/ping')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('offline_lease_days_max', 14)
            ->assertJsonPath('is_read_only', false);

        $leaseDate = \Carbon\Carbon::parse($ping->json('offline_lease_expires_at'));
        $this->assertTrue($leaseDate->lessThan(now()->addDays(7)), 'Le bail hors ligne doit être plafonné par la fin d\'abonnement');

        // Replay universel idempotent sur n'importe quelle route (X-Offline-Replay: 1 + X-Client-Uuid)
        $headers = [
            'X-Offline-Replay' => '1',
            'X-Client-Uuid' => 'universal-replay-uuid-001',
        ];
        $payload = [
            'name' => 'Replay Universel',
            'gender' => 'homme',
            'id_number' => 'CNI-REPLAY-001',
            'phone_dial_code' => '+237',
            'phone' => '699554433',
            'type' => 'particulier',
        ];

        $this->actingAs($owner, 'web')
            ->withHeaders($headers)
            ->post('http://lease-off.mboacar.com/clients', $payload)
            ->assertRedirect();

        $this->assertSame(1, Customer::where('tenant_id', $tenant->id)->where('name', 'Replay Universel')->count());

        // 2e replay du même client_uuid -> intercepté par HandleOfflineReplayIdempotency, aucun doublon !
        $this->actingAs($owner, 'web')
            ->withHeaders($headers)
            ->postJson('http://lease-off.mboacar.com/clients', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Customer::where('tenant_id', $tenant->id)->where('name', 'Replay Universel')->count());
    }
}
