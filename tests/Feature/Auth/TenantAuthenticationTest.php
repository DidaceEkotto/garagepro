<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TenantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_can_login_on_own_subdomain_and_logout(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Douala', 'slug' => 'douala', 'email' => 'contact@douala.cm']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jean Gérant',
            'email' => 'jean@douala.cm',
            'password' => 'MotDePasse123!',
            'is_active' => true,
        ]);

        $response = $this->post('http://douala.mboacar.com/connexion', [
            'email' => 'jean@douala.cm',
            'password' => 'MotDePasse123!',
        ]);

        $response->assertRedirect(route('tenant.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'auth.login_success',
            'tenant_id' => $tenant->id,
        ]);

        $logoutResponse = $this->post('http://douala.mboacar.com/deconnexion');
        $logoutResponse->assertRedirect(route('tenant.login'));
        $this->assertGuest('web');
    }

    public function test_account_is_locked_after_max_failed_attempts(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Yaoundé', 'slug' => 'yaounde', 'email' => 'contact@yde.cm']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Paul',
            'email' => 'paul@yde.cm',
            'password' => 'BonMotDePasse123!',
            'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('http://yaounde.mboacar.com/connexion', [
                'email' => 'paul@yde.cm',
                'password' => 'MauvaisPass!',
            ]);
        }

        $user->refresh();
        $this->assertTrue($user->isLocked());
        $this->assertSame(5, $user->failed_login_attempts);

        // On réinitialise le rate limiter HTTP pour vérifier le verrouillage applicatif en base.
        Cache::flush();

        // Même avec le bon mot de passe, le compte reste verrouillé.
        $response = $this->post('http://yaounde.mboacar.com/connexion', [
            'email' => 'paul@yde.cm',
            'password' => 'BonMotDePasse123!',
        ]);
        $response->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }
}
