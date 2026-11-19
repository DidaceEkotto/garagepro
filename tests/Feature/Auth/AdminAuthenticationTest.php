<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_guard_is_isolated_from_web_guard(): void
    {
        $tenant = Tenant::create(['name' => 'Garage Test', 'slug' => 'test-garage', 'email' => 't@mboacar.com']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant User',
            'email' => 'user@test.cm',
            'password' => 'Password123!',
        ]);

        // Un utilisateur tenant ne peut pas accéder au back-office /admin.
        $this->actingAs($user, 'web')
            ->get('/admin')
            ->assertRedirect(route('admin.login'));

        auth('web')->logout();

        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'super@mboacar.com',
            'password' => 'AdminPass123!',
            'is_active' => true,
        ]);

        // Connexion admin sur /admin/login
        $this->post('/admin/login', [
            'email' => 'super@mboacar.com',
            'password' => 'AdminPass123!',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');

        $this->get('/admin')->assertStatus(200);
    }

    public function test_admin_seeder_fails_when_env_values_are_missing(): void
    {
        config(['admin.email' => null, 'admin.password' => null, 'admin.name' => null]);

        $this->expectException(\RuntimeException::class);
        $this->seed(AdminSeeder::class);
    }

    public function test_admin_seeder_creates_admin_from_config(): void
    {
        config([
            'admin.email' => 'root@mboacar.com',
            'admin.password' => 'SuperSecret123!',
            'admin.name' => 'Admin Principal',
        ]);

        $this->seed(AdminSeeder::class);

        $this->assertDatabaseHas('admins', [
            'email' => 'root@mboacar.com',
            'name' => 'Admin Principal',
        ]);
    }
}
