<?php

namespace Tests\Feature\Tenant;

use App\Models\Agency;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerant_can_create_agency_and_switch_active_agency(): void
    {
        $tenant = Tenant::create([
            'name' => 'Garage Multi',
            'slug' => 'multi',
            'email' => 'multi@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(7),
        ]);
        $agency1 = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Douala', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency1->id,
            'name' => 'Gérant Multi',
            'email' => 'gerant@multi.com',
            'password' => 'Secret123!',
        ]);
        $gerant->assignRole('gerant');

        $this->actingAs($gerant, 'web')
            ->post('http://multi.mboacar.com/agences', [
                'name' => 'Yaoundé Bastos',
                'code' => 'YDE01',
                'city' => 'Yaoundé',
            ])
            ->assertSessionHas('success');

        $agency2 = Agency::withoutTenantScope()->where('tenant_id', $tenant->id)->where('code', 'YDE01')->first();
        $this->assertNotNull($agency2);

        $this->actingAs($gerant, 'web')
            ->post("http://multi.mboacar.com/agences/{$agency2->id}/activer")
            ->assertSessionHas('active_agency_id', $agency2->id);
    }
}
