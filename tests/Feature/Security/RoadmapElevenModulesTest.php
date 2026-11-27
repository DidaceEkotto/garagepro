<?php

namespace Tests\Feature\Security;

use App\Models\Agency;
use App\Models\AppointmentLink;
use App\Models\CashRegisterClosure;
use App\Models\ClientReview;
use App\Models\CourtesyVehicle;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\Tenant;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleReception;
use App\Services\AutomotiveFileAnalyzerService;
use App\Services\OverdueInvoiceReminderService;
use App\Services\PostServiceReviewService;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RoadmapElevenModulesTest extends TestCase
{
    use RefreshDatabase;

    private function createGarageContext(string $slug): array
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => Tenant::TYPE_GARAGE,
            'email' => "{$slug}@mboacar.test",
            'phone' => '+237690001111',
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence Centrale', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant Test',
            'email' => "gerant@{$slug}.test",
            'password' => 'Secret123!',
        ]);
        $user->assignRole('gerant');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Jean Client',
            'gender' => 'homme',
            'phone' => '+237699112233',
            'email' => 'client@test.local',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT-100-AA',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'mileage' => 85000,
        ]);

        return [$tenant, $agency, $user, $customer, $vehicle];
    }

    public function test_lot1_cash_register_open_close_z_detects_discrepancy_and_overdue_reminders_run(): void
    {
        Mail::fake();
        [$tenant, $agency, $user, $customer, $vehicle] = $this->createGarageContext('lot1-caisse');
        $today = now()->toDateString();

        // 1) Ouverture caisse avec fond 25 000 FCFA
        $this->actingAs($user)
            ->post('/caisse/ouvrir', [
                'agency_id' => $agency->id,
                'closure_date' => $today,
                'opening_float' => 25000,
            ])
            ->assertRedirect();

        // Paiement espèces 50 000 + dépense 10 000 => attendu = 25 000 + 50 000 - 10 000 = 65 000
        Payment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'payable_type' => Tenant::class,
            'payable_id' => $tenant->id,
            'reference_code' => 'PAY-Z-1',
            'idempotency_key' => 'idem-pay-z-1',
            'provider' => 'manual',
            'payment_method' => 'especes',
            'amount' => 50000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => now(),
        ]);

        Expense::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'created_by' => $user->id,
            'category' => 'electricite',
            'description' => 'ENEO',
            'amount' => 10000,
            'expense_date' => $today,
        ]);

        // Clôture Z avec 63 000 comptés => écart de -2 000 FCFA
        $this->actingAs($user)
            ->post('/caisse/cloture', [
                'agency_id' => $agency->id,
                'closure_date' => $today,
                'counted_cash' => 63000,
            ])
            ->assertRedirect();

        $closure = CashRegisterClosure::where('agency_id', $agency->id)->whereDate('closure_date', $today)->first();
        $this->assertNotNull($closure);
        $this->assertSame(CashRegisterClosure::STATUS_CLOSED, $closure->status);
        $this->assertSame(65000, $closure->expected_cash);
        $this->assertSame(63000, $closure->counted_cash);
        $this->assertSame(-2000, $closure->cash_discrepancy);

        // 2) Relance d'impayé J+7 + Échéancier
        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'invoice_number' => 'FAC-OVERDUE-1',
            'type' => Invoice::TYPE_INVOICE,
            'status' => Invoice::STATUS_ISSUED,
            'vat_rate' => 0,
            'subtotal_ht' => 100000,
            'discount_amount' => 0,
            'vat_amount' => 0,
            'deposit_deducted' => 0,
            'total_ttc' => 100000,
            'paid_amount' => 0,
            'issued_at' => now()->subDays(40),
            'due_at' => now()->subDays(8),
        ]);

        $sent = app(OverdueInvoiceReminderService::class)->dispatchDueForTenant($tenant);
        $this->assertSame(1, $sent);
        $this->assertSame(2, $invoice->fresh()->overdue_reminder_stage);

        $this->actingAs($user)
            ->post("/factures/{$invoice->id}/echeancier", [
                'installments' => [
                    ['label' => 'Acompte 1', 'amount' => 50000, 'due_date' => now()->addDays(5)->toDateString()],
                    ['label' => 'Solde', 'amount' => 50000, 'due_date' => now()->addDays(20)->toDateString()],
                ],
            ])
            ->assertRedirect();

        $this->assertCount(2, $invoice->fresh()->installment_plan);

        // Vérifie que la page /rapports (Caisse Z + Marge nette OR) charge sans erreur
        $this->actingAs($user)->get('/rapports')->assertOk();
    }

    public function test_lot2_mechanic_timer_damage_diagram_optional_signature_and_courtesy_return(): void
    {
        [$tenant, $agency, $user, $customer, $vehicle] = $this->createGarageContext('lot2-atelier');

        // 1) Chrono mécanicien start -> pause/finish
        $ro = RepairOrder::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-CHRONO-1',
            'status' => RepairOrder::STATUS_IN_PROGRESS,
        ]);

        $task = RepairTask::create([
            'tenant_id' => $tenant->id,
            'repair_order_id' => $ro->id,
            'assigned_mechanic_id' => $user->id,
            'title' => 'Remplacement plaquettes',
            'estimated_minutes' => 60,
            'actual_minutes' => 0,
            'status' => 'a_faire',
        ]);

        $this->actingAs($user)->post("/atelier/taches/{$task->id}/chrono/demarrer")->assertRedirect();
        $this->assertNotNull($task->fresh()->timer_started_at);

        $this->actingAs($user)->post("/atelier/taches/{$task->id}/chrono/pause", [
            'finish' => true,
            'offline_elapsed_minutes' => 45,
        ])->assertRedirect();

        $this->assertSame(45, $task->fresh()->actual_minutes);
        $this->assertSame('termine', $task->fresh()->status);
        $this->assertNull($task->fresh()->timer_started_at);

        // 2) Véhicule de courtoisie + Réception tour de caisse (sans signature obligatoire) + retour
        $this->actingAs($user)->post('/receptions/vehicules-courtoisie', [
            'agency_id' => $agency->id,
            'name' => 'Toyota Yaris Prêt',
            'license_plate' => 'LT-999-PR',
            'current_mileage' => 40000,
            'deposit_amount' => 50000,
        ])->assertRedirect();

        $cv = CourtesyVehicle::first();
        $this->assertNotNull($cv);

        $this->actingAs($user)->post('/receptions', [
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'courtesy_vehicle_id' => $cv->id,
            'courtesy_deposit_amount' => 50000,
            'courtesy_departure_mileage' => 40000,
            'courtesy_departure_fuel' => 80,
            'visit_reason' => 'entretien',
            'mileage_in' => 86000,
            'fuel_level' => 60,
            'damage_markers' => [['x' => 25, 'y' => 40, 'type' => 'rayure']],
        ])->assertRedirect();

        $rec = VehicleReception::first();
        $this->assertNotNull($rec);
        $this->assertCount(1, $rec->damage_markers);
        $this->assertSame(CourtesyVehicle::STATUS_LOANED, $cv->fresh()->status);

        $this->actingAs($user)->post("/receptions/{$rec->id}/retour-courtoisie", [
            'courtesy_return_mileage' => 40180,
            'courtesy_return_fuel' => 75,
            'courtesy_return_notes' => 'RAS',
        ])->assertRedirect();

        $this->assertSame(CourtesyVehicle::STATUS_AVAILABLE, $cv->fresh()->status);
        $this->assertSame(40180, $cv->fresh()->current_mileage);
    }

    public function test_lot3_predictive_reminders_fleet_threshold_and_post_service_review(): void
    {
        Mail::fake();
        [$tenant, $agency, $user, $customer, $vehicle] = $this->createGarageContext('lot3-fidel');

        // 1) Rappel prédictif 15j (courroie/plaquettes) -> crée un lien RDV 1 clic
        $vehicle->update(['next_belt_brake_date' => now()->addDays(7)->toDateString()]);
        $this->actingAs($user)->post('/rappels-entretien/lancer-auto')->assertRedirect();
        $this->assertSame(1, AppointmentLink::where('vehicle_id', $vehicle->id)->count());

        // 2) Seuil alerte coût flotte B2B
        $this->actingAs($user)->patch("/flottes/vehicules/{$vehicle->id}/seuil-cout", [
            'cost_alert_threshold' => 250000,
        ])->assertRedirect();
        $this->assertSame(250000, (int) $vehicle->fresh()->cost_alert_threshold);

        // 3) Enquête satisfaction J+1 -> soumission 5 étoiles -> publie Testimonial
        $ro = RepairOrder::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'order_number' => 'OR-REV-1',
            'status' => RepairOrder::STATUS_DELIVERED,
        ]);
        RepairOrder::withoutGlobalScopes()->where('id', $ro->id)->update(['updated_at' => now()->subHours(30)]);

        $sent = app(PostServiceReviewService::class)->dispatchDueForTenant($tenant);
        $this->assertSame(1, $sent);

        $review = ClientReview::first();
        $this->assertNotNull($review);

        $this->post("/avis/{$review->token}", [
            'rating' => 5,
            'comment' => 'Excellent travail rapide et soigné !',
            'publish_publicly' => true,
        ])->assertRedirect();

        $this->assertSame(5, $review->fresh()->rating);
        $this->assertTrue($review->fresh()->published_publicly);
        $this->assertDatabaseHas('testimonials', ['author_name' => 'Jean Client', 'is_published' => true]);
    }

    public function test_lot4_ecu_diff_vehicle_ocr_and_low_stock_marketplace_suggestions(): void
    {
        [$tenant, $agency, $user] = $this->createGarageContext('lot4-ia');
        config(['services.gemini.api_key' => 'test-key']);

        // 1) Comparateur ECU .ori vs .mod (0 token)
        $oriBytes = str_repeat("\x10", 512);
        $modBytes = substr_replace($oriBytes, "\xFF\xEE\xDD\xCC", 128, 4);
        $ori = UploadedFile::fake()->createWithContent('edc17.ori', $oriBytes);
        $mod = UploadedFile::fake()->createWithContent('edc17.mod', $modBytes);

        $diff = app(AutomotiveFileAnalyzerService::class)->compareEcuPair($ori, $mod);
        $this->assertTrue($diff['ok']);
        $this->assertSame(4, $diff['diff_bytes']);
        $this->assertSame(1, $diff['regions_count']);
        $this->assertStringContainsString('0x000080', $diff['summary']);

        // Sécurité : un ZIP déguisé en .mod est bloqué par le comparateur
        $fakeZip = UploadedFile::fake()->createWithContent('bad.mod', "PK\x03\x04payload");
        $blocked = app(AutomotiveFileAnalyzerService::class)->compareEcuPair($ori, $fakeZip);
        $this->assertFalse($blocked['ok']);

        // 2) OCR Carte Grise / Plaque (Gemini Vision mocké)
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"license_plate":"LT-777-ZZ","brand":"Peugeot","model":"308","year":2019,"vin":"VF3LPHNYHKS123456","fuel_type":"diesel","color":"Gris"}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $photo = UploadedFile::fake()->image('carte_grise.jpg', 400, 300);
        $this->actingAs($user)
            ->postJson('/vehicules/ocr', ['photo' => $photo])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.license_plate', 'LT-777-ZZ')
            ->assertJsonPath('data.brand', 'Peugeot');

        // 3) Alerte stock bas + suggestion réapprovisionnement Marketplace
        Part::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'sku' => 'FLT-HUILE-01',
            'name' => 'Filtre huile Bosch',
            'category' => 'piece',
            'condition' => 'neuve',
            'quantity_on_hand' => 1,
            'min_threshold' => 3,
            'purchase_price' => 4500,
            'selling_price' => 7000,
        ]);

        $category = \App\Models\Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Filtration',
            'slug' => 'filtration-test',
        ]);

        Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'title' => 'Filtre huile Bosch Origine',
            'sku' => 'FLT-HUILE-01',
            'price' => 5500,
            'stock_quantity' => 20,
            'is_published' => true,
        ]);

        $this->actingAs($user)
            ->get('/stock')
            ->assertOk();
    }
}
