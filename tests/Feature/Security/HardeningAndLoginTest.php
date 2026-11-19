<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\BlockedIp;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HardeningAndLoginTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantAndUsers(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Secu',
            'slug' => 'garage-sec',
            'email' => 'gerant@secu.cm',
            'country' => 'CM',
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'code' => 'AG01',
            'is_main' => true,
            'is_active' => true,
        ]);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant Sécu',
            'email' => 'gerant@secu.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $gerant->assignRole('gerant');

        $mechanic = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Mécanicien Sécu',
            'email' => 'meca@secu.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $mechanic->assignRole('mecanicien');

        return [$tenant, $agency, $gerant, $mechanic];
    }

    public function test_unauthenticated_users_are_redirected_from_protected_resources(): void
    {
        $this->createTenantAndUsers();

        foreach (['/tableau-de-bord', '/clients', '/factures', '/comptabilite', '/rh', '/parametres-espace'] as $path) {
            $this->get("http://garage-sec.mboacar.com{$path}")
                ->assertRedirect();
        }
    }

    public function test_valid_credentials_login_succeeds_on_local_ip_and_subdomain(): void
    {
        [$tenant, $agency, $gerant] = $this->createTenantAndUsers();

        $res = $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
            ->post('http://127.0.0.1:5675/connexion', [
                'email' => 'gerant@secu.cm',
                'password' => 'MotDePasse@123',
            ]);

        $res->assertRedirect('http://127.0.0.1:5675/tableau-de-bord');
        $this->assertAuthenticatedAs($gerant, 'web');

        $this->get('http://127.0.0.1:5675/tableau-de-bord')->assertOk();
    }

    public function test_five_failed_logins_lock_ip_for_ten_minutes_with_countdown(): void
    {
        $this->createTenantAndUsers();
        $ip = '10.10.0.99';
        Cache::forget("login_fail_ip:{$ip}");
        Cache::forget("login_block_ip:{$ip}");

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('http://garage-sec.mboacar.com/connexion', [
                    'email' => 'gerant@secu.cm',
                    'password' => 'WrongPassword!',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('http://garage-sec.mboacar.com/connexion')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
                ->where('blockedUntilTimestamp', fn ($ts) => is_int($ts) && $ts > now()->timestamp)
            );
    }

    public function test_sqli_xss_and_ddos_burst_auto_block_ip_for_twenty_minutes(): void
    {
        $this->createTenantAndUsers();

        $sqliIp = '10.20.0.1';
        $this->withServerVariables(['REMOTE_ADDR' => $sqliIp])
            ->get('http://garage-sec.mboacar.com/?q=1+UNION+SELECT+password+FROM+users')
            ->assertStatus(403);

        $this->assertTrue(BlockedIp::isCurrentlyBlocked($sqliIp));

        // Après 21 minutes, l'IP est automatiquement débloquée
        $this->travel(21)->minutes();
        $this->assertFalse(BlockedIp::isCurrentlyBlocked($sqliIp));

        // Burst DDoS
        $ddosIp = '10.20.0.2';
        config(['garagepro.security.ddos_max_requests_per_5s' => 5]);
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ddosIp])->get('http://garage-sec.mboacar.com/')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => $ddosIp])->get('http://garage-sec.mboacar.com/')->assertStatus(403);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked($ddosIp));
    }

    public function test_role_permission_middleware_blocks_unauthorized_resources(): void
    {
        [$tenant, $agency, $gerant, $mechanic] = $this->createTenantAndUsers();

        $this->actingAs($mechanic, 'web')
            ->get('http://garage-sec.mboacar.com/comptabilite')
            ->assertStatus(403);

        $this->actingAs($mechanic, 'web')
            ->get('http://garage-sec.mboacar.com/rh')
            ->assertStatus(403);

        $this->actingAs($mechanic, 'web')
            ->get('http://garage-sec.mboacar.com/parametres-espace')
            ->assertStatus(403);

        $this->actingAs($mechanic, 'web')
            ->get('http://garage-sec.mboacar.com/atelier/mes-or')
            ->assertOk();
    }
}
