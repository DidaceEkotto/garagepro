<?php

namespace App\Services;

use App\Models\AiDiagnosticMessage;
use Illuminate\Support\Str;

/**
 * Moteur d'économie de tokens et base de connaissances auto-apprenante MboaCar IA Auto.
 *
 * Ordre de résolution (0 token avant tout appel Gemini) :
 * 1. Pré-filtre local PHP (salutations simples & hors-sujet évidents -> 0 token).
 * 2. Dictionnaire local OBD-II intégré (codes défauts les plus fréquents -> 0 token).
 * 3. Base de connaissances BDD :
 *    - Correspondance forte (>= 0.65) sur une réponse marquée « Résolu » (is_resolved = true).
 *    - Correspondance quasi-identique (>= 0.85) sur une réponse valide non rejetée (is_resolved !== false).
 * 4. Sinon, appel Gemini enrichi des retours d'expérience anonymisés (score >= 0.30).
 */
class AiKnowledgeService
{
    private const RESOLVED_MATCH_THRESHOLD = 0.65;

    private const GENERAL_CACHE_THRESHOLD = 0.85;

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
        'causes', 'probables', 'etape', 'diagnostic', 'defaut', 'code', 'signifie', 'veut', 'dire',
    ];

    /** @var array<int, string> */
    private const GREETINGS = [
        'bonjour', 'bonsoir', 'salut', 'hello', 'hi', 'coucou', 'merci', 'merci beaucoup',
        'ok', 'okay', 'd accord', 'daccord', 'super', 'parfait', 'compris', 'ca marche',
    ];

    /** @var array<int, string> */
    private const OFF_TOPIC_KEYWORDS = [
        'recette', 'cuisine', 'cuisiner', 'ndole', 'poulet', 'gateau', 'pizza', 'restaurant',
        'poeme', 'chanson', 'blague', 'histoire drole', 'horoscope', 'astrologie',
        'football', 'championnat', 'ligue des champions', 'ballon d or', 'score du match',
        'politique', 'election', 'president', 'ministre', 'parlement',
        'bitcoin', 'crypto', 'bourse', 'trading',
        'python', 'javascript', 'html', 'css', 'laravel', 'php', 'sql', 'algorithme',
        'mal de tete', 'fievre', 'paludisme', 'ordonnance', 'medicament', 'grossesse',
        'dissertation', 'philosophie', 'conjugaison', 'traduis moi', 'lettre de motivation',
    ];

    /** @var array<int, string> */
    private const AUTO_KEYWORDS = [
        'moteur', 'obd', 'voyant', 'frein', 'plaquette', 'disque', 'abs', 'esp', 'airbag',
        'embrayage', 'boite', 'vitesse', 'vidange', 'huile', 'filtre', 'bougie', 'bobine',
        'injecteur', 'diesel', 'essence', 'turbo', 'egr', 'fap', 'catalyseur', 'sonde', 'lambda',
        'batterie', 'alternateur', 'demarreur', 'courroie', 'distribution', 'radiateur', 'refroidissement',
        'surchauffe', 'climatisation', 'clim', 'compresseur', 'amortisseur', 'suspension', 'rotule',
        'cardan', 'roulement', 'pneu', 'direction', 'cremaillere', 'echappement', 'fumee', 'ralenti',
        'claquement', 'bruit', 'fuite', 'demarrage', 'calage', 'consommation', 'capteur', 'calculateur',
        'ecu', 'carrosserie', 'pare brise', 'phare', 'toyota', 'hyundai', 'kia', 'mercedes', 'peugeot',
        'renault', 'volkswagen', 'nissan', 'honda', 'ford', 'bmw', 'audi', 'suzuki', 'mitsubishi',
        'isuzu', 'mazda', 'dacia', 'citroen', 'suv', 'camion', 'moto', 'garage', 'atelier', 'pont',
    ];

    /**
     * Dictionnaire intégré des codes OBD-II les plus fréquents en atelier (0 token Gemini).
     *
     * @var array<string, array{title: string, causes: string, checks: string, urgency: string}>
     */
    private const OBD_DICTIONARY = [
        'P0300' => [
            'title' => 'Ratés d\'allumage aléatoires / multiples cylindres',
            'causes' => 'Bougies usées, bobines d\'allumage fatiguées, prise d\'air à l\'admission, pression carburant faible, injecteurs encrassés.',
            'checks' => 'Inspecter bougies et bobines, contrôler les trims carburant (STFT/LTFT), tester les fuites d\'air admission et la pression rail.',
            'urgency' => 'Urgent (risque de destruction du catalyseur)',
        ],
        'P0301' => [
            'title' => 'Raté d\'allumage détecté — Cylindre 1',
            'causes' => 'Bobine ou bougie cylindre 1 HS, injecteur cylindre 1 bouché, perte de compression.',
            'checks' => 'Permuter la bobine du cyl. 1 avec le cyl. 2 pour voir si le défaut migre (P0302), vérifier bougie, faisceau et compression.',
            'urgency' => 'Urgent (risque catalyseur)',
        ],
        'P0302' => [
            'title' => 'Raté d\'allumage détecté — Cylindre 2',
            'causes' => 'Bobine ou bougie cylindre 2 HS, injecteur cylindre 2 défaillant, prise d\'air ou compression faible.',
            'checks' => 'Permuter la bobine cyl. 2 avec un autre cylindre, contrôler l\'état de la bougie, l\'alimentation injecteur et la compression.',
            'urgency' => 'Urgent (risque catalyseur)',
        ],
        'P0303' => [
            'title' => 'Raté d\'allumage détecté — Cylindre 3',
            'causes' => 'Bobine ou bougie cylindre 3 HS, injecteur encrassé, défaut faisceau ou compression.',
            'checks' => 'Permuter bobine cyl. 3 avec cyl. 1, inspecter bougie, tester la résistance de l\'injecteur et la compression.',
            'urgency' => 'Urgent (risque catalyseur)',
        ],
        'P0304' => [
            'title' => 'Raté d\'allumage détecté — Cylindre 4',
            'causes' => 'Bobine ou bougie cylindre 4 HS, injecteur défectueux, fuite soupape/joint de culasse.',
            'checks' => 'Permuter bobine cyl. 4, contrôler bougie, faisceau d\'allumage et compression cylindre 4.',
            'urgency' => 'Urgent (risque catalyseur)',
        ],
        'P0171' => [
            'title' => 'Système trop pauvre (Banque 1)',
            'causes' => 'Prise d\'air après débitmètre (durite fendue, joint collecteur), débitmètre MAF encrassé, pompe/filtre carburant faible, sonde lambda amont.',
            'checks' => 'Test fumigène/visuel des durites d\'admission, nettoyer le MAF, mesurer la pression carburant et lire les valeurs STFT/LTFT.',
            'urgency' => 'Modéré à Urgent (risque surchauffe moteur)',
        ],
        'P0172' => [
            'title' => 'Système trop riche (Banque 1)',
            'causes' => 'Injecteur qui fuit, régulateur de pression carburant bloqué, sonde lambda/MAF erronée, filtre à air colmaté.',
            'checks' => 'Vérifier filtre à air, lire les données MAF et sonde O2, contrôler la pression carburant et l\'étanchéité des injecteurs.',
            'urgency' => 'Modéré (surconsommation & encrassement)',
        ],
        'P0420' => [
            'title' => 'Rendement du catalyseur inférieur au seuil (Banque 1)',
            'causes' => 'Catalyseur usé/vidé, fuite d\'échappement avant la sonde aval, sonde lambda aval (O2 B1S2) défectueuse.',
            'checks' => 'Comparer les courbes des sondes O2 amont/aval à chaud, vérifier l\'absence de fuite d\'échappement et contrôler la température entrée/sortie catalyseur.',
            'urgency' => 'Modéré (pollution / contrôle technique)',
        ],
        'P0401' => [
            'title' => 'Débit de recirculation des gaz d\'échappement (EGR) insuffisant',
            'causes' => 'Vanne EGR encrassée/bloquée fermée par la calamine, conduit EGR bouché, capteur de position EGR ou électrovanne HS.',
            'checks' => 'Démonter et nettoyer la vanne EGR + conduit, actionner la vanne via valise OBD et vérifier le faisceau/dépression.',
            'urgency' => 'Modéré (perte de rendement & voyant moteur)',
        ],
        'P0101' => [
            'title' => 'Plage / Performance du débitmètre d\'air massique (MAF)',
            'causes' => 'Capteur MAF encrassé ou HS, filtre à air très sale, fuite sur durite turbo/admission.',
            'checks' => 'Inspecter filtre à air et durites d\'admission, nettoyer le MAF avec un spray dédié, vérifier la valeur g/s au ralenti et en charge.',
            'urgency' => 'Modéré',
        ],
        'P0115' => [
            'title' => 'Dysfonctionnement du circuit capteur température liquide de refroidissement (ECT)',
            'causes' => 'Sonde de température d\'eau HS, connecteur oxydé/coupé, thermostat bloqué.',
            'checks' => 'Lire la température moteur à froid à la valise (doit correspondre à la température ambiante), tester la résistance de la sonde et le faisceau.',
            'urgency' => 'Urgent (risque surchauffe non détectée)',
        ],
        'P0118' => [
            'title' => 'Signal haut du capteur température liquide de refroidissement (ECT)',
            'causes' => 'Circuit ouvert (fil coupé ou prise débranchée) ou sonde ECT HS.',
            'checks' => 'Vérifier le branchement et la continuité du faisceau jusqu\'au calculateur, remplacer la sonde si résistance infinie.',
            'urgency' => 'Urgent (ventilateur permanent / démarrage difficile)',
        ],
        'P0128' => [
            'title' => 'Température liquide de refroidissement inférieure au seuil du thermostat',
            'causes' => 'Thermostat bloqué ouvert, niveau de liquide bas, sonde ECT décalée.',
            'checks' => 'Vérifier le niveau de LDR, surveiller la montée en température à la valise (doit atteindre ~85-90°C), remplacer le thermostat si nécessaire.',
            'urgency' => 'Modéré',
        ],
        'P0130' => [
            'title' => 'Dysfonctionnement circuit sonde à oxygène (Banque 1 Capteur 1)',
            'causes' => 'Sonde lambda amont HS, faisceau brûlé/coupé, fuite d\'échappement amont.',
            'checks' => 'Tester l\'alimentation et la masse de la sonde, observer la tension (doit osciller entre 0,1V et 0,9V à chaud).',
            'urgency' => 'Modéré',
        ],
        'P0135' => [
            'title' => 'Circuit de chauffage sonde à oxygène (Banque 1 Capteur 1)',
            'causes' => 'Résistance de chauffage interne de la sonde coupée, fusible grillé, fil coupé.',
            'checks' => 'Mesurer la résistance entre les deux fils de chauffage (souvent blancs, ~4-15 Ω) et vérifier le 12V contact mis.',
            'urgency' => 'Modéré',
        ],
        'P0299' => [
            'title' => 'Sous-alimentation turbo / compresseur (pression trop faible)',
            'causes' => 'Durite de suralimentation ou intercooler percé, électrovanne N75/wastegate bloquée, géométrie variable grippée, turbo fatigué.',
            'checks' => 'Mettre le circuit d\'admission sous pression (recherche de fuite), tester l\'actuateur de wastegate/GV à la pompe à vide et contrôler le capteur MAP.',
            'urgency' => 'Urgent (perte de puissance / mode dégradé)',
        ],
        'P0335' => [
            'title' => 'Dysfonctionnement du capteur de position vilebrequin (PMH)',
            'causes' => 'Capteur PMH HS, cible/volant moteur endommagé ou sale, faisceau coupé.',
            'checks' => 'Vérifier le régime moteur (tr/min) au démarreur à la valise, contrôler résistance/signal du capteur PMH et entrefer.',
            'urgency' => 'Sécurité / Critique (refus de démarrage ou calage brutal)',
        ],
        'P0340' => [
            'title' => 'Dysfonctionnement du capteur d\'arbre à cames (AAC)',
            'causes' => 'Capteur AAC HS, décalage de la courroie/chaîne de distribution, problème de faisceau.',
            'checks' => 'Contrôler le connecteur et l\'alimentation 5V/12V du capteur, vérifier le calage de la distribution si bruit ou démarrage long.',
            'urgency' => 'Urgent (risque casse moteur si distribution décalée)',
        ],
        'P0442' => [
            'title' => 'Petite fuite détectée dans le système EVAP (vapeurs carburant)',
            'causes' => 'Bouchon de réservoir mal serré ou joint sec, durite EVAP fissurée, électrovanne de purge canister bloquée.',
            'checks' => 'Vérifier le bouchon de réservoir, inspecter les durites canister et tester l\'étanchéité de la vanne de purge.',
            'urgency' => 'Faible à Modéré',
        ],
        'P0500' => [
            'title' => 'Dysfonctionnement du capteur de vitesse véhicule (VSS)',
            'causes' => 'Capteur de vitesse boîte ou capteur ABS défaillant, faisceau endommagé.',
            'checks' => 'Comparer la vitesse affichée au compteur et les vitesses de roues dans le module ABS à la valise, contrôler faisceau.',
            'urgency' => 'Urgent (impact ABS / boîte auto)',
        ],
        'P0505' => [
            'title' => 'Dysfonctionnement du système de régulation du ralenti',
            'causes' => 'Boîtier papillon encrassé, vanne IAC bloquée, prise d\'air admission.',
            'checks' => 'Nettoyer le boîtier papillon et faire un réapprentissage ralenti à la valise, vérifier les durites de dépression.',
            'urgency' => 'Modéré',
        ],
        'P0562' => [
            'title' => 'Tension système trop basse',
            'causes' => 'Batterie faible/sulfatée, alternateur qui ne charge plus, cosses oxydées ou tresse de masse moteur coupée.',
            'checks' => 'Mesurer tension batterie moteur arrêté (~12,6V) et moteur tournant (~13,8V à 14,4V), vérifier courroie et masses.',
            'urgency' => 'Urgent (risque panne électrique complète)',
        ],
        'P0600' => [
            'title' => 'Défaut de communication bus série / CAN',
            'causes' => 'Câblage CAN endommagé, batterie faible, module électronique en court-circuit.',
            'checks' => 'Vérifier tension batterie, mesurer résistance bus CAN (60 Ω entre CAN-H et CAN-L contact coupé), contrôler les connecteurs ECU.',
            'urgency' => 'Urgent',
        ],
        'P0700' => [
            'title' => 'Dysfonctionnement du système de commande de boîte automatique (TCM)',
            'causes' => 'Code générique signalant un défaut enregistré dans le calculateur de boîte auto (solénoïde, huile BVA, capteur vitesse).',
            'checks' => 'Lire impérativement les codes spécifiques dans le module TCM/Boîte auto et vérifier le niveau/état de l\'huile ATF.',
            'urgency' => 'Urgent (mode dégradé boîte auto)',
        ],
        'P2002' => [
            'title' => 'Efficacité du filtre à particules (FAP / DPF) inférieure au seuil',
            'causes' => 'FAP colmaté par la suie, capteur de pression différentielle ou ses durites percées, régénérations interrompues.',
            'checks' => 'Lire la pression différentielle FAP au ralenti et à 3000 tr/min, inspecter les 2 durites du capteur, lancer une régénération forcée ou nettoyage FAP.',
            'urgency' => 'Urgent (risque perte puissance & dilution huile)',
        ],
        'U0100' => [
            'title' => 'Perte de communication avec le calculateur moteur (ECM / PCM)',
            'causes' => 'Fusible/relais alimentation ECU grillé, mauvaise masse, fil bus CAN coupé, batterie très faible.',
            'checks' => 'Contrôler les fusibles et relais principaux ECU, vérifier masses châssis/moteur et résistance bus CAN (60 Ω).',
            'urgency' => 'Critique (démarrage impossible / mode sécurité)',
        ],
    ];

    /**
     * 1. Pré-filtre local ultra-rapide (0 token Gemini) pour salutations et hors-sujet évidents.
     *
     * @return ?array{reply: string, is_off_topic: bool, model: string}
     */
    public function quickLocalFilter(string $prompt): ?array
    {
        $clean = trim(preg_replace('/[^a-z0-9\s]/', ' ', strtolower(Str::ascii($prompt))) ?? '');
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if ($clean === '') {
            return null;
        }

        // Salutations / remerciements courts sans contenu technique -> réponse instantanée 0 token
        if (in_array($clean, self::GREETINGS, true)) {
            return [
                'reply' => "Bonjour ! Je suis **MboaCar IA Auto**, votre copilote de diagnostic atelier.\nDécrivez-moi un symptôme, un code défaut OBD-II (ex: `P0300`, `P0420`) ou joignez une photo de voyant/pièce pour commencer.",
                'is_off_topic' => false,
                'model' => 'mboacar-local',
            ];
        }

        // Si le message contient un code OBD ou un mot-clé automobile, on ne le bloque JAMAIS localement
        if ($this->extractObdCodes($prompt) !== []) {
            return null;
        }
        foreach (self::AUTO_KEYWORDS as $autoWord) {
            if (str_contains($clean, $autoWord)) {
                return null;
            }
        }

        // Si le message contient un mot-clé manifestement hors-automobile -> refus instantané à 0 token
        foreach (self::OFF_TOPIC_KEYWORDS as $offWord) {
            if (str_contains($clean, $offWord)) {
                return [
                    'reply' => "Je suis MboaCar IA Auto, un assistant exclusivement spécialisé dans le diagnostic, la mécanique et l'entretien automobile. Je ne peux pas répondre aux questions hors du domaine automobile.",
                    'is_off_topic' => true,
                    'model' => 'mboacar-local',
                ];
            }
        }

        return null;
    }

    /**
     * 2. Dictionnaire local OBD-II intégré (0 token Gemini) pour les demandes directes sur un code connu.
     *
     * @return ?array{reply: string, code: string}
     */
    public function lookupObdDictionary(string $prompt): ?array
    {
        $codes = $this->extractObdCodes($prompt);
        if (count($codes) !== 1) {
            return null;
        }

        $code = $codes[0];
        if (! isset(self::OBD_DICTIONARY[$code])) {
            return null;
        }

        // On sert le dictionnaire local directement pour les questions courtes/générales sur le code (<= 14 mots)
        $wordCount = str_word_count(Str::ascii($prompt));
        if ($wordCount > 14) {
            return null;
        }

        $entry = self::OBD_DICTIONARY[$code];
        $reply = "**Code OBD-II {$code} — {$entry['title']}**\n\n"
            ."**1. Causes probables :**\n- {$entry['causes']}\n\n"
            ."**2. Contrôles atelier recommandés :**\n- {$entry['checks']}\n\n"
            ."**3. Niveau d'urgence :** {$entry['urgency']}\n\n"
            .'*Diagnostic issu du référentiel atelier MboaCar (0 token). Vérification physique indispensable.*';

        return [
            'reply' => $reply,
            'code' => $code,
        ];
    }

    /**
     * 3. Recherche dans les diagnostics enregistrés en base de données (auto-apprenant).
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

        // Récupérer les réponses valides & approuvées (non hors-sujet, non rejetées par is_resolved = false)
        $candidateReplies = AiDiagnosticMessage::withoutTenantScope()
            ->where('role', AiDiagnosticMessage::ROLE_ASSISTANT)
            ->where('is_off_topic', false)
            ->where('is_approved_for_knowledge', true)
            ->where(function ($q) {
                $q->where('is_resolved', true)
                    ->orWhereNull('is_resolved');
            })
            ->whereNull('matched_from_message_id')
            ->whereNotIn('model', ['mboacar-local'])
            ->orderByDesc('is_resolved')
            ->orderByDesc('reuse_count')
            ->orderByDesc('id')
            ->take(250)
            ->get(['id', 'conversation_id', 'content', 'is_resolved', 'reuse_count']);

        if ($candidateReplies->isEmpty()) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        $convIds = $candidateReplies->pluck('conversation_id')->unique()->values()->all();
        $userMessagesByConv = AiDiagnosticMessage::withoutTenantScope()
            ->whereIn('conversation_id', $convIds)
            ->where('role', AiDiagnosticMessage::ROLE_USER)
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'content'])
            ->groupBy('conversation_id');

        $scored = [];

        foreach ($candidateReplies as $reply) {
            $convUserMsgs = $userMessagesByConv->get($reply->conversation_id);
            if (! $convUserMsgs) {
                continue;
            }

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
                    'is_resolved' => $reply->is_resolved === true,
                    'obd_compatible' => $obdCompatible,
                ];
            }
        }

        if ($scored === []) {
            return ['direct_match' => null, 'learning_hints' => []];
        }

        usort($scored, function ($a, $b) {
            // Priorise les réponses explicitement résolues à score équivalent
            $scoreA = $a['score'] + ($a['is_resolved'] ? 0.05 : 0.0);
            $scoreB = $b['score'] + ($b['is_resolved'] ? 0.05 : 0.0);

            return $scoreB <=> $scoreA;
        });

        $best = $scored[0];
        $directMatch = null;
        $threshold = $best['is_resolved'] ? self::RESOLVED_MATCH_THRESHOLD : self::GENERAL_CACHE_THRESHOLD;

        if ($best['obd_compatible'] && $best['score'] >= $threshold) {
            $directMatch = [
                'message_id' => $best['message_id'],
                'reply' => $best['reply'],
                'score' => round($best['score'], 2),
            ];
        }

        // Extraire jusqu'à 2 cas résolus anonymisés (compact pour économiser les tokens d'entrée)
        $learningHints = [];
        foreach ($scored as $item) {
            if (! $item['is_resolved']) {
                continue;
            }
            $learningHints[] = Str::limit(preg_replace('/\s+/', ' ', $item['prompt']), 90)
                .' => '.Str::limit(preg_replace('/\s+/', ' ', $item['reply']), 180);
            if (count($learningHints) >= 2) {
                break;
            }
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

        return ($jaccard * 0.55) + ($containment * 0.45);
    }
}
