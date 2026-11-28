<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AppointmentLink;
use App\Models\Tenant;
use App\Models\WhatsappBotLog;
use App\Services\GreenApiService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Réceptionniste IA WhatsApp 24h/24 pour chaque garage :
 * répond aux demandes de tarifs / disponibilités clients et génère un lien RDV /rdv/{token} en 1 clic.
 */
class WhatsappBotController extends Controller
{
    public function webhook(Request $request, string $tenantSlug, GreenApiService $greenApi): JsonResponse
    {
        $tenant = Tenant::where('slug', $tenantSlug)
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIALING])
            ->firstOrFail();

        if (! $tenant->whatsapp_bot_enabled || empty($tenant->whatsapp_bot_secret)) {
            return response()->json(['ok' => false, 'error' => 'Bot WhatsApp désactivé.'], 403);
        }

        $providedSecret = (string) ($request->header('X-Bot-Secret') ?? $request->input('secret', ''));
        if ($providedSecret === '' || ! hash_equals((string) $tenant->whatsapp_bot_secret, $providedSecret)) {
            return response()->json(['ok' => false, 'error' => 'Secret webhook invalide.'], 403);
        }

        $validated = $request->validate([
            'sender_phone' => ['required', 'string', 'min:6', 'max:40'],
            'message' => ['required', 'string', 'min:1', 'max:1500'],
        ]);

        $result = $this->generateReply($tenant, $validated['sender_phone'], $validated['message']);

        if (! empty($validated['sender_phone']) && $greenApi->isConfigured()) {
            try {
                $greenApi->sendMessage($validated['sender_phone'], $result['reply']);
            } catch (\Throwable) {
                // non-blocking
            }
        }

        return response()->json([
            'ok' => true,
            'reply' => $result['reply'],
            'rdv_link_url' => $result['rdv_link_url'],
        ]);
    }

    public function simulate(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1500'],
            'sender_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $tenant = $tenantContext->getTenant();
        $result = $this->generateReply(
            $tenant,
            $validated['sender_phone'] ?: '+237699000000',
            $validated['message']
        );

        return response()->json([
            'ok' => true,
            'reply' => $result['reply'],
            'rdv_link_url' => $result['rdv_link_url'],
        ]);
    }

    /**
     * @return array{reply: string, rdv_link_url: ?string}
     */
    private function generateReply(Tenant $tenant, string $senderPhone, string $incoming): array
    {
        $wantsBooking = (bool) preg_match('/\b(rdv|rendez[\s\-]?vous|place|disponible|dispo|demain|venir|passer|réserver|reserver|book|appointment)\b/iu', $incoming);
        $rdvUrl = null;

        if ($wantsBooking) {
            $agencyId = Agency::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->value('id');

            $link = AppointmentLink::create([
                'tenant_id' => $tenant->id,
                'agency_id' => $agencyId,
                'token' => Str::random(32),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);
            $rdvUrl = url("/rdv/{$link->token}");
        }

        $catalog = trim((string) ($tenant->whatsapp_bot_catalog ?? ''));
        $apiKey = (string) config('services.gemini.api_key');
        $reply = null;

        if ($apiKey !== '') {
            try {
                $model = (string) config('services.gemini.model', 'gemini-flash-lite-latest');
                $system = "Tu es le réceptionniste WhatsApp officiel 24h/24 du garage « {$tenant->name} » ({$tenant->city}). "
                    ."Réponds de façon chaleureuse, concise (max 5 lignes) dans la langue du client. "
                    ."Si la demande n'a rien à voir avec l'automobile, dis-le poliment en 1 phrase. "
                    .($catalog !== '' ? "\nTarifs & horaires du garage :\n{$catalog}" : '')
                    .($rdvUrl ? "\nLien direct de prise de rendez-vous à inclure : {$rdvUrl}" : '');

                $resp = Http::timeout(20)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                    [
                        'system_instruction' => ['parts' => [['text' => $system]]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => $incoming]]]],
                        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 280],
                    ]
                );

                if ($resp->successful()) {
                    $reply = trim((string) ($resp->json('candidates.0.content.parts.0.text') ?? ''));
                }
            } catch (\Throwable) {
                $reply = null;
            }
        }

        if (! $reply) {
            $reply = "Bonjour ! Merci d'avoir contacté *{$tenant->name}*.\n"
                ."Nous avons bien reçu votre message et notre chef d'atelier vous confirme le tarif exact très rapidement.";
            if ($rdvUrl) {
                $reply .= "\n\nVous pouvez déjà réserver votre créneau ici : {$rdvUrl}";
            }
        } elseif ($rdvUrl && ! str_contains($reply, $rdvUrl)) {
            $reply .= "\n\nRéserver votre rendez-vous en 1 clic : {$rdvUrl}";
        }

        WhatsappBotLog::create([
            'tenant_id' => $tenant->id,
            'sender_phone' => $senderPhone,
            'incoming_message' => $incoming,
            'bot_reply' => $reply,
            'rdv_link_url' => $rdvUrl,
        ]);

        return [
            'reply' => $reply,
            'rdv_link_url' => $rdvUrl,
        ];
    }
}
