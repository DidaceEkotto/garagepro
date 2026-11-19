<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\PaymentService;
use App\Services\SaspayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Réception des webhooks SaSPay (sans CSRF, protégé par signature HMAC,
 * vérification /verify/, rate limiting et déduplication via webhook_events).
 */
class SaspayWebhookController extends Controller
{
    public function handle(
        Request $request,
        SaspayClient $saspayClient,
        PaymentService $paymentService
    ): JsonResponse {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Saspay-Signature') ?? $request->header('X-Signature');

        $signatureValid = $saspayClient->verifyWebhookSignature($rawBody, $signature);

        $payload = $request->all();
        $eventId = (string) ($payload['event_id'] ?? $payload['id'] ?? '');
        $eventType = (string) ($payload['event'] ?? $payload['type'] ?? 'payment.updated');
        $providerPaymentId = (string) ($payload['data']['id'] ?? $payload['data']['payment_id'] ?? $payload['payment_id'] ?? '');

        if ($eventId === '' || $providerPaymentId === '') {
            return response()->json(['message' => 'Payload webhook invalide.'], 400);
        }

        // En cas de doute sur la signature, on re-vérifie obligatoirement côté serveur via /verify/.
        if (! $signatureValid) {
            try {
                $verifiedData = $saspayClient->verifyPayment($providerPaymentId);
                if (empty($verifiedData)) {
                    AuditLog::record('webhook.saspay_invalid_signature', request: $request);

                    return response()->json(['message' => 'Signature invalide.'], 401);
                }
                $payload['data'] = $verifiedData;
            } catch (\Throwable) {
                AuditLog::record('webhook.saspay_invalid_signature', request: $request);

                return response()->json(['message' => 'Signature invalide.'], 401);
            }
        }

        // Déduplication atomique : un webhook reçu deux fois n'est traité qu'une seule fois.
        $alreadyProcessed = DB::transaction(function () use ($eventId, $eventType, $payload, $providerPaymentId, $paymentService): bool {
            $event = WebhookEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'provider' => 'saspay',
                    'event_type' => $eventType,
                    'payload' => $payload,
                ]
            );

            if ($event->processed_at !== null) {
                return true;
            }

            $payment = Payment::withoutTenantScope()
                ->where('provider_payment_id', $providerPaymentId)
                ->first();

            if ($payment) {
                $remoteStatus = strtoupper((string) ($payload['data']['status'] ?? ''));
                if ($remoteStatus === 'SUCCESS') {
                    $paymentService->markPaymentSucceeded($payment, (array) ($payload['data'] ?? []));
                } elseif (in_array($remoteStatus, ['FAILED', 'CANCELLED'], true)) {
                    $payment->update([
                        'status' => strtolower($remoteStatus),
                        'failure_reason' => 'Le paiement a échoué : votre compte n\'a pas été débité, vous pouvez réessayer.',
                    ]);
                }
            } else {
                Log::warning('Webhook SaSPay reçu pour un paiement inconnu', [
                    'provider_payment_id' => $providerPaymentId,
                    'event_id' => $eventId,
                ]);
            }

            $event->update(['processed_at' => now()]);

            return false;
        });

        return response()->json([
            'status' => $alreadyProcessed ? 'already_processed' : 'processed',
        ]);
    }
}
