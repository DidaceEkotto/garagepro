<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assistant IA spécialisé exclusivement dans le diagnostic et la mécanique automobile via Gemini API.
 * Supporte texte, photos (Vision), messages vocaux (Audio) — vidéos strictement interdites —
 * historique du véhicule, résumé vulgarisé pour client et bascule automatique entre modèles.
 */
class GeminiDiagnosticService
{
    private const OFF_TOPIC_TAG = '[HORS_SUJET]';

    private const FALLBACK_MODELS = [
        'gemini-flash-lite-latest',
        'gemini-3.1-flash-lite',
        'gemini-3.5-flash',
        'gemini-3.8-flash',
    ];

    public function isConfigured(): bool
    {
        return trim((string) config('services.gemini.api_key', '')) !== '';
    }

    public function model(): string
    {
        return trim((string) config('services.gemini.model', 'gemini-flash-lite-latest')) ?: 'gemini-flash-lite-latest';
    }

    /**
     * Envoie le prompt (+ photos/audio facultatifs) et l'historique récent à Gemini
     * avec garde-fou strict 100 % automobile et fallback automatique.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, array{mime_type: string, data: string}>  $mediaParts
     * @param  array<int, string>  $vehicleHistoryLines
     * @return array{ok: bool, reply: string, is_off_topic: bool, model: string, tokens_used: ?int, error: ?string}
     */
    public function diagnose(
        string $prompt,
        array $history = [],
        ?Vehicle $vehicle = null,
        array $mediaParts = [],
        array $vehicleHistoryLines = [],
        array $knowledgeHints = []
    ): array {
        $systemPrompt = $this->buildSystemInstruction($vehicle, $vehicleHistoryLines, $knowledgeHints);

        // Économie de tokens : n'envoyer que les 4 derniers messages en tronquant les anciennes réponses IA
        $contents = [];
        foreach (array_slice($history, -4) as $msg) {
            $isAssistant = ($msg['role'] ?? 'user') === 'assistant';
            $geminiRole = $isAssistant ? 'model' : 'user';
            $text = trim((string) ($msg['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($isAssistant && mb_strlen($text) > 400) {
                $text = mb_substr($text, 0, 400).'…';
            }
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $text]],
            ];
        }

        $userParts = [];
        if (trim($prompt) !== '') {
            $userParts[] = ['text' => $prompt];
        } elseif ($mediaParts !== []) {
            $userParts[] = ['text' => 'Analyse ces éléments (photo(s) ou message vocal d\'atelier) dans le cadre d\'un diagnostic automobile et donne tes conclusions structurées.'];
        }

        foreach ($mediaParts as $media) {
            $mime = strtolower(trim((string) ($media['mime_type'] ?? '')));
            $data = (string) ($media['data'] ?? '');
            // Sécurité : les vidéos sont strictement interdites
            if ($mime === '' || $data === '' || str_starts_with($mime, 'video/')) {
                continue;
            }
            if (! str_starts_with($mime, 'image/') && ! str_starts_with($mime, 'audio/')) {
                continue;
            }
            $userParts[] = [
                'inlineData' => [
                    'mimeType' => $mime,
                    'data' => $data,
                ],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $userParts,
        ];

        return $this->callGeminiWithFallback($systemPrompt, $contents, 700, true);
    }

    /**
     * Reformule un diagnostic technique d'atelier en un message clair, poli et rassurant pour le client (WhatsApp).
     *
     * @return array{ok: bool, reply: string, is_off_topic: bool, model: string, tokens_used: ?int, error: ?string}
     */
    public function summarizeForClient(
        string $technicalDiagnostic,
        ?Vehicle $vehicle = null,
        ?string $customerName = null,
        ?string $garageName = null
    ): array {
        $garage = $garageName ?: 'notre atelier';
        $client = $customerName ?: 'cher client';
        $vehDesc = $vehicle
            ? trim("{$vehicle->brand} {$vehicle->model} ({$vehicle->license_plate})")
            : 'votre véhicule';

        $systemPrompt = "Tu es un conseiller client d'un garage automobile ({$garage}). "
            ."Reformule le diagnostic technique fourni en un message WhatsApp court (max 8 lignes), clair, poli, rassurant et sans jargon technique complexe, destiné à {$client} pour {$vehDesc}. "
            .'Ne promets aucun prix exact. Termine en invitant le client à nous confirmer son accord ou à nous contacter.';

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => "Diagnostic technique de l'atelier :\n\n{$technicalDiagnostic}"],
                ],
            ],
        ];

        return $this->callGeminiWithFallback($systemPrompt, $contents, 500, false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{ok: bool, reply: string, is_off_topic: bool, model: string, tokens_used: ?int, error: ?string}
     */
    private function callGeminiWithFallback(
        string $systemPrompt,
        array $contents,
        int $maxOutputTokens,
        bool $checkOffTopic
    ): array {
        $primaryModel = $this->model();
        $apiKey = trim((string) config('services.gemini.api_key', ''));

        if ($apiKey === '') {
            return [
                'ok' => false,
                'reply' => "La clé API Gemini (GEMINI_API_KEY) n'est pas configurée sur le serveur.",
                'is_off_topic' => false,
                'model' => $primaryModel,
                'tokens_used' => null,
                'error' => 'missing_api_key',
            ];
        }

        $allModels = array_values(array_unique(array_merge([$primaryModel], self::FALLBACK_MODELS)));
        $healthyModels = array_values(array_filter($allModels, fn (string $m) => ! Cache::has("mboacar_gemini_cooldown_{$m}")));
        $modelsToTry = $healthyModels !== [] ? $healthyModels : $allModels;

        $lastError = 'unknown_error';
        $lastModel = $primaryModel;

        foreach ($modelsToTry as $idx => $candidateModel) {
            $lastModel = $candidateModel;
            $hasMoreFallbacks = $idx < count($modelsToTry) - 1;
            $endpoint = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                rawurlencode($candidateModel)
            );

            try {
                $response = Http::connectTimeout(6)
                    ->timeout($hasMoreFallbacks ? 14 : 25)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                    ])
                    ->post($endpoint, [
                        'systemInstruction' => [
                            'parts' => [
                                ['text' => $systemPrompt],
                            ],
                        ],
                        'contents' => $contents,
                        'generationConfig' => [
                            'temperature' => 0.3,
                            'maxOutputTokens' => $maxOutputTokens,
                        ],
                    ]);

                if (! $response->successful()) {
                    $status = $response->status();
                    $errorMsg = $response->json('error.message') ?? ("HTTP {$status}");
                    $lastError = $errorMsg;

                    Log::warning('Gemini API attempt failed, trying fallback', [
                        'model' => $candidateModel,
                        'status' => $status,
                        'error' => $errorMsg,
                    ]);

                    if (in_array($status, [401, 403], true)) {
                        break;
                    }

                    if (in_array($status, [404, 429, 500, 502, 503, 504], true)) {
                        Cache::put("mboacar_gemini_cooldown_{$candidateModel}", 1, 300);
                    }

                    continue;
                }

                $rawText = $this->extractResponseText($response->json());
                if ($rawText === '') {
                    $lastError = 'empty_response';
                    Log::warning('Gemini API returned empty text, trying fallback', ['model' => $candidateModel]);

                    continue;
                }

                $isOffTopic = $checkOffTopic
                    && (str_starts_with($rawText, self::OFF_TOPIC_TAG) || str_contains($rawText, self::OFF_TOPIC_TAG));

                $cleanReply = $checkOffTopic
                    ? trim(str_replace(self::OFF_TOPIC_TAG, '', $rawText))
                    : $rawText;

                if ($cleanReply === '') {
                    $cleanReply = 'Je suis MboaCar IA, un assistant strictement spécialisé dans le diagnostic et la mécanique automobile. Veuillez me poser uniquement des questions liées à l\'automobile.';
                }

                return [
                    'ok' => true,
                    'reply' => $cleanReply,
                    'is_off_topic' => $isOffTopic,
                    'model' => $candidateModel,
                    'tokens_used' => data_get($response->json(), 'usageMetadata.totalTokenCount'),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Cache::put("mboacar_gemini_cooldown_{$candidateModel}", 1, 300);
                Log::warning('Gemini API exception, trying fallback', [
                    'model' => $candidateModel,
                    'error' => $lastError,
                ]);
            }
        }

        return [
            'ok' => false,
            'reply' => "Impossible d'obtenir une réponse de l'assistant IA pour le moment ({$lastError}). Veuillez réessayer.",
            'is_off_topic' => false,
            'model' => $lastModel,
            'tokens_used' => null,
            'error' => $lastError,
        ];
    }

    /**
     * Extrait le texte final en ignorant les éventuels blocs internes de raisonnement (thought).
     */
    private function extractResponseText(?array $payload): string
    {
        $parts = data_get($payload, 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part) || ! empty($part['thought'])) {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param  array<int, string>  $vehicleHistoryLines
     * @param  array<int, string>  $knowledgeHints
     */
    private function buildSystemInstruction(?Vehicle $vehicle, array $vehicleHistoryLines = [], array $knowledgeHints = []): string
    {
        $instruction = <<<'PROMPT'
Tu es MboaCar IA Auto, expert diagnostic/mécanique/OBD-II/électricité/carrosserie automobile.
RÈGLE STRICTE : Réponds UNIQUEMENT aux questions automobiles (texte, photo ou vocal). Si hors-sujet (cuisine, sport, politique, code, santé, blague, etc.), commence EXACTEMENT par [HORS_SUJET] suivi d'une phrase polie courte.
STYLE CONCIS ATELIER (sans salutations ni introduction) :
- Signification courte
- Causes probables (3-4 puces max)
- Contrôles atelier (3-4 puces max)
- Pièces & Urgence (Sécurité/Urgent/Modéré)
PROMPT;

        if ($vehicle) {
            $details = array_filter([
                'Immatriculation : '.$vehicle->license_plate,
                trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')) !== '' ? 'Modèle : '.trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')) : null,
                $vehicle->year ? 'Année : '.$vehicle->year : null,
                $vehicle->fuel_type ? 'Carburant : '.$vehicle->fuel_type : null,
                $vehicle->transmission ? 'Boîte : '.$vehicle->transmission : null,
                $vehicle->mileage ? 'Kilométrage : '.number_format((int) $vehicle->mileage, 0, ',', ' ').' km' : null,
                $vehicle->vin ? 'VIN : '.$vehicle->vin : null,
            ]);

            if ($details !== []) {
                $instruction .= "\n\nCONTEXTE DU VÉHICULE SÉLECTIONNÉ PAR LE GARAGISTE :\n- ".implode("\n- ", $details);
            }
        }

        if ($vehicleHistoryLines !== []) {
            $instruction .= "\n\nHISTORIQUE RÉCENT DE CE VÉHICULE DANS CE GARAGE (tiens-en compte pour repérer les pannes récurrentes) :\n- "
                .implode("\n- ", $vehicleHistoryLines);
        }

        if ($knowledgeHints !== []) {
            $instruction .= "\n\nRETOURS D'EXPÉRIENCE RÉSOLUS EN ATELIER MBOACAR (inspire-toi de ces solutions déjà validées sur le terrain si pertinentes, sans jamais mentionner d'autres garages) :\n- "
                .implode("\n- ", $knowledgeHints);
        }

        return $instruction;
    }
}
