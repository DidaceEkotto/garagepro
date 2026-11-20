<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GreenApiService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.greenapi.url'))
            && ! empty(config('services.greenapi.id_instance'))
            && ! empty(config('services.greenapi.api_token_instance'));
    }

    /**
     * Envoie un message WhatsApp via Green-API en utilisant exclusivement les clés .env.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendMessage(string $phone, string $message): array
    {
        $apiUrl = rtrim((string) config('services.greenapi.url'), '/');
        $idInstance = trim((string) config('services.greenapi.id_instance'));
        $token = trim((string) config('services.greenapi.api_token_instance'));

        if ($apiUrl === '' || $idInstance === '' || $token === '') {
            return [
                'ok' => false,
                'error' => 'La configuration WhatsApp (Green-API) est manquante dans le fichier .env.',
            ];
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (empty($digits) || strlen($digits) < 6) {
            return [
                'ok' => false,
                'error' => 'Le numéro WhatsApp fourni est invalide.',
            ];
        }

        $endpoint = "{$apiUrl}/waInstance{$idInstance}/sendMessage/{$token}";

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->post($endpoint, [
                    'chatId' => "{$digits}@c.us",
                    'message' => $message,
                    'typingTime' => 1000,
                ]);

            if ($response->successful() && ! empty($response->json('idMessage'))) {
                return ['ok' => true, 'error' => null];
            }

            Log::warning('Green-API sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'error' => "Échec de l'envoi WhatsApp (code {$response->status()}). Vérifiez le numéro ou réessayez.",
            ];
        } catch (\Throwable $e) {
            Log::error('Green-API exception', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'error' => 'Service WhatsApp momentanément indisponible. Veuillez réessayer.',
            ];
        }
    }
}
