<?php

namespace Tests\Feature\Payment;

use App\Models\Agency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaspayPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_checkout_is_idempotent_and_verified_server_side(): void
    {
        config([
            'saspay.secret_key' => 'sk_test_dummy_for_http_fake',
            'saspay.base_url' => 'https://api.saspay.me/api/v1',
        ]);

        Http::fake([
            'https://api.saspay.me/api/v1/checkout-sessions/' => Http::response([
                'data' => [
                    'id' => 'sp_pay_1001',
                    'checkout_url' => 'https://checkout.saspay.me/session/sp_pay_1001',
                ],
            ], 200),
            'https://api.saspay.me/api/v1/payments/sp_pay_1001/verify/' => Http::response([
                'data' => [
                    'id' => 'sp_pay_1001',
                    'status' => 'SUCCESS',
                ],
            ], 200),
        ]);

        $tenant = Tenant::create([
            'name' => 'Garage Pay',
            'slug' => 'pay',
            'email' => 'pay@example.com',
            'status' => Tenant::STATUS_EXPIRED,
            'trial_ends_at' => now()->subDay(),
        ]);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);
        app(TenantRoleService::class)->provisionForTenant($tenant);
        setPermissionsTeamId($tenant->id);

        $gerant = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Gérant',
            'email' => 'g@pay.cm',
            'password' => 'Secret123!',
        ]);
        $gerant->assignRole('gerant');

        // Premier clic -> crée 1 paiement pending et redirige vers checkout_url
        $res1 = $this->actingAs($gerant, 'web')
            ->post('http://pay.mboacar.com/abonnement/payer');
        $res1->assertRedirect('https://checkout.saspay.me/session/sp_pay_1001');

        // Deuxième clic / rafraîchissement -> ne crée PAS un second paiement
        $res2 = $this->actingAs($gerant, 'web')
            ->post('http://pay.mboacar.com/abonnement/payer');
        $res2->assertRedirect('https://checkout.saspay.me/session/sp_pay_1001');

        $this->assertCount(1, Payment::withoutTenantScope()->where('tenant_id', $tenant->id)->get());

        $payment = Payment::withoutTenantScope()->firstOrFail();
        $this->assertNotEmpty($payment->idempotency_key);

        // Retour client -> vérifie via /verify/ et active l'abonnement
        $this->actingAs($gerant, 'web')
            ->get("http://pay.mboacar.com/paiements/{$payment->id}/retour")
            ->assertStatus(200);

        $this->assertSame(Payment::STATUS_SUCCESS, $payment->fresh()->status);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
        $this->assertNotNull($tenant->fresh()->subscription_ends_at);
    }

    public function test_saspay_webhook_deduplicates_events_and_prevents_double_credit_on_invoice(): void
    {
        config([
            'saspay.secret_key' => 'sk_test_dummy_for_http_fake',
            'saspay.webhook_secret' => 'whsec_test_secret',
        ]);

        $tenant = Tenant::create(['name' => 'Garage Facture', 'slug' => 'fac', 'email' => 'f@example.com']);
        $agency = Agency::create(['tenant_id' => $tenant->id, 'name' => 'Agence 1', 'is_main' => true]);
        $customer = Customer::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Client',
            'phone' => '699111222',
            'type' => 'particulier',
        ]);
        $invoice = Invoice::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'FAC-2026-00001',
            'type' => Invoice::TYPE_INVOICE,
            'status' => Invoice::STATUS_ISSUED,
            'subtotal_ht' => 50000,
            'vat_amount' => 0,
            'total_ttc' => 50000,
            'paid_amount' => 0,
            'issued_at' => now(),
        ]);

        $payment = Payment::withoutTenantScope()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'payable_type' => Invoice::class,
            'payable_id' => $invoice->id,
            'payment_method' => 'saspay_softpay',
            'amount' => 25000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_PENDING,
            'provider_payment_id' => 'sp_inv_999',
            'idempotency_key' => '11111111-1111-1111-1111-111111111111',
            'reference_code' => 'PAY-TEST999',
        ]);

        $payload = json_encode([
            'event_id' => 'evt_unique_123',
            'event' => 'payment.succeeded',
            'data' => [
                'id' => 'sp_inv_999',
                'status' => 'SUCCESS',
            ],
        ]);
        $signature = hash_hmac('sha256', $payload, 'whsec_test_secret');

        // Premier appel webhook
        $this->call('POST', '/webhooks/saspay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SASPAY_SIGNATURE' => $signature,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'processed']);

        $invoice->refresh();
        $this->assertSame(25000, $invoice->paid_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        // Deuxième appel webhook avec le MÊME event_id -> ignoré (pas de double crédit !)
        $this->call('POST', '/webhooks/saspay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SASPAY_SIGNATURE' => $signature,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'already_processed']);

        $this->assertSame(25000, $invoice->fresh()->paid_amount);
    }
}
