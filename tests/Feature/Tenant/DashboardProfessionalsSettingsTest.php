<?php

namespace Tests\Feature\Tenant;

use App\Models\Agency;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardProfessionalsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerant_can_view_dashboard_add_professionals_and_update_space_settings_with_logo(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create([
            'name' => 'Garage Design',
            'slug' => 'design',
            'email' => 'd@example.com',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(7),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant',
            'email' => 'gerant@design.cm',
            'password' => 'Secret123!',
        ]);
        $gerant->assignRole('gerant');

        // Dashboard récapitulatif avec KPIs et séries graphiques
        $this->actingAs($gerant, 'web')
            ->get('http://design.mboacar.com/tableau-de-bord')
            ->assertStatus(200);

        // Ajout d'un professionnel (mécanicien) avec fiche RH complète et photo facultative
        $photo = UploadedFile::fake()->image('meca.jpg', 150, 150);
        $this->actingAs($gerant, 'web')
            ->post('http://design.mboacar.com/professionnels', [
                'name' => 'Paul Mécanicien',
                'email' => 'paul@design.cm',
                'phone' => '699112233',
                'role' => 'mecanicien',
                'agency_id' => $agency->id,
                'gender' => 'M',
                'marital_status' => 'marie',
                'birth_date' => '1992-05-14',
                'birth_place' => 'Douala',
                'base_salary' => 180000,
                'commission_rate' => 5.0,
                'photo' => $photo,
                'password' => 'Secret123!',
            ])
            ->assertSessionHas('success');

        $meca = User::withoutTenantScope()->where('email', 'paul@design.cm')->firstOrFail();
        $this->assertTrue($meca->hasRole('mecanicien'));
        $this->assertDatabaseHas('employees', [
            'user_id' => $meca->id,
            'gender' => 'M',
            'marital_status' => 'marie',
            'base_salary' => 180000,
        ]);

        // Un mécanicien ne peut pas accéder aux paramètres de l'espace (403)
        $this->actingAs($meca, 'web')
            ->get('http://design.mboacar.com/parametres-espace')
            ->assertStatus(403);

        // Mise à jour des paramètres de l'espace + horaires + upload logo sécurisé
        $logo = UploadedFile::fake()->image('logo.png', 200, 200);
        $this->actingAs($gerant, 'web')
            ->post('http://design.mboacar.com/parametres-espace', [
                'name' => 'Garage Design Pro',
                'phone' => '699000000',
                'city' => 'Douala',
                'vat_rate' => 19.25,
                'hourly_rate' => 6500,
                'legal_mentions' => 'NIU: M0123456789',
                'work_start_time' => '08:00',
                'work_end_time' => '17:30',
                'work_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'late_penalty_per_hour' => 2000,
                'logo' => $logo,
            ])
            ->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame('Garage Design Pro', $tenant->name);
        $this->assertSame('08:00', $tenant->settings['work_start_time']);
        $this->assertSame(2000, $tenant->settings['late_penalty_per_hour']);
        $this->assertNotEmpty($tenant->settings['logo_path'] ?? null);
        Storage::disk('local')->assertExists($tenant->settings['logo_path']);

        // Modification d'un professionnel
        $this->actingAs($gerant, 'web')
            ->put("http://design.mboacar.com/professionnels/{$meca->id}", [
                'name' => 'Paul Mécanicien Chef',
                'email' => 'paul@design.cm',
                'phone' => '699112244',
                'role' => 'chef_atelier',
                'agency_id' => $agency->id,
                'gender' => 'M',
                'marital_status' => 'marie',
                'base_salary' => 220000,
                'commission_rate' => 7.5,
            ])
            ->assertSessionHas('success');

        $this->assertTrue($meca->fresh()->hasRole('chef_atelier'));
        $this->assertDatabaseHas('employees', [
            'user_id' => $meca->id,
            'full_name' => 'Paul Mécanicien Chef',
            'base_salary' => 220000,
        ]);

        $this->assertSame(10000, PlatformSetting::subscriptionPrice());

        // Réinitialisation du mot de passe à 8 zéros (00000000) par le gérant
        $this->actingAs($gerant, 'web')
            ->post("http://design.mboacar.com/professionnels/{$meca->id}/reinitialiser-mot-de-passe")
            ->assertSessionHas('success');
        $meca->refresh();
        $this->assertTrue(Hash::check('00000000', $meca->password));

        // Modification du profil personnel avec photo + mot de passe
        $avatar = UploadedFile::fake()->image('avatar.png', 100, 100);
        $this->actingAs($meca, 'web')
            ->put('http://design.mboacar.com/mon-profil', [
                'name' => 'Paul Profil',
                'email' => 'paul@design.cm',
                'phone' => '699999999',
                'photo' => $avatar,
            ])
            ->assertSessionHas('success');

        $this->assertNotNull($meca->fresh()->avatar_path);

        $this->actingAs($meca, 'web')
            ->put('http://design.mboacar.com/mon-profil/mot-de-passe', [
                'current_password' => '00000000',
                'password' => 'NouveauPass123!',
                'password_confirmation' => 'NouveauPass123!',
            ])
            ->assertSessionHas('success');
    }
}
