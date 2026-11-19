<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestre les paiements SaSPay et locaux avec idempotence stricte et verrous.
 */
class PaymentService
{
    public function __construct(private readonly SaspayClient $saspayClient) {}

    public function initiateSaspayCheckout(
        Tenant $tenant,
        Model $payable,
        int $amount,
        string $description,
        string $customerEmail,
        string $customerName,
        string $returnUrl,
        ?int $agencyId = null
    ): Payment {
        $lockKey = 'payment_lock:'.$payable->getMorphClass().':'.$payable->getKey();

        return Cache::lock($lockKey, 10)->block(5, function () use (
            $tenant,
            $payable,
            $amount,
            $description,
            $customerEmail,
            $customerName,
            $returnUrl,
            $agencyId
        ): Payment {
            // Idempotence double-clic / rafraîchissement : réutilise un paiement en cours pour la même cible.
            $existingPending = Payment::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('payable_type', $payable->getMorphClass())
                ->where('payable_id', $payable->getKey())
                ->where('amount', $amount)
                ->where('status', Payment::STATUS_PENDING)
                ->whereNotNull('checkout_url')
                ->latest('id')
                ->first();

            if ($existingPending) {
                return $existingPending;
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'payment_method' => 'saspay_checkout',
                'amount' => $amount,
                'currency' => config('garagepro.currency', 'XAF'),
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => (string) Str::uuid(),
                'reference_code' => 'PAY-'.strtoupper(Str::random(8)),
            ]);

            try {
                $data = $this->saspayClient->createCheckoutSession([
                    'amount' => $amount,
                    'currency' => $payment->currency,
                    'description' => $description,
                    'customer_email' => $customerEmail,
                    'customer_name' => $customerName,
                    'return_url' => str_replace('__PAYMENT_ID__', (string) $payment->id, $returnUrl),
                ], $payment->idempotency_key);

                $payment->update([
                    'provider_payment_id' => $data['id'] ?? $data['payment_id'] ?? $data['session_id'] ?? null,
                    'checkout_url' => $data['checkout_url'] ?? $data['url'] ?? $data['payment_url'] ?? null,
                    'raw_response' => $this->sanitizeResponse($data),
                ]);
            } catch (\Throwable $e) {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => 'Service de paiement momentanément indisponible, réessayez dans quelques minutes.',
                ]);

                Log::error('Échec initiation paiement SaSPay', [
                    'reference_code' => $payment->reference_code,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $payment->fresh();
        });
    }

    public function initiateSaspaySoftpay(
        Tenant $tenant,
        Model $payable,
        int $amount,
        string $country,
        string $network,
        array $customer,
        ?int $agencyId = null
    ): Payment {
        $lockKey = 'payment_lock:'.$payable->getMorphClass().':'.$payable->getKey();

        return Cache::lock($lockKey, 10)->block(5, function () use (
            $tenant,
            $payable,
            $amount,
            $country,
            $network,
            $customer,
            $agencyId
        ): Payment {
            $existingPending = Payment::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('payable_type', $payable->getMorphClass())
                ->where('payable_id', $payable->getKey())
                ->where('amount', $amount)
                ->where('status', Payment::STATUS_PENDING)
                ->latest('id')
                ->first();

            if ($existingPending) {
                return $existingPending;
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'payment_method' => 'saspay_softpay',
                'amount' => $amount,
                'currency' => config('garagepro.currency', 'XAF'),
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => (string) Str::uuid(),
                'reference_code' => 'PAY-'.strtoupper(Str::random(8)),
            ]);

            try {
                $data = $this->saspayClient->createSoftpay([
                    'amount' => $amount,
                    'currency' => $payment->currency,
                    'country' => $country,
                    'network' => $network,
                    'customer' => $customer,
                ], $payment->idempotency_key);

                $payment->update([
                    'provider_payment_id' => $data['id'] ?? $data['payment_id'] ?? null,
                    'checkout_url' => ! empty($data['checkout_url']) ? $data['checkout_url'] : null,
                    'raw_response' => $this->sanitizeResponse($data),
                ]);
            } catch (\Throwable $e) {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => 'Service de paiement momentanément indisponible, réessayez dans quelques minutes.',
                ]);

                Log::error('Échec Softpay SaSPay', [
                    'reference_code' => $payment->reference_code,
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $payment->fresh();
        });
    }

    /**
     * Marque un paiement réussi de façon strictement idempotente et transactionnelle.
     */
    public function markPaymentSucceeded(Payment $payment, array $providerData = []): Payment
    {
        return DB::transaction(function () use ($payment, $providerData): Payment {
            $locked = Payment::withoutTenantScope()->where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payment::STATUS_SUCCESS) {
                return $locked;
            }

            $locked->update([
                'status' => Payment::STATUS_SUCCESS,
                'paid_at' => now(),
                'raw_response' => ! empty($providerData) ? $this->sanitizeResponse($providerData) : $locked->raw_response,
            ]);

            if ($locked->payable_type === Tenant::class) {
                $tenant = Tenant::where('id', $locked->payable_id)->lockForUpdate()->firstOrFail();
                if ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture()) {
                    $baseDate = $tenant->subscription_ends_at;
                } elseif ($tenant->status === Tenant::STATUS_TRIALING && $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
                    $baseDate = $tenant->trial_ends_at;
                } else {
                    $baseDate = now();
                }

                $tenant->update([
                    'status' => Tenant::STATUS_ACTIVE,
                    'subscription_ends_at' => $baseDate->copy()->addMonth(),
                    'grace_ends_at' => null,
                ]);

                \App\Models\AffiliateReferral::settleOnFirstSubscriptionPayment($tenant);
            } elseif ($locked->payable_type === Invoice::class) {
                $invoice = Invoice::withoutTenantScope()->where('id', $locked->payable_id)->lockForUpdate()->firstOrFail();
                $newPaid = (int) $invoice->paid_amount + (int) $locked->amount;
                $dueTotal = max(0, (int) $invoice->total_ttc - (int) $invoice->deposit_deducted);

                $invoice->update([
                    'paid_amount' => $newPaid,
                    'status' => $newPaid >= $dueTotal ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL,
                ]);

                if ($locked->amount > 0) {
                    app(AccountingService::class)->recordPaymentEntry($locked);
                }
            }

            AuditLog::record(
                action: 'payment.succeeded',
                subject: $locked,
                tenantId: $locked->tenant_id,
                metadata: ['amount' => $locked->amount, 'reference_code' => $locked->reference_code]
            );

            return $locked->fresh();
        });
    }

    public function recordOfflinePayment(Invoice $invoice, int $amount, string $method, User $actor): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $actor): Payment {
            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'agency_id' => $invoice->agency_id,
                'payable_type' => Invoice::class,
                'payable_id' => $invoice->id,
                'payment_method' => $method,
                'amount' => $amount,
                'currency' => config('garagepro.currency', 'XAF'),
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => (string) Str::uuid(),
                'reference_code' => 'PAY-'.strtoupper(Str::random(8)),
            ]);

            $succeeded = $this->markPaymentSucceeded($payment);

            AuditLog::record(
                action: 'payment.offline_recorded',
                subject: $succeeded,
                actor: $actor,
                tenantId: $invoice->tenant_id,
                metadata: ['method' => $method, 'amount' => $amount]
            );

            return $succeeded;
        });
    }

    private function sanitizeResponse(array $data): array
    {
        unset($data['secret_key'], $data['token'], $data['authorization']);

        return $data;
    }
}
