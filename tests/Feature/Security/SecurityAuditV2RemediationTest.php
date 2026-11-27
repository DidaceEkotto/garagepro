<?php

namespace Tests\Feature\Security;

use App\Mail\CustomerProfileUpdatedMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\GlobalCustomer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAuditV2RemediationTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithGerant(string $slug, string $type): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => $type,
            'email' => "{$slug}@sec.test",
            'phone' => '+237690000888',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => "Gerant {$slug}",
            'email' => "gerant@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_public_quote_rejects_non_png_signature_payloads_and_uses_random_filename(): void
    {
        Storage::fake('public');

        [$tenant, $agency] = $this->createTenantWithGerant('sig-sec', Tenant::TYPE_GARAGE);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Sig',
            'gender' => 'homme',
            'phone' => '+237699100200',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 900 SS',
            'brand' => 'Toyota',
            'model' => 'Yaris',
        ]);
        $quote = Quote::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'quote_number' => 'DEV-SEC-01',
            'version' => 1,
            'status' => Quote::STATUS_SENT,
            'valid_until' => now()->addDays(5),
            'vat_rate' => 0,
            'discount_amount' => 0,
        ]);
        QuoteItem::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'type' => 'main_oeuvre',
            'urgency' => 'urgent',
            'description' => 'Vidange',
            'quantity' => 1,
            'unit_price' => 15000,
            'total_price' => 15000,
            'is_accepted' => true,
        ]);

        // 1. Tentative d'injection HTML/SVG déguisée avec le préfixe data:image/png;base64,
        $maliciousSig = 'data:image/png;base64,'.base64_encode('<svg onload="alert(1)">not a png</svg>'.str_repeat('A', 200));
        $this->post("/devis-public/{$quote->public_token}/repondre", [
            'decision' => 'accept',
            'signature_data' => $maliciousSig,
        ])->assertSessionHasErrors('signature_data');

        $this->assertNull($quote->fresh()->client_signature_path);
        $this->assertSame(Quote::STATUS_SENT, $quote->fresh()->status);

        // 2. Vrai PNG valide accepté et stocké sous un nom aléatoire (non prévisible)
        $validPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $this->post("/devis-public/{$quote->public_token}/repondre", [
            'decision' => 'accept',
            'signature_data' => $validPng,
        ])->assertSessionHas('success');

        $savedPath = $quote->fresh()->client_signature_path;
        $this->assertNotNull($savedPath);
        $this->assertStringNotContainsString("quote-{$quote->id}", $savedPath);
        Storage::disk('public')->assertExists($savedPath);
    }

    public function test_public_quote_blocks_expired_quote_and_empty_item_acceptance(): void
    {
        [$tenant, $agency] = $this->createTenantWithGerant('exp-sec', Tenant::TYPE_GARAGE);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Exp',
            'gender' => 'homme',
            'phone' => '+237699100201',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 901 EE',
            'brand' => 'Honda',
            'model' => 'Civic',
        ]);

        // 1. Devis expiré -> refusé
        $expiredQuote = Quote::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'quote_number' => 'DEV-EXP-01',
            'version' => 1,
            'status' => Quote::STATUS_SENT,
            'valid_until' => now()->subDays(2),
            'vat_rate' => 0,
            'discount_amount' => 0,
        ]);

        $this->post("/devis-public/{$expiredQuote->public_token}/repondre", [
            'decision' => 'accept',
        ])->assertSessionHas('error');
        $this->assertSame(Quote::STATUS_SENT, $expiredQuote->fresh()->status);

        // 2. Devis sans ligne urgente + accepted_item_ids vide -> refusé (pas de validation à 0 FCFA)
        $optQuote = Quote::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'quote_number' => 'DEV-OPT-01',
            'version' => 1,
            'status' => Quote::STATUS_SENT,
            'valid_until' => now()->addDays(5),
            'vat_rate' => 0,
            'discount_amount' => 0,
        ]);
        QuoteItem::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $optQuote->id,
            'type' => 'piece',
            'urgency' => 'optionnel',
            'description' => 'Polissage',
            'quantity' => 1,
            'unit_price' => 25000,
            'total_price' => 25000,
            'is_accepted' => true,
        ]);

        $this->post("/devis-public/{$optQuote->public_token}/repondre", [
            'decision' => 'accept',
            'accepted_item_ids' => [],
        ])->assertSessionHasErrors('accepted_item_ids');
        $this->assertSame(Quote::STATUS_SENT, $optQuote->fresh()->status);
    }

    public function test_offline_sync_enforces_vehicle_customer_coherence_and_partner_tenant_type(): void
    {
        [$tenant, $agency, $owner] = $this->createTenantWithGerant('assur-sec', Tenant::TYPE_INSURANCE);
        [$visiteCenter] = $this->createTenantWithGerant('visite-sec', Tenant::TYPE_TECHNICAL_CENTER);

        $cust1 = Customer::create(['tenant_id' => $tenant->id, 'agency_id' => $agency->id, 'name' => 'C1', 'gender' => 'homme', 'phone' => '+237690111001']);
        $cust2 = Customer::create(['tenant_id' => $tenant->id, 'agency_id' => $agency->id, 'name' => 'C2', 'gender' => 'homme', 'phone' => '+237690111002']);
        $veh1 = Vehicle::create(['tenant_id' => $tenant->id, 'customer_id' => $cust1->id, 'license_plate' => 'CE 111 AA', 'brand' => 'BMW', 'model' => 'X3']);

        // Véhicule de C1 envoyé avec customer_id de C2 -> 422
        $this->actingAs($owner, 'web')
            ->postJson('http://assur-sec.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-pol-mismatch',
                'entity' => 'insurance_policy',
                'action' => 'create',
                'payload' => [
                    'customer_id' => $cust2->id,
                    'vehicle_id' => $veh1->id,
                    'policy_number' => 'POL-BAD',
                    'insurer_name' => 'Test',
                    'coverage_type' => 'tous_risques',
                    'starts_at' => now()->toDateString(),
                    'expires_at' => now()->addYear()->toDateString(),
                ],
            ])
            ->assertStatus(422);

        // garage_tenant_id pointant vers un centre de visite technique au lieu d'un garage -> 422
        $this->actingAs($owner, 'web')
            ->postJson('http://assur-sec.mboacar.com/offline/sync', [
                'client_uuid' => 'uuid-claim-bad-tenant',
                'entity' => 'insurance_claim',
                'action' => 'create',
                'payload' => [
                    'customer_id' => $cust1->id,
                    'vehicle_id' => $veh1->id,
                    'garage_tenant_id' => $visiteCenter->id,
                    'incident_date' => now()->toDateString(),
                    'description' => 'Test injection',
                ],
            ])
            ->assertStatus(422);
    }

    public function test_customer_portal_profile_update_prevents_notification_spam(): void
    {
        Mail::fake();
        Http::fake();

        [$tenant, $agency] = $this->createTenantWithGerant('spam-sec', Tenant::TYPE_GARAGE);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'NoSpam',
            'first_name' => 'Jean',
            'gender' => 'homme',
            'phone' => '+237699334455',
            'whatsapp_phone' => '+237699334455',
        ]);
        $global = GlobalCustomer::findOrFail($customer->global_customer_id);

        // 1. Soumission identique sans aucun changement -> 0 e-mail envoyé
        $this->withSession(['global_customer_id' => $global->id])
            ->put('/mon-espace-client/profil', [
                'name' => 'NoSpam',
                'first_name' => 'Jean',
                'gender' => 'homme',
            ])
            ->assertSessionHas('success');
        Mail::assertNothingSent();

        // 2. Vraie modification -> 1 e-mail envoyé
        $this->withSession(['global_customer_id' => $global->id])
            ->put('/mon-espace-client/profil', [
                'name' => 'NoSpam-Modifie',
                'first_name' => 'Jean',
                'gender' => 'homme',
            ])
            ->assertSessionHas('success');
        Mail::assertSent(CustomerProfileUpdatedMail::class, 1);

        // 3. Modification immédiate suivante (< 5 min) -> bloquée par le cooldown anti-spam
        $this->withSession(['global_customer_id' => $global->id])
            ->put('/mon-espace-client/profil', [
                'name' => 'NoSpam-Modifie-2',
                'first_name' => 'Jean',
                'gender' => 'homme',
            ])
            ->assertSessionHas('success');
        Mail::assertSent(CustomerProfileUpdatedMail::class, 1);
    }
}
