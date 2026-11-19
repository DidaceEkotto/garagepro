<?php

namespace Tests\Feature\Tenancy;

use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AgencyPolicy;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant_global_scope_isolates_agencies_and_users(): void
    {
        $tenantA = Tenant::create(['name' => 'Garage Alpha', 'slug' => 'alpha', 'email' => 'a@mboacar.com']);
        $tenantB = Tenant::create(['name' => 'Garage Beta', 'slug' => 'beta', 'email' => 'b@mboacar.com']);

        $agencyA = Agency::create(['tenant_id' => $tenantA->id, 'name' => 'Agence Alpha', 'is_main' => true]);
        $agencyB = Agency::create(['tenant_id' => $tenantB->id, 'name' => 'Agence Beta', 'is_main' => true]);

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'default_agency_id' => $agencyA->id,
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => 'Secret123!',
        ]);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'default_agency_id' => $agencyB->id,
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => 'Secret123!',
        ]);

        app(TenantContext::class)->setTenant($tenantA);

        $this->assertCount(1, Agency::all());
        $this->assertSame($agencyA->id, Agency::first()->id);
        $this->assertNull(Agency::find($agencyB->id));

        $this->assertCount(1, User::all());
        $this->assertSame($userA->id, User::first()->id);
        $this->assertNull(User::find($userB->id));
    }

    public function test_user_cannot_access_another_tenant_agency_via_policy(): void
    {
        $tenantA = Tenant::create(['name' => 'Garage Alpha', 'slug' => 'alpha', 'email' => 'a@mboacar.com']);
        $tenantB = Tenant::create(['name' => 'Garage Beta', 'slug' => 'beta', 'email' => 'b@mboacar.com']);

        $agencyA = Agency::create(['tenant_id' => $tenantA->id, 'name' => 'Agence Alpha', 'is_main' => true]);
        $agencyB = Agency::create(['tenant_id' => $tenantB->id, 'name' => 'Agence Beta', 'is_main' => true]);

        $roleService = app(TenantRoleService::class);
        $roleService->provisionForTenant($tenantA);

        app(TenantContext::class)->setTenant($tenantA);
        $gerantA = User::create([
            'tenant_id' => $tenantA->id,
            'default_agency_id' => $agencyA->id,
            'name' => 'Gérant A',
            'email' => 'geranta@example.com',
            'password' => 'Secret123!',
        ]);
        $gerantA->assignRole('gerant');

        $policy = new AgencyPolicy();

        $this->assertTrue($policy->view($gerantA, $agencyA));
        $this->assertFalse($policy->view($gerantA, $agencyB));
        $this->assertFalse($policy->update($gerantA, $agencyB));
        $this->assertFalse($policy->delete($gerantA, $agencyB));
    }

    public function test_authenticated_user_of_tenant_a_is_blocked_on_tenant_b_subdomain(): void
    {
        $tenantA = Tenant::create(['name' => 'Garage Alpha', 'slug' => 'alpha', 'email' => 'a@mboacar.com']);
        $tenantB = Tenant::create(['name' => 'Garage Beta', 'slug' => 'beta', 'email' => 'b@mboacar.com']);

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => 'Secret123!',
        ]);

        $response = $this->actingAs($userA, 'web')
            ->get('http://beta.mboacar.com/tableau-de-bord');

        $response->assertStatus(403);
        $this->assertGuest('web');
    }
}
