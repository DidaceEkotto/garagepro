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
     * Retourne l'URL publique HTTPS à utiliser dans les liens WhatsApp
     * (WhatsApp ne rend jamais cliquables les liens localhost / 127.0.0.1).
     */
    public static function publicBaseUrl(): string
    {
        $configured = trim((string) config('garagepro.whatsapp_public_url', ''));
        if ($configured !== '') {
            if (! preg_match('#^https?://#i', $configured)) {
                $configured = 'https://'.ltrim($configured, '/');
            }

            return rtrim($configured, '/');
        }

        $appUrl = trim((string) config('app.url', ''));
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $isLocalHost = $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '127.0.0.1'
            || $host === '::1'
            || ! str_contains($host, '.');

        if (! $isLocalHost) {
            $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) ?: 'https';
            $port = parse_url($appUrl, PHP_URL_PORT);
            $portPart = ($port && ! in_array((int) $port, [80, 443], true)) ? ":{$port}" : '';

            return rtrim("{$scheme}://{$host}{$portPart}", '/');
        }

        $baseDomain = trim((string) config('garagepro.tenant_base_domain', 'mboacar.com'));

        return 'https://'.ltrim($baseDomain, '/');
    }

    /**
     * Formate un message WhatsApp pour garantir que chaque lien soit 100 % cliquable
     * et ouvre directement le navigateur (HTTPS, domaine public avec TLD, sans markdown collé).
     */
    public static function normalizeClickableLinks(string $message): string
    {
        $baseDomain = trim((string) config('garagepro.tenant_base_domain', 'mboacar.com'));
        $publicBase = self::publicBaseUrl();
        $escapedBaseDomain = preg_quote($baseDomain, '#');

        // 1. Convertir les liens Markdown [Libellé](url) en "Libellé :\nurl"
        $message = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/i',
            fn (array $m) => trim($m[1])." :\n".$m[2],
            $message
        );

        // 2. Retirer le formatage WhatsApp ou délimiteurs autour des URL (*url*, _url_, `url`, ~url~, <url>, (url))
        $message = (string) preg_replace(
            '/[\*_`~<\(](https?:\/\/[^\s\*_`~>\)]+)[\*_`~>\)]/i',
            ' $1 ',
            $message
        );

        // 3. Remplacer les sous-domaines locaux (http://garage.localhost:8000/...) par https://garage.<domaine>
        $message = (string) preg_replace(
            '#https?://([a-z0-9-]+)\.localhost(?::\d+)?#i',
            "https://$1.{$baseDomain}",
            $message
        );

        // 4. Remplacer localhost / 127.0.0.1 / [::1] par l'URL publique HTTPS (WhatsApp ignore localhost)
        $message = (string) preg_replace(
            '#https?://(?:www\.)?(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?#i',
            $publicBase,
            $message
        );

        // 5. Passer en HTTPS les liens http:// pointant vers le domaine principal ou ses sous-domaines
        $message = (string) preg_replace(
            "#http://((?:[a-z0-9-]+\\.)?{$escapedBaseDomain})#i",
            'https://$1',
            $message
        );

        // 6. Ajouter https:// devant les domaines nus (www.xxx.tld ou domaine principal) hors adresses e-mail et hors URL déjà complètes
        $message = (string) preg_replace(
            "#(?<![@:/\\w.-])((?:www\\.[a-z0-9-]+\\.[a-z]{2,}|(?:[a-z0-9-]+\\.)?{$escapedBaseDomain})(?:/[^\\s]*)?)#i",
            'https://$1',
            $message
        );

        // 7. Séparer les URL collées à un caractère précédent (ex: "Lien:https://...")
        $message = (string) preg_replace(
            '/([^\s])(https?:\/\/)/i',
            "$1\n$2",
            $message
        );

        // 8. Détacher la ponctuation finale collée à une URL (.,;:!?) pour ne pas casser le lien
        $message = (string) preg_replace_callback(
            '#https?://[^\s]+#i',
            function (array $matches): string {
                $url = $matches[0];
                $trailing = '';
                while ($url !== '' && preg_match('/[.,;:!?\]\)]$/', $url) === 1) {
                    $trailing = substr($url, -1).$trailing;
                    $url = substr($url, 0, -1);
                }

                return $trailing !== '' ? "{$url} {$trailing}" : $url;
            },
            $message
        );

        return trim($message);
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

        $formattedMessage = self::normalizeClickableLinks($message);
        $endpoint = "{$apiUrl}/waInstance{$idInstance}/sendMessage/{$token}";

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->post($endpoint, [
                    'chatId' => "{$digits}@c.us",
                    'message' => $formattedMessage,
                    'linkPreview' => true,
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
