<?php

namespace Tests\Feature\Workflow;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }
}
