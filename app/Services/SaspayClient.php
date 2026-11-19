<?php

namespace App\Services;

use App\Support\SecretMasker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client HTTP officiel pour l'API SaSPay.
 * La clé secrète n'est jamais exposée aux logs, aux réponses JSON ni au front.
 */
class SaspayClient
{
    private function client(?string $idempotencyKey = null): PendingRequest
    {
        $secretKey = config('saspay.secret_key');
        if (empty($secretKey)) {
            throw new RuntimeException('La clé API SaSPay n\'est pas configurée.');
        }

        $baseUrl = rtrim((string) config('saspay.base_url', 'https://api.saspay.me/api/v1'), '/');
        if (! str_ends_with($baseUrl, '/api/v1')) {
            $baseUrl .= '/api/v1';
        }

        $request = Http::baseUrl($baseUrl)
            ->withToken($secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('saspay.timeout', 15))
            ->retry((int) config('saspay.retry_times', 2), (int) config('saspay.retry_sleep_ms', 300), throw: false);

        if ($idempotencyKey !== null) {
            $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $request;
    }

    /**
     * Crée une session de paiement hébergée : POST {base}/checkout-sessions/
     */
    public function createCheckoutSession(array $payload, string $idempotencyKey): array
    {
        $response = $this->client($idempotencyKey)->post('/checkout-sessions/', $payload);

        if ($response->failed()) {
            $this->logApiError('checkout-sessions', $response->status());
            throw new RuntimeException('Service de paiement momentanément indisponible, réessayez dans quelques minutes.');
        }

        $json = (array) $response->json();

        return (array) ($json['data'] ?? $json);
    }

    /**
     * Déclenche un paiement direct Softpay (Mobile Money) : POST {base}/payments/softpay/
     */
    public function createSoftpay(array $payload, string $idempotencyKey): array
    {
        $response = $this->client($idempotencyKey)->post('/payments/softpay/', $payload);

        if ($response->failed()) {
            $this->logApiError('payments/softpay', $response->status());
            throw new RuntimeException('Service de paiement momentanément indisponible, réessayez dans quelques minutes.');
        }

        return (array) $response->json('data', []);
    }

    /**
     * Vérifie le statut réel d'un paiement côté serveur : GET {base}/payments/{id}/verify/
     */
    public function verifyPayment(string $providerPaymentId): array
    {
        $response = $this->client()->get("/payments/{$providerPaymentId}/verify/");

        if ($response->failed()) {
            $this->logApiError('payments/verify', $response->status());
            throw new RuntimeException('Impossible de vérifier le statut du paiement auprès de SaSPay.');
        }

        return (array) $response->json('data', []);
    }

    /**
     * Vérifie la signature HMAC du webhook SaSPay.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $webhookSecret = config('saspay.webhook_secret');
        if (empty($webhookSecret) || empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, (string) $webhookSecret);
        $cleanSignature = preg_replace('/^sha256=/', '', $signature) ?? '';

        return hash_equals($expected, $cleanSignature);
    }

    private function logApiError(string $endpoint, int $status): void
    {
        Log::error('Erreur API SaSPay', [
            'endpoint' => $endpoint,
            'http_status' => $status,
            'key_prefix' => SecretMasker::mask(config('saspay.secret_key')),
        ]);
    }
}
