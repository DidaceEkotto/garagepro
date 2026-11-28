<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\Appointment;
use App\Models\PlatformMessage;
use App\Models\RoadsideSosRequest;
use App\Models\Tenant;
use App\Models\TenantService;
use App\Models\User;
use App\Services\GreenApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowcaseAndBookingTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithOwner(string $slug, string $name, float $lat, float $lon): array
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'type' => Tenant::TYPE_GARAGE,
            'email' => "{$slug}@example.com",
            'phone' => '+237690000111',
            'city' => 'Douala',
            'country' => 'CM',
            'latitude' => $lat,
            'longitude' => $lon,
            'formatted_location' => 'Akwa, Douala',
            'status' => Tenant::STATUS_ACTIVE,
            'b2b_directory_visible' => true,
            'sos_enabled' => true,
            'sos_radius_km' => 50,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'is_active' => true,
        ]);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin '.$name,
            'email' => "owner-{$slug}@example.com",
            'password' => bcrypt('Secret123!'),
            'role' => 'gerant',
            'is_active' => true,
        ]);

        return [$tenant, $owner];
    }

    public function test_tenant_can_upload_showcase_photos_and_manage_services_with_isolation(): void
    {
        Storage::fake('public');

        [$tenantA, $ownerA] = $this->createTenantWithOwner('garage-alpha', 'Garage Alpha', 4.0511, 9.7679);
        [$tenantB, $ownerB] = $this->createTenantWithOwner('garage-beta', 'Garage Beta', 4.0550, 9.7700);

        // 1. Upload multiple photos établissement
        $resPhotos = $this->actingAs($ownerA)->post('/vitrine-prestations/photos', [
            'photos' => [
                UploadedFile::fake()->image('facade.jpg', 800, 600),
                UploadedFile::fake()->image('atelier.png', 800, 600),
            ],
        ]);
        $resPhotos->assertRedirect();

        $tenantA->refresh();
        $this->assertCount(2, $tenantA->showcase_photos ?? []);

        // Vérifier que la route directe d'image vitrine répond 200 sans dépendre de storage:link
        $this->get("/garage-media/vitrine/{$tenantA->id}/0")->assertOk();

        // 2. Créer une prestation AVEC image facultative
        $resSvc1 = $this->actingAs($ownerA)->post('/vitrine-prestations/prestations', [
            'name' => 'Vidange complète 5W30',
            'category' => 'entretien',
            'description' => 'Huile synthétique + filtre à huile',
            'price' => 25000,
            'duration_minutes' => 45,
            'image' => UploadedFile::fake()->image('vidange.webp', 600, 400),
        ]);
        $resSvc1->assertRedirect();

        $svcWithImage = TenantService::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        $this->get("/garage-media/prestation/{$svcWithImage->id}")->assertOk();

        // 3. Créer une prestation SANS image
        $resSvc2 = $this->actingAs($ownerA)->post('/vitrine-prestations/prestations', [
            'name' => 'Diagnostic OBD complet',
            'category' => 'diagnostic',
            'price' => 10000,
            'duration_minutes' => 30,
        ]);
        $resSvc2->assertRedirect();

        $this->assertEquals(2, TenantService::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());

        // 4. Isolation multi-tenant : Tenant B ne peut pas supprimer la prestation de Tenant A
        $serviceA = TenantService::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        $this->actingAs($ownerB)
            ->delete("/vitrine-prestations/prestations/{$serviceA->id}")
            ->assertStatus(404);
    }

    public function test_public_nearby_returns_paginated_batch_of_20_and_all_map_pins(): void
    {
        [$tenantA] = $this->createTenantWithOwner('garage-one', 'Garage One', 4.0512, 9.7680);
        TenantService::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Vidange Express',
            'category' => 'entretien',
            'price' => 15000,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        // Créer 21 garages supplémentaires pour vérifier la pagination par lots de 20
        for ($i = 2; $i <= 22; $i++) {
            $this->createTenantWithOwner("garage-{$i}", "Garage {$i}", 4.0512 + ($i * 0.001), 9.7680);
        }

        $resp1 = $this->getJson('/sos-depannage/proximite?lat=4.0512&lon=9.7680&radius_km=50&page=1&per_page=20');
        $resp1->assertOk()
            ->assertJsonPath('count', 20)
            ->assertJsonPath('total', 22)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('establishments.0.slug', 'garage-one')
            ->assertJsonPath('establishments.0.min_price', 15000);

        $this->assertCount(22, $resp1->json('map_pins'));

        $resp2 = $this->getJson('/sos-depannage/proximite?lat=4.0512&lon=9.7680&radius_km=50&page=2&per_page=20');
        $resp2->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('has_more', false);
    }

    public function test_public_garage_booking_creates_appointment_admin_message_and_sends_sync_whatsapp(): void
    {
        [$tenant] = $this->createTenantWithOwner('garage-express', 'Garage Express Douala', 4.0511, 9.7679);

        $service = TenantService::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Révision freinage',
            'category' => 'mecanique',
            'price' => 35000,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $whatsappSent = false;
        $mockGreenApi = $this->mock(GreenApiService::class);
        $mockGreenApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $phone, string $message) use (&$whatsappSent) {
                $whatsappSent = str_contains($message, 'NOUVEAU RENDEZ-VOUS EN LIGNE')
                    && str_contains($message, 'Révision freinage')
                    && str_contains($message, 'LT 999 AA');

                return $phone === '+237690000111';
            })
            ->andReturn(['ok' => true]);

        // Page publique accessible
        $this->get("/garage/{$tenant->slug}")->assertOk();

        // Prise de rendez-vous en ligne
        $resp = $this->post("/garage/{$tenant->slug}/rendez-vous", [
            'service_ids' => [$service->id],
            'customer_name' => 'Kamga',
            'customer_first_name' => 'Paul',
            'dial_code' => '+237',
            'customer_phone' => '677112233',
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'RAV4',
            'vehicle_plate' => 'LT 999 AA',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'notes' => 'Bruit à l\'avant gauche',
        ]);

        $resp->assertRedirect();
        $this->assertTrue($whatsappSent, 'Le WhatsApp synchrone doit être envoyé au garage immédiatement.');

        // Rendez-vous visible dans /rendez-vous du tenant
        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $tenant->id,
            'channel' => 'en_ligne',
            'status' => Appointment::STATUS_PENDING,
        ]);

        // Notification interne créée dans l'espace admin du tenant
        $this->assertDatabaseHas('platform_messages', [
            'tenant_id' => $tenant->id,
            'recipient_type' => PlatformMessage::TYPE_TENANT,
            'recipient_id' => $tenant->id,
            'is_read' => false,
        ]);
    }

    public function test_public_sos_drawer_accepts_dedicated_fields_and_multiple_photos(): void
    {
        Storage::fake('public');
        $this->createTenantWithOwner('garage-sos-1', 'Garage SOS 1', 4.0515, 9.7682);

        $mockGreenApi = $this->mock(GreenApiService::class);
        $mockGreenApi->shouldReceive('sendMessage')->andReturn(['ok' => true]);

        $resp = $this->post('/sos-depannage', [
            'breakdown_type' => 'moteur',
            'driver_name' => 'Ndongo',
            'driver_first_name' => 'Marc',
            'driver_dial_code' => '+237',
            'driver_phone' => '699887766',
            'vehicle_brand' => 'Peugeot',
            'vehicle_model' => '3008',
            'vehicle_plate' => 'CE 456 XY',
            'description' => 'Fumée blanche sous le capot',
            'latitude' => 4.0511,
            'longitude' => 9.7679,
            'location_label' => 'Akwa, Douala',
            'photos' => [
                UploadedFile::fake()->image('panne1.jpg', 640, 480),
                UploadedFile::fake()->image('panne2.png', 640, 480),
            ],
        ]);

        $resp->assertRedirect();

        $sos = RoadsideSosRequest::latest('id')->firstOrFail();
        $this->assertSame('Ndongo Marc', $sos->driver_name);
        $this->assertSame('+237', $sos->driver_dial_code);
        $this->assertSame('+237699887766', $sos->driver_phone);
        $this->assertSame('Peugeot', $sos->vehicle_brand);
        $this->assertSame('3008', $sos->vehicle_model);
        $this->assertSame('CE 456 XY', $sos->vehicle_plate);
        $this->assertCount(2, $sos->photos ?? []);
    }
}
