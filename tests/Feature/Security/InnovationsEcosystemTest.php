<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\AiDiagnosticMessage;
use App\Models\AppointmentLink;
use App\Models\Customer;
use App\Models\RoadsideSosOffer;
use App\Models\RoadsideSosRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Models\WhatsappBotLog;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InnovationsEcosystemTest extends TestCase
{
    use RefreshDatabase;

    private function createGarage(string $slug, float $lat, float $lon, string $type = Tenant::TYPE_GARAGE): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => $type,
            'email' => "{$slug}@innov.test",
            'phone' => '+237690005555',
            'city' => 'Douala',
            'country' => 'CM',
            'latitude' => $lat,
            'longitude' => $lon,
            'formatted_location' => 'Akwa, Douala, Cameroun',
            'status' => Tenant::STATUS_ACTIVE,
            'b2b_directory_visible' => true,
            'sos_enabled' => true,
            'sos_radius_km' => 35,
            'whatsapp_bot_enabled' => true,
            'whatsapp_bot_secret' => 'secret-bot-token-12345',
            'whatsapp_bot_catalog' => 'Vidange complète 25 000 FCFA, Diagnostic valise 10 000 FCFA.',
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Atelier', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => "Chef {$slug}",
            'email' => "chef@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_osm_geocode_proxy_and_sos_roadside_end_to_end_with_idor_protection(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'display_name' => 'Akwa, Douala, Littoral, Cameroun',
                'name' => 'Akwa',
                'lat' => '4.0511',
                'lon' => '9.7085',
                'address' => ['suburb' => 'Akwa', 'city' => 'Douala', 'country' => 'Cameroun'],
            ]], 200),
        ]);

        // 1) Proxy OpenStreetMap autocomplétion
        $this->getJson('/api/geocode/search?q=Akwa&country=CM')
            ->assertOk()
            ->assertJsonPath('results.0.city', 'Douala')
            ->assertJsonPath('results.0.lat', 4.0511);

        // 2) SOS Dépannage proximité Haversine
        [$nearGarage, , $nearUser] = $this->createGarage('garage-proche', 4.0520, 9.7090);
        [$farGarage, , $farUser] = $this->createGarage('garage-loin', 3.8480, 11.5021); // Yaoundé (~200km)

        $nearbyRes = $this->getJson('/sos-depannage/proximite?lat=4.0511&lon=9.7085&radius_km=30')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('establishments.0.id', $nearGarage->id);

        $this->assertLessThan(2.0, $nearbyRes->json('establishments.0.distance_km'));

        // 3) Conducteur envoie un SOS -> Garage proche fait une offre -> Conducteur accepte -> Garage loin bloqué en 403
        $this->post('/sos-depannage', [
            'driver_name' => 'Marc Conducteur',
            'driver_phone' => '+237699111222',
            'vehicle_info' => 'Toyota RAV4 grise',
            'breakdown_type' => 'batterie',
            'latitude' => 4.0511,
            'longitude' => 9.7085,
            'location_label' => 'Akwa, Douala',
        ])->assertRedirect();

        $sos = RoadsideSosRequest::first();
        $this->assertNotNull($sos);

        $this->actingAs($nearUser)->post("/depannage-sos/{$sos->id}/offre", [
            'price' => 15000,
            'eta_minutes' => 20,
            'message' => 'Dépanneur en route',
        ])->assertRedirect();

        $offer = RoadsideSosOffer::first();
        $this->assertNotNull($offer);

        $this->post("/sos-depannage/{$sos->token}/offres/{$offer->id}/accepter")->assertRedirect();
        $this->assertSame(RoadsideSosRequest::STATUS_ACCEPTED, $sos->fresh()->status);

        // Sécurité IDOR : un autre garage ne peut pas modifier le statut de cette mission
        $this->actingAs($farUser)->patch("/depannage-sos/{$sos->id}/statut", ['status' => 'resolved'])->assertForbidden();

        // Le garage accepté peut clôturer la mission
        $this->actingAs($nearUser)->patch("/depannage-sos/{$sos->id}/statut", ['status' => 'resolved'])->assertRedirect();
        $this->assertSame(RoadsideSosRequest::STATUS_RESOLVED, $sos->fresh()->status);
    }

    public function test_mboacar_check_detects_odometer_fraud_and_qr_smart_scan_redirects_correctly(): void
    {
        [$tenant, $agency, $user] = $this->createGarage('garage-check', 4.05, 9.70);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client Confidentiel',
            'gender' => 'homme',
            'phone' => '+237699888777',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT-555-CK',
            'brand' => 'Honda',
            'model' => 'CR-V',
            'year' => 2018,
            'vin' => 'JHLRW2H59JX000123',
            'mileage' => 80000,
        ]);

        // Passage 1 : 120 000 km il y a 6 mois
        VehicleReception::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'received_by' => $user->id,
            'visit_reason' => 'entretien',
            'mileage_in' => 120000,
            'fuel_level' => 50,
            'received_at' => now()->subMonths(6),
        ]);

        // Passage 2 : 78 000 km aujourd'hui => FRAUDE COMPTEUR (-42 000 km) !
        VehicleReception::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'received_by' => $user->id,
            'visit_reason' => 'controle',
            'mileage_in' => 78000,
            'fuel_level' => 60,
            'received_at' => now(),
        ]);

        $report = app(\App\Http\Controllers\VehicleHistoryCheckController::class)->buildReport('LT-555-CK');
        $this->assertTrue($report['found']);
        $this->assertTrue($report['odometer_fraud_detected']);
        $this->assertLessThanOrEqual(55, $report['trust_score']);
        // Vérifie l'anonymisation : aucun nom de client dans le rapport public
        $this->assertStringNotContainsString('Client Confidentiel', json_encode($report));

        $this->get('/verifier-vehicule/pdf?q=LT-555-CK')->assertOk();

        // Étiquette QR & Scan intelligent
        $this->actingAs($user)->get("/vehicules/{$vehicle->id}/etiquette-qr")->assertOk();
        $this->actingAs($user)
            ->get("/vehicule-scan/{$vehicle->public_token}")
            ->assertRedirect(route('tenant.vehicles.show', ['vehicle' => $vehicle->id, 'checkin' => 1]));

        auth()->logout();
        $this->get("/vehicule-scan/{$vehicle->public_token}")
            ->assertRedirect(route('public.vehicle.passport', ['token' => $vehicle->public_token]));
    }

    public function test_acoustic_ai_diagnostic_and_whatsapp_bot_24_7_security(): void
    {
        [$tenant, , $user] = $this->createGarage('garage-bot', 4.05, 9.70);
        config(['services.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => "🔴 ROUGE — Arrêt immédiat : risque de coussinet de bielle.\n1. Coussinet de bielle usé\n2. Pression huile insuffisante",
                        ]],
                    ],
                ]],
                'usageMetadata' => ['totalTokenCount' => 180],
            ], 200),
        ]);

        // 1) Diagnostic Acoustique IA
        $this->actingAs($user)->post('/assistant-ia/diagnostic-acoustique', [
            'zone' => 'bas_moteur',
            'condition' => 'acceleration',
            'description' => 'Claquement sourd à l\'accélération',
        ])->assertRedirect();

        $aiMsg = AiDiagnosticMessage::where('role', 'assistant')->latest()->first();
        $this->assertNotNull($aiMsg);
        $this->assertStringContainsString('ROUGE', $aiMsg->content);

        // 2) Webhook Bot WhatsApp 24/7 — mauvais secret bloqué en 403
        $this->postJson("/webhooks/whatsapp-bot/{$tenant->slug}", [
            'sender_phone' => '+237699333444',
            'message' => 'Bonjour, avez-vous de la place demain pour une vidange ?',
        ], ['X-Bot-Secret' => 'wrong-secret'])->assertForbidden();

        // Bon secret -> génère réponse + lien RDV /rdv/{token} + log en BDD
        $botRes = $this->postJson("/webhooks/whatsapp-bot/{$tenant->slug}", [
            'sender_phone' => '+237699333444',
            'message' => 'Bonjour, avez-vous de la place demain pour une vidange ?',
        ], ['X-Bot-Secret' => 'secret-bot-token-12345'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotEmpty($botRes->json('rdv_link_url'));
        $this->assertSame(1, AppointmentLink::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, WhatsappBotLog::where('tenant_id', $tenant->id)->count());
    }
}
