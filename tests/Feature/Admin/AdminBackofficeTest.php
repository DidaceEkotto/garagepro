<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\PlatformDashboard;
use App\Models\Admin;
use App\Models\BlockedIp;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBackofficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_tenants_settings_and_blocked_ips_via_livewire(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@mboacar.com',
            'password' => 'AdminPass123!',
        ]);

        $tenant = Tenant::create([
            'name' => 'Garage Test',
            'slug' => 'test-livewire',
            'email' => 'tl@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($admin, 'admin');

        Livewire::test(PlatformDashboard::class)
            ->set('subscriptionPrice', 30000)
            ->call('saveSettings')
            ->call('extendTrial', $tenant->id, 7)
            ->call('suspendTenant', $tenant->id)
            ->set('newIp', '198.51.100.55')
            ->set('newIpReason', 'Abus manuel')
            ->call('blockIpManual');

        $this->assertSame(30000, PlatformSetting::subscriptionPrice());
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->fresh()->status);
        $this->assertTrue(BlockedIp::isCurrentlyBlocked('198.51.100.55'));

        // Témoignage géré côté admin et affiché sur la landing page
        Livewire::test(PlatformDashboard::class)
            ->set('testiAuthor', 'Jean-Luc T.')
            ->set('testiRole', 'Directeur')
            ->set('testiGarage', 'Auto Pro Yaoundé')
            ->set('testiCity', 'Yaoundé')
            ->set('testiQuote', 'GaragePro a transformé notre suivi atelier et comptable.')
            ->call('addTestimonial');

        $this->get('/')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Welcome')
                ->has('testimonials', 5)
                ->where('testimonials.0.garage_name', 'Auto Pro Yaoundé')
            );
    }
}
