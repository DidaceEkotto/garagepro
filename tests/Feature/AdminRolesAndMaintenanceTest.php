<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlatformDashboard;
use App\Models\Admin;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRolesAndMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_roles_restrict_access_to_dedicated_pages(): void
    {
        $marketing = Admin::create([
            'name' => 'Marketing Admin',
            'email' => 'marketing@admin.test',
            'role' => Admin::ROLE_MARKETING,
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        $this->actingAs($marketing, 'admin')->get('/admin/categories')->assertOk();
        $this->actingAs($marketing, 'admin')->get('/admin/bannieres')->assertOk();
        $this->actingAs($marketing, 'admin')->get('/admin/temoignages')->assertOk();
        $this->actingAs($marketing, 'admin')->get('/admin/pays')->assertOk();

        // Accès refusé aux pages réservées au Super-Admin
        $this->actingAs($marketing, 'admin')->get('/admin/administrateurs')->assertStatus(403);
        $this->actingAs($marketing, 'admin')->get('/admin/parametres')->assertStatus(403);
    }

    public function test_maintenance_mode_blocks_public_site_but_keeps_admin_accessible(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Root Admin',
            'email' => 'root@admin.test',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        PlatformSetting::setValue('maintenance_mode', '1');
        PlatformSetting::setValue('maintenance_message', 'Maintenance urgente en cours.');

        $this->get('/')->assertStatus(503)->assertSee('Maintenance urgente en cours.');
        $this->get('/marketplace')->assertStatus(503);

        // L'espace Admin reste accessible
        $this->get('/admin/login')->assertOk();
        $this->actingAs($superAdmin, 'admin')->get('/admin/parametres')->assertOk();

        // Désactivation du mode maintenance via Livewire sans erreur
        $this->actingAs($superAdmin, 'admin');
        Livewire::test(PlatformDashboard::class, ['section' => 'settings'])
            ->set('maintenanceMode', false)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertFalse(PlatformSetting::isMaintenanceMode());
        $this->get('/')->assertOk();
    }

    public function test_admin_profile_and_creation_with_photo_in_storage_images(): void
    {
        Storage::fake('public');

        $superAdmin = Admin::create([
            'name' => 'Root Admin',
            'email' => 'root@admin.test',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin, 'admin');

        Livewire::test(PlatformDashboard::class, ['section' => 'profile'])
            ->set('profileGender', 'homme')
            ->set('profileJobTitle', 'Directeur Technique')
            ->set('profilePhone', '+237 699000000')
            ->set('profileBirthDate', '1990-05-15')
            ->set('profileBio', 'Admin principal GaragePro')
            ->set('profileAvatarFile', UploadedFile::fake()->image('avatar.png'))
            ->call('saveAdminProfile')
            ->assertHasNoErrors();

        $superAdmin->refresh();
        $this->assertSame('homme', $superAdmin->gender);
        $this->assertSame('Directeur Technique', $superAdmin->job_title);
        $this->assertNotNull($superAdmin->avatar_path);
        $this->assertStringStartsWith('images/admins/', $superAdmin->avatar_path);
        Storage::disk('public')->assertExists($superAdmin->avatar_path);
    }

    public function test_admin_can_manage_terms_and_privacy_and_public_pages_render(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Root Admin',
            'email' => 'root2@admin.test',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'password' => 'Secret@123',
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin, 'admin');
        $this->get('/admin/conditions-utilisation')->assertOk();
        $this->get('/admin/politique-confidentialite')->assertOk();

        Livewire::test(PlatformDashboard::class, ['section' => 'terms'])
            ->set('termsContent', '<h2>CGU GaragePro</h2><table><tr><td>Règle 1</td></tr></table>')
            ->call('saveTerms')
            ->assertHasNoErrors();

        Livewire::test(PlatformDashboard::class, ['section' => 'privacy'])
            ->set('privacyContent', '<h2>Confidentialité</h2><blockquote>Données chiffrées</blockquote>')
            ->call('savePrivacy')
            ->assertHasNoErrors();

        $this->get('/conditions-utilisation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Legal/Show')
                ->where('content', '<h2>CGU GaragePro</h2><table><tr><td>Règle 1</td></tr></table>')
            );
        $this->get('/politique-confidentialite')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Legal/Show')
                ->where('content', '<h2>Confidentialité</h2><blockquote>Données chiffrées</blockquote>')
            );
    }
}
