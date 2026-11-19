<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\CourtesyVehicle;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Services\SecureUploadService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleReceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_reception_stores_secure_files_and_manages_courtesy_vehicle(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Garage Réception', 'slug' => 'reception', 'email' => 'r@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Réception',
            'email' => 'rec@reception.cm',
            'password' => 'Secret123!',
        ]);
        $user->assignRole('receptionniste');

        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Mme Fotso',
            'phone' => '690112233',
            'type' => 'particulier',
        ]);

        $vehicle = Vehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE 789 XY',
            'brand' => 'Peugeot',
            'model' => '3008',
            'mileage' => 45000,
        ]);

        $courtesy = CourtesyVehicle::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Renault Clio Courtoisie',
            'license_plate' => 'CE 001 CT',
            'deposit_amount' => 100000,
        ]);

        $signature = UploadedFile::fake()->image('signature.png', 200, 100);
        $photo = UploadedFile::fake()->image('rayure.jpg', 400, 300);

        $this->actingAs($user, 'web')
            ->post('http://reception.mboacar.com/receptions', [
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'courtesy_vehicle_id' => $courtesy->id,
                'courtesy_deposit_amount' => 100000,
                'visit_reason' => 'panne',
                'mileage_in' => 45200,
                'fuel_level' => 60,
                'belongings_left' => 'Cric + roue de secours',
                'damage_markers' => [['zone' => 'aile_avant_gauche', 'type' => 'rayure']],
                'signature' => $signature,
                'photos' => [$photo],
            ])
            ->assertSessionHas('success');

        $reception = VehicleReception::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertNull($reception->client_signature_path);
        $this->assertCount(1, $reception->photos);
        Storage::disk('local')->assertExists($reception->photos[0]);

        // Kilométrage véhicule mis à jour
        $this->assertSame(45200, $vehicle->fresh()->mileage);

        // Véhicule de courtoisie passé en prêté puis restitué
        $this->assertSame(CourtesyVehicle::STATUS_LOANED, $courtesy->fresh()->status);

        $this->actingAs($user, 'web')
            ->post("http://reception.mboacar.com/receptions/{$reception->id}/retour-courtoisie")
            ->assertSessionHas('success');

        $this->assertSame(CourtesyVehicle::STATUS_AVAILABLE, $courtesy->fresh()->status);

        // Accès au fichier privé via URL signée uniquement
        $uploadService = app(SecureUploadService::class);
        $signedUrl = str_replace(config('app.url'), 'http://reception.mboacar.com', $uploadService->signedUrl($reception->photos[0]));
        $this->actingAs($user, 'web')->get($signedUrl)->assertStatus(200);

        // Sans signature -> 403
        $unsignedPath = base64_encode($reception->photos[0]);
        $this->actingAs($user, 'web')
            ->get("http://reception.mboacar.com/fichiers-securises/{$unsignedPath}")
            ->assertStatus(403);
    }
}
