<?php

namespace Tests\Feature\Auth;

use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_roles_are_provisioned_per_tenant_with_agency_access(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Bonanjo', 'slug' => 'bonanjo', 'email' => 'b@mboacar.com']);
        $agency1 = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Bonanjo', 'is_main' => true]);
        $agency2 = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Akwa', 'is_main' => false]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        app(TenantContext::class)->setTenant($tenant);

        $mecanicien = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency1->id,
            'name' => 'Moussa Mécanicien',
            'email' => 'moussa@bonanjo.cm',
            'password' => 'Secret123!',
        ]);
        $mecanicien->assignRole('mecanicien');
        $mecanicien->agencies()->attach($agency1->id, ['tenant_id' => $tenant->id]);

        $this->assertTrue($mecanicien->hasRole('mecanicien'));
        $this->assertTrue($mecanicien->can('repair_orders.view_own'));
        $this->assertFalse($mecanicien->can('accounting.manage'));
        $this->assertTrue($mecanicien->hasAccessToAgency($agency1->id));
        $this->assertFalse($mecanicien->hasAccessToAgency($agency2->id));

        $comptable = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency1->id,
            'name' => 'Clarisse Comptable',
            'email' => 'clarisse@bonanjo.cm',
            'password' => 'Secret123!',
        ]);
        $comptable->assignRole('comptable');

        $this->assertTrue($comptable->can('accounting.manage'));
        $this->assertTrue($comptable->can('invoices.manage'));
        $this->assertFalse($comptable->can('diagnostics.manage'));
    }
}
