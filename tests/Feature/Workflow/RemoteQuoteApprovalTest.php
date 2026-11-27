<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RepairOrder;
use App\Models\RepairTask;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RemoteQuoteApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_remote_quote_whatsapp_refuse_revise_and_sign_loop(): void
    {
        Storage::fake('public');

        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        $sentMessages = [];
        Http::fake(function ($req) use (&$sentMessages) {
            if (isset($req['chatId'], $req['message'])) {
                $sentMessages[] = [
                    'chatId' => $req['chatId'],
                    'message' => $req['message'],
                ];
            }

            return Http::response(['idMessage' => 'msg-ok'], 200);
        });

        $tenant = Tenant::create([
            'name' => 'Garage Excellence',
            'slug' => 'excellence',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'contact@excellence.cm',
            'phone' => '+237690000100',
            'vat_rate' => 19.25,
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Douala',
            'is_main' => true,
        ]);

        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $owner = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant Excellence',
            'email' => 'gerant@excellence.cm',
            'password' => 'Secret123!',
        ]);
        $owner->assignRole('gerant');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Tchoumi',
            'first_name' => 'Eric',
            'gender' => 'homme',
            'phone_dial_code' => '+237',
            'phone' => '+237699887766',
            'whatsapp_phone' => '+237699887766',
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'LT 555 EE',
            'brand' => 'Toyota',
            'model' => 'Hilux',
        ]);

        // 1. Création du devis -> envoi WhatsApp automatique avec lien /devis-public/{token}
        $this->actingAs($owner, 'web')
            ->post('http://excellence.mboacar.com/devis', [
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'discount_amount' => 0,
                'deposit_required' => 10000,
                'notes' => 'Révision complète',
                'items' => [
                    [
                        'type' => 'main_oeuvre',
                        'urgency' => 'urgent',
                        'description' => 'Remplacement plaquettes avant',
                        'quantity' => 1,
                        'unit_price' => 45000,
                    ],
                ],
            ])
            ->assertSessionHas('success');

        $quote = Quote::withoutTenantScope()->firstOrFail();
        $this->assertNotEmpty($quote->public_token);
        $this->assertSame(Quote::STATUS_SENT, $quote->status);
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString("/devis-public/{$quote->public_token}", $sentMessages[0]['message']);

        // 2. Le client ouvre la page publique et refuse avec un motif
        $this->get("/devis-public/{$quote->public_token}")->assertOk();

        $this->post("/devis-public/{$quote->public_token}/repondre", [
            'decision' => 'reject',
            'rejection_reason' => 'Trop cher, pouvez-vous descendre à 30 000 FCFA ?',
        ])->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame(Quote::STATUS_REFUSED, $quote->status);
        $this->assertSame('Trop cher, pouvez-vous descendre à 30 000 FCFA ?', $quote->rejection_reason);
        // Le garage a été alerté sur WhatsApp
        $this->assertCount(2, $sentMessages);
        $this->assertSame('237690000100@c.us', $sentMessages[1]['chatId']);

        // 3. Le garage réajuste le devis -> v2 + renvoi WhatsApp automatique au client
        $this->actingAs($owner, 'web')
            ->put("http://excellence.mboacar.com/devis/{$quote->id}/reajuster", [
                'discount_amount' => 0,
                'deposit_required' => 5000,
                'notes' => 'Prix ajusté selon votre demande',
                'items' => [
                    [
                        'type' => 'main_oeuvre',
                        'urgency' => 'urgent',
                        'description' => 'Remplacement plaquettes avant (tarif ajusté)',
                        'quantity' => 1,
                        'unit_price' => 30000,
                    ],
                ],
            ])
            ->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame(2, $quote->version);
        $this->assertSame(Quote::STATUS_SENT, $quote->status);
        $this->assertNull($quote->rejection_reason);
        $this->assertCount(3, $sentMessages);
        $this->assertSame('237699887766@c.us', $sentMessages[2]['chatId']);
        $this->assertStringContainsString('v2', $sentMessages[2]['message']);

        // 4. Le client valide et signe électroniquement (PNG base64)
        $fakePngBase64 = 'data:image/png;base64,'.base64_encode(str_repeat("\x89PNG\r\n\x1a\n", 20));
        $this->post("/devis-public/{$quote->public_token}/repondre", [
            'decision' => 'accept',
            'signed_by_name' => 'Eric Tchoumi',
            'signature_data' => $fakePngBase64,
        ])->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame(Quote::STATUS_ACCEPTED, $quote->status);
        $this->assertSame('Eric Tchoumi', $quote->signed_by_name);
        $this->assertNotNull($quote->signed_at);
        $this->assertNotNull($quote->client_signature_path);
        Storage::disk('public')->assertExists($quote->client_signature_path);

        // Ordre de réparation généré automatiquement + garage notifié sur WhatsApp
        $this->assertNotNull($quote->repair_order_id);
        $this->assertSame(1, RepairOrder::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
        $this->assertCount(4, $sentMessages);
        $this->assertSame('237690000100@c.us', $sentMessages[3]['chatId']);

        // 5. Téléchargement du PDF avec signature manuscrite incrustée
        $this->actingAs($owner, 'web')
            ->get("http://excellence.mboacar.com/devis/{$quote->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_partial_line_acceptance_and_auto_j2_reminder_command(): void
    {
        Storage::fake('public');

        config([
            'services.greenapi.url' => 'https://api.green-api.com',
            'services.greenapi.id_instance' => '123456',
            'services.greenapi.api_token_instance' => 'token-secret',
        ]);

        $sentMessages = [];
        Http::fake(function ($req) use (&$sentMessages) {
            if (isset($req['chatId'], $req['message'])) {
                $sentMessages[] = [
                    'chatId' => $req['chatId'],
                    'message' => $req['message'],
                ];
            }

            return Http::response(['idMessage' => 'msg-j2'], 200);
        });

        $tenant = Tenant::create([
            'name' => 'Garage Rapid',
            'slug' => 'rapid',
            'type' => Tenant::TYPE_GARAGE,
            'email' => 'contact@rapid.cm',
            'phone' => '+237690000200',
            'vat_rate' => 0,
            'status' => Tenant::STATUS_ACTIVE,
            'subscription_ends_at' => now()->addMonth(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Yaoundé', 'is_main' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Mballa',
            'gender' => 'homme',
            'phone' => '+237699112233',
            'whatsapp_phone' => '+237699112233',
        ]);
        $vehicle = Vehicle::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'license_plate' => 'CE 777 RR',
            'brand' => 'Honda',
            'model' => 'CR-V',
        ]);

        // Devis envoyé il y a 50 heures sans réponse -> éligible à la relance auto J+2
        $quote = Quote::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'quote_number' => 'DEV-J2-001',
            'version' => 1,
            'status' => Quote::STATUS_SENT,
            'sent_at' => now()->subHours(50),
            'valid_until' => now()->addDays(10),
            'vat_rate' => 0,
            'discount_amount' => 0,
        ]);

        $urgentItem = QuoteItem::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'type' => 'main_oeuvre',
            'urgency' => 'urgent',
            'description' => 'Freinage avant (urgent)',
            'quantity' => 1,
            'unit_price' => 30000,
            'total_price' => 30000,
            'is_accepted' => true,
        ]);

        $optionalItem = QuoteItem::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'type' => 'piece',
            'urgency' => 'optionnel',
            'description' => 'Lustrage carrosserie (optionnel)',
            'quantity' => 1,
            'unit_price' => 20000,
            'total_price' => 20000,
            'is_accepted' => true,
        ]);

        $quote->recalculateTotals();
        $this->assertSame(50000, $quote->fresh()->total_ttc);

        // 1. Exécution de la commande J+2 -> envoie 1 relance WhatsApp et marque auto_reminded_at
        Artisan::call('quotes:remind-pending');
        $this->assertNotNull($quote->fresh()->auto_reminded_at);
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString("/devis-public/{$quote->public_token}", $sentMessages[0]['message']);

        // 2. Relancer la commande une 2e fois -> aucun doublon de relance !
        Artisan::call('quotes:remind-pending');
        $this->assertCount(1, $sentMessages);

        // 3. Validation partielle par le client (décoche la ligne optionnelle, garde l'urgente)
        $this->post("/devis-public/{$quote->public_token}/repondre", [
            'decision' => 'accept',
            'signed_by_name' => 'Mballa',
            'accepted_item_ids' => [$urgentItem->id],
        ])->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame(Quote::STATUS_PARTIALLY_ACCEPTED, $quote->status);
        $this->assertSame(30000, $quote->total_ttc);
        $this->assertTrue((bool) $urgentItem->fresh()->is_accepted);
        $this->assertFalse((bool) $optionalItem->fresh()->is_accepted);
        // Seule la ligne acceptée est convertie en tâche atelier
        $this->assertSame(1, RepairTask::withoutTenantScope()->where('repair_order_id', $quote->repair_order_id)->count());
    }
}
