<?php

namespace Tests\Feature;

use App\Mail\RichMessageMail;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PlatformMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TenantContext;
use App\Services\TenantRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsSaspayAndMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedGerant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Garage Elite',
            'slug' => 'garage-elite',
            'email' => 'gerant@elite.cm',
            'country' => 'CM',
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(5),
        ]);

        app(TenantContext::class)->setTenant($tenant);
        app(TenantRoleService::class)->provisionForTenant($tenant);

        $agency = Agency::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agence Principale',
            'code' => 'AG01',
            'is_main' => true,
            'is_active' => true,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'default_agency_id' => $agency->id,
            'name' => 'Jean Gérant',
            'email' => 'gerant@elite.cm',
            'password' => 'MotDePasse@123',
            'is_active' => true,
        ]);
        $user->assignRole('gerant');

        return [$tenant, $agency, $user];
    }

    public function test_reports_calculates_monthly_profit_and_triggers_deficit_alert(): void
    {
        [$tenant, $agency, $user] = $this->createAuthenticatedGerant();

        $lastMonth = now()->startOfMonth()->subMonth();

        Payment::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'payable_type' => Tenant::class,
            'payable_id' => $tenant->id,
            'payment_method' => 'especes',
            'amount' => 100000,
            'currency' => 'XAF',
            'status' => Payment::STATUS_SUCCESS,
            'idempotency_key' => 'k-rev-1',
            'reference_code' => 'PAY-REV1',
            'paid_at' => $lastMonth->copy()->addDays(5),
        ]);

        $this->actingAs($user, 'web')
            ->post('http://garage-elite.mboacar.com/rapports/depenses', [
                'agency_id' => $agency->id,
                'category' => 'loyer',
                'description' => 'Loyer atelier mois passé',
                'amount' => 250000,
                'expense_date' => $lastMonth->copy()->addDays(10)->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(1, Expense::withoutTenantScope()->count());

        $this->actingAs($user, 'web')
            ->get('http://garage-elite.mboacar.com/rapports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tenant/Reports/Index')
                ->where('stats.isDeficitAlert', true)
                ->where('stats.lastCompleted.netProfit', -150000)
                ->has('monthlyComparison', 6)
            );
    }

    public function test_trialing_tenant_can_subscribe_with_saspay_webhook_secret_fallback_and_base_url(): void
    {
        [$tenant, $agency, $user] = $this->createAuthenticatedGerant();

        config([
            'saspay.secret_key' => 'sk_test_from_webhook_secret',
            'saspay.base_url' => 'https://api.saspay.me',
        ]);

        Http::fake([
            'https://api.saspay.me/api/v1/checkout-sessions/' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'checkout_url' => 'https://checkout.saspay.me/pay/cs_test_123',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'web')
            ->post('http://garage-elite.mboacar.com/abonnement/payer');

        $response->assertRedirect('https://checkout.saspay.me/pay/cs_test_123');

        $payment = Payment::withoutTenantScope()->where('provider_payment_id', 'cs_test_123')->firstOrFail();
        app(PaymentService::class)->markPaymentSucceeded($payment);

        $freshTenant = $tenant->fresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $freshTenant->status);
        $this->assertTrue($freshTenant->subscription_ends_at->greaterThan(now()->addMonth()));
    }

    public function test_internal_admin_messaging_does_not_send_email_while_bulk_customer_messaging_sends_attachments(): void
    {
        Mail::fake();
        Storage::fake('local');
        [$tenant, $agency, $user] = $this->createAuthenticatedGerant();

        $c1 = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client A',
            'phone' => '+237 699000001',
            'email' => 'clienta@example.cm',
            'type' => 'particulier',
        ]);
        $c2 = Customer::create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'name' => 'Client B',
            'phone' => '+237 699000002',
            'email' => 'clientb@example.cm',
            'type' => 'particulier',
        ]);

        $txtFile = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
        $pdfFile = UploadedFile::fake()->create('devis.pdf', 50, 'application/pdf');

        // 1. Message vers Admin => interne uniquement, 0 email envoyé
        $this->actingAs($user, 'web')
            ->post('http://garage-elite.mboacar.com/messagerie', [
                'recipient_scope' => 'admin',
                'subject' => 'Question support',
                'body' => 'Bonjour Admin, merci de vérifier notre compte.',
                'attachments' => [$txtFile],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $adminMsg = PlatformMessage::where('recipient_type', PlatformMessage::TYPE_ADMIN)->first();
        $this->assertNotNull($adminMsg);
        $this->assertCount(1, $adminMsg->attachments);

        // 2. Message groupé vers 2 clients avec pièce jointe PDF => 2 emails avec lien de téléchargement
        $this->actingAs($user, 'web')
            ->post('http://garage-elite.mboacar.com/messagerie', [
                'recipient_scope' => 'customers',
                'customer_ids' => [$c1->id, $c2->id],
                'subject' => 'Promo vidange',
                'body' => 'Profitez de -20% cette semaine.',
                'attachments' => [$pdfFile],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(RichMessageMail::class, fn ($m) => $m->hasTo('clienta@example.cm') && count($m->attachmentLinks) === 1);
        Mail::assertSent(RichMessageMail::class, fn ($m) => $m->hasTo('clientb@example.cm') && count($m->attachmentLinks) === 1);

        // 3. Réponse à un message provenant d'un Admin => reste interne (aucun email supplémentaire)
        $incomingAdminMsg = PlatformMessage::create([
            'tenant_id' => $tenant->id,
            'sender_type' => PlatformMessage::TYPE_ADMIN,
            'sender_name' => 'Administration GaragePro',
            'recipient_type' => PlatformMessage::TYPE_TENANT,
            'recipient_id' => $tenant->id,
            'subject' => 'Info plateforme',
            'body' => 'Votre espace est vérifié.',
            'is_read' => false,
        ]);

        $this->actingAs($user, 'web')
            ->post("http://garage-elite.mboacar.com/messagerie/{$incomingAdminMsg->id}/repondre", [
                'body' => 'Merci beaucoup !',
            ])
            ->assertRedirect();

        Mail::assertSentCount(2); // Toujours uniquement les 2 emails clients
        $this->assertTrue($incomingAdminMsg->fresh()->is_read);
    }
}
