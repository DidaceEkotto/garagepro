<?php

namespace App\Services;

use App\Models\AiDiagnosticMessage;
use Illuminate\Support\Str;

/**
 * Base de connaissances auto-apprenante MboaCar IA Auto.
 *
 * Avant chaque appel Gemini, recherche si un problème automobile similaire
 * a déjà été résolu et validé en atelier (is_resolved = true, is_approved_for_knowledge = true).
 * Aucune donnée privée d'un garage (nom, client, plaque) n'est jamais exposée à un autre garage :
 * seule la solution technique anonymisée est réutilisée.
 */
class AiKnowledgeService
{
    private const DIRECT_MATCH_THRESHOLD = 0.65;

    private const LEARNING_HINT_THRESHOLD = 0.30;

    /** @var array<int, string> */
    private const STOPWORDS = [
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'au', 'aux', 'et', 'ou', 'mais',
        'donc', 'car', 'ni', 'or', 'je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'elles',
        'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'son', 'sa', 'ses', 'notre', 'votre', 'leur',
        'ce', 'cet', 'cette', 'ces', 'qui', 'que', 'quoi', 'dont', 'est', 'sont', 'etre', 'avoir',
        'fait', 'faire', 'fais', 'sur', 'dans', 'par', 'pour', 'avec', 'sans', 'sous', 'vers',
        'chez', 'plus', 'tres', 'bien', 'mal', 'pas', 'ne', 'n', 'ai', 'as', 'a', 'avons', 'avez',
        'ont', 'suis', 'es', 'sommes', 'etes', 'bonjour', 'salut', 'merci', 'svp', 'sil', 'plait',
        'aide', 'aider', 'question', 'probleme', 'souci', 'voiture', 'vehicule', 'auto', 'comment',
        'pourquoi', 'quand', 'quelle', 'quelles', 'quel', 'quels', 'quoi', 'commencer', 'verifier',
        'causes', 'probables', 'etape', 'diagnostic', 'defaut', 'code',
    ];

    /**
     * Recherche dans les diagnostics résolus en base de données.
     *
     * @return array{
     *     direct_match: ?array{message_id: int, reply: string, score: float},
     *     learning_hints: array<int, string>
     * }
     */
    public function lookup(string $prompt): array
    {
        $prompt = trim($prompt);
        if (mb_strlen($prompt) < 6) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        $queryTokens = $this->extractTokens($prompt);
        $queryObd = $this->extractObdCodes($prompt);

        if ($queryTokens === [] && $queryObd === []) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        // Récupérer les réponses résolues et approuvées pour la base de connaissances (les 250 plus récentes)
        $resolvedReplies = AiDiagnosticMessage::withoutTenantScope()
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->where('is_off_topic', false)
            ->where('is_resolved', true)
            ->where('is_approved_for_knowledge', true)
            ->whereNull('matched_from_message_id')
            ->orderByDesc('reuse_count')
            ->orderByDesc('id')
            ->take(250)
            ->get(['id', 'conversation_id', 'content', 'reuse_count']);

        if ($resolvedReplies->isEmpty()) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        // Charger les messages utilisateur des conversations concernées en une seule requête
        $convIds = $resolvedReplies->pluck('conversation_id')->unique()->values()->all();
        $userMessagesByConv = AiDiagnosticMessage::withoutTenantScope()
            ->whereIn('conversation_id', $convIds)
            ->where('role', AiDiagnosticMessage::ROLE_USER)
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'content'])
            ->groupBy('conversation_id');

        $scored = [];

        foreach ($resolvedReplies as $reply) {
            $convUserMsgs = $userMessagesByConv->get($reply->conversation_id);
            if (! $convUserMsgs) {
                continue;
            }

            // Trouver le prompt utilisateur immédiatement précédent cette réponse IA
            $userPromptMsg = $convUserMsgs->where('id', '<', $reply->id)->last();
            if (! $userPromptMsg) {
                continue;
            }

            $candText = trim((string) $userPromptMsg->content);
            if ($candText === '') {
                continue;
            }

            $candTokens = $this->extractTokens($candText);
            $candObd = $this->extractObdCodes($candText);

            // Règle de précision OBD-II : si le nouveau prompt mentionne un code OBD,
            // le candidat doit contenir au moins un code OBD commun pour être un match direct.
            $obdBonus = 0.0;
            $obdCompatible = true;
            if ($queryObd !== []) {
                $commonObd = array_intersect($queryObd, $candObd);
                if ($commonObd === []) {
                    $obdCompatible = false;
                } else {
                    $obdBonus = 0.35;
                }
            }

            $tokenScore = $this->computeTokenSimilarity($queryTokens, $candTokens);
            $score = min(1.0, $tokenScore + $obdBonus);

            if ($score >= self::LEARNING_HINT_THRESHOLD) {
                $scored[] = [
                    'message_id' => $reply->id,
                    'prompt' => $candText,
                    'reply' => $reply->content,
                    'score' => $score,
                    'obd_compatible' => $obdCompatible,
                ];
            }
        }

        if ($scored === []) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $best = $scored[0];
        $directMatch = null;
        if ($best['obd_compatible'] && $best['score'] >= self::DIRECT_MATCH_THRESHOLD) {
            $directMatch = [
                'message_id' => $best['message_id'],
                'reply' => $best['reply'],
                'score' => round($best['score'], 2),
            ];
        }

        // Extraire jusqu'à 3 cas résolus anonymisés comme mémoire d'apprentissage pour Gemini
        $learningHints = [];
        foreach (array_slice($scored, 0, 3) as $item) {
            $learningHints[] = 'Symptôme résolu en atelier : « '.Str::limit(preg_replace('/\s+/', ' ', $item['prompt']), 120)
                .' » → Solution validée : '.Str::limit(preg_replace('/\s+/', ' ', $item['reply']), 280);
        }

        return [
            'direct_match' => $directMatch,
            'learning_hints' => $learningHints,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractObdCodes(string $text): array
    {
        preg_match_all('/\b[PBCU][0-9]{4}\b/i', $text, $matches);

        return array_values(array_unique(array_map('strtoupper', $matches[0] ?? [])));
    }

    /**
     * @return array<int, string>
     */
    private function extractTokens(string $text): array
    {
        $ascii = strtolower(Str::ascii($text));
        $cleaned = preg_replace('/[^a-z0-9\s]/', ' ', $ascii);
        $parts = preg_split('/\s+/', trim((string) $cleaned)) ?: [];

        $tokens = [];
        foreach ($parts as $word) {
            if (strlen($word) < 3) {
                continue;
            }
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function computeTokenSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));

        if ($union === []) {
            return 0.0;
        }

        $jaccard = count($intersection) / count($union);
        $containment = count($intersection) / min(count($a), count($b));

        // Combine Jaccard et recouvrement pour tolérer les formulations légèrement plus courtes ou longues
        return ($jaccard * 0.55) + ($containment * 0.45);
    }
}
