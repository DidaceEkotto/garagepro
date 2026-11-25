<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const scenarios = [
    {
        id: 'obd',
        label: 'Code OBD-II',
        vehicle: 'Toyota Corolla 2016 • Essence • 118 400 km',
        mechanicName: 'Chef d\'atelier',
        prompt: 'Code défaut P0300 (ratés d\'allumage multiples) + ralenti instable à chaud. Par quoi commencer ?',
        isOffTopic: false,
        replyTitle: 'Diagnostic OBD-II — Code P0300',
        replyLines: [
            '1. Causes probables : bobines d\'allumage fatiguées (45%), bougies usées, prise d\'air admission.',
            '2. Contrôles atelier : mesurer la résistance des bobines, inspecter l\'écartement des bougies et tester la dépression collecteur.',
            '3. Urgence : Élevée (risque de détérioration du catalyseur).',
        ],
        responseTime: '1,4 s',
    },
    {
        id: 'diesel',
        label: 'Symptôme Diesel',
        vehicle: 'Hyundai Tucson 2018 • CRDi Diesel • 142 000 km',
        mechanicName: 'Mécanicien',
        prompt: 'Fumée noire épaisse à l\'accélération et perte de puissance en montée. Quelles pistes ?',
        isOffTopic: false,
        replyTitle: 'Analyse Combustion Diesel',
        replyLines: [
            '1. Causes probables : vanne EGR bloquée ouverte, durite de suralimentation fendue, injecteur qui goutte.',
            '2. Contrôles atelier : vérifier les durites turbo/intercooler, lire la pression de suralimentation réelle et le débitmètre MAF.',
            '3. Pièces à vérifier : vanne EGR, durite turbo, filtre à air.',
        ],
        responseTime: '1,6 s',
    },
    {
        id: 'guardrail',
        label: 'Garde-fou 100 % Auto',
        vehicle: 'Protection anti hors-sujet active',
        mechanicName: 'Utilisateur',
        prompt: 'Donne-moi une recette de cuisine pour préparer du ndolé ce soir.',
        isOffTopic: true,
        replyTitle: 'Hors domaine automobile',
        replyLines: [
            'Je suis MboaCar IA Auto, exclusivement dédié au diagnostic, à la mécanique et à l\'entretien automobile.',
            'Je ne peux pas répondre aux questions hors du domaine automobile. Posez-moi une question sur un véhicule ou un code défaut !',
        ],
        responseTime: '0,9 s',
    },
];

const activeIndex = ref(0);
// phase: 'typing_user' -> 'show_user' -> 'ai_thinking' -> 'show_ai'
const phase = ref('typing_user');
const typedPrompt = ref('');
const visibleReplyCount = ref(0);

const currentScenario = computed(() => scenarios[activeIndex.value]);

let timers = [];

function clearAllTimers() {
    timers.forEach((id) => clearTimeout(id));
    timers = [];
}

function schedule(fn, delay) {
    const id = setTimeout(fn, delay);
    timers.push(id);
    return id;
}

function runScenario(idx) {
    clearAllTimers();
    activeIndex.value = idx;
    phase.value = 'typing_user';
    typedPrompt.value = '';
    visibleReplyCount.value = 0;

    const fullPrompt = scenarios[idx].prompt;
    const stepMs = 24;

    // Effet frappe rapide dans la barre de saisie du garagiste
    for (let i = 1; i <= fullPrompt.length; i++) {
        schedule(() => {
            typedPrompt.value = fullPrompt.slice(0, i);
        }, i * stepMs);
    }

    const afterTyping = fullPrompt.length * stepMs + 350;

    // Envoi du message du garagiste
    schedule(() => {
        typedPrompt.value = '';
        phase.value = 'show_user';
    }, afterTyping);

    // MboaCar IA Auto réfléchit
    schedule(() => {
        phase.value = 'ai_thinking';
    }, afterTyping + 450);

    // Affichage progressif de la réponse structurée
    const aiStart = afterTyping + 1850;
    schedule(() => {
        phase.value = 'show_ai';
        visibleReplyCount.value = 1;
    }, aiStart);

    const lines = scenarios[idx].replyLines;
    for (let l = 2; l <= lines.length; l++) {
        schedule(() => {
            visibleReplyCount.value = l;
        }, aiStart + (l - 1) * 520);
    }

    // Pause lecture puis passage au scénario suivant en boucle infinie
    const totalDuration = aiStart + lines.length * 520 + 4600;
    schedule(() => {
        runScenario((idx + 1) % scenarios.length);
    }, totalDuration);
}

onMounted(() => {
    runScenario(0);
});

onBeforeUnmount(() => {
    clearAllTimers();
});
</script>

<template>
    <section
        id="ia-auto"
        class="py-5 border-top position-relative overflow-hidden text-white"
        style="background: radial-gradient(circle at 18% 20%, rgba(59, 130, 246, 0.28), transparent 48%), radial-gradient(circle at 85% 80%, rgba(16, 185, 129, 0.18), transparent 45%), linear-gradient(135deg, #0b1224 0%, #0f172a 55%, #111c36 100%);"
    >
        <div class="container py-4">
            <div class="row align-items-center g-5">
                <!-- Colonne gauche : Atouts & Productivité -->
                <div class="col-lg-6">
                    <span class="badge rounded-pill bg-primary bg-opacity-25 text-info border border-info border-opacity-25 px-3 py-2 mb-3 fw-semibold">
                        <i class="bi bi-stars me-1"></i>NOUVEAU • MBOACAR IA AUTO
                    </span>
                    <h2 class="display-6 fw-bold lh-sm mb-3">
                        Diagnostiquez <span class="text-info">3× plus vite</span> avec votre copilote IA 100 % automobile
                    </h2>
                    <p class="text-white-50 mb-4">
                        Intégré directement dans votre espace professionnel, <strong class="text-white">MboaCar IA Auto</strong> assiste vos mécaniciens en temps réel sur les codes OBD-II, pannes complexes et contrôles atelier — tout en bloquant automatiquement les questions hors sujet.
                    </p>

                    <!-- Bandeau KPI accélération / productivité -->
                    <div class="row g-2 mb-4">
                        <div class="col-4">
                            <div class="p-3 rounded-3 ai-stat-box text-center h-100">
                                <div class="h4 fw-bold text-info mb-0">-65 %</div>
                                <div class="text-white-50" style="font-size: 0.75rem;">Temps de recherche de panne</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 ai-stat-box text-center h-100">
                                <div class="h4 fw-bold text-success mb-0">×3</div>
                                <div class="text-white-50" style="font-size: 0.75rem;">Productivité au diagnostic</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-3 rounded-3 ai-stat-box text-center h-100">
                                <div class="h4 fw-bold text-warning mb-0">100 %</div>
                                <div class="text-white-50" style="font-size: 0.75rem;">Spécialisé auto &amp; privé</div>
                            </div>
                        </div>
                    </div>

                    <!-- Atouts clés -->
                    <div class="vstack gap-3 mb-4 small">
                        <div class="d-flex align-items-start gap-3">
                            <span class="ai-feature-icon bg-primary bg-opacity-25 text-info">
                                <i class="bi bi-lightning-charge-fill"></i>
                            </span>
                            <div>
                                <div class="fw-semibold text-white">Diagnostic structuré en moins de 2 secondes</div>
                                <div class="text-white-50">Causes classées par probabilité, tests à faire sur le pont et niveau d'urgence sécurité.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <span class="ai-feature-icon bg-success bg-opacity-25 text-success">
                                <i class="bi bi-people-fill"></i>
                            </span>
                            <div>
                                <div class="fw-semibold text-white">Multiplie l'autonomie de toute votre équipe</div>
                                <div class="text-white-50">Vos mécaniciens juniors résolvent plus de pannes sans bloquer le chef d'atelier, réduisant les erreurs de commande de pièces.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <span class="ai-feature-icon bg-warning bg-opacity-25 text-warning">
                                <i class="bi bi-shield-lock-fill"></i>
                            </span>
                            <div>
                                <div class="fw-semibold text-white">Strictement automobile &amp; cloisonné par garage</div>
                                <div class="text-white-50">Refuse toute question hors métier et conserve l'historique privé de votre garage en base de données.</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <Link href="/inscription" class="btn btn-primary btn-lg px-4 fw-semibold shadow hover-lift">
                            <i class="bi bi-stars me-2"></i>Tester MboaCar IA Auto
                        </Link>
                        <a href="/demo" class="btn btn-outline-light btn-lg px-4">
                            Voir la démo
                        </a>
                    </div>
                </div>

                <!-- Colonne droite : Simulation animée en boucle -->
                <div class="col-lg-6">
                    <div class="ai-sim-window rounded-4 overflow-hidden shadow-lg">
                        <!-- Barre supérieure de la fenêtre simulée -->
                        <div class="ai-sim-header px-3 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="ai-avatar-glow">
                                    <i class="bi bi-stars"></i>
                                </div>
                                <div>
                                    <div class="fw-bold small text-white d-flex align-items-center gap-2">
                                        <span>MboaCar IA Auto</span>
                                        <span class="ai-live-dot"></span>
                                    </div>
                                    <div class="text-white-50" style="font-size: 0.72rem;">
                                        En ligne • Copilote diagnostic atelier
                                    </div>
                                </div>
                            </div>
                            <!-- Sélecteur de scénarios -->
                            <div class="d-flex align-items-center gap-1">
                                <button
                                    v-for="(sc, idx) in scenarios"
                                    :key="sc.id"
                                    type="button"
                                    class="btn btn-sm py-0 px-2 ai-sc-pill"
                                    :class="activeIndex === idx ? 'active' : ''"
                                    @click="runScenario(idx)"
                                >
                                    {{ sc.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Bandeau contexte véhicule -->
                        <div class="px-3 py-2 ai-sim-vehicle-bar d-flex align-items-center justify-content-between gap-2 small">
                            <span class="text-info text-truncate">
                                <i class="bi bi-car-front-fill me-1"></i>{{ currentScenario.vehicle }}
                            </span>
                            <span class="badge bg-white bg-opacity-10 text-white-50 border border-white border-opacity-10">
                                Scénario {{ activeIndex + 1 }} / {{ scenarios.length }}
                            </span>
                        </div>

                        <!-- Zone des échanges simulés -->
                        <div class="ai-sim-body p-3 d-flex flex-column gap-3">
                            <!-- Message du garagiste -->
                            <div
                                v-if="phase !== 'typing_user'"
                                class="d-flex justify-content-end ai-fade-up"
                            >
                                <div class="ai-sim-bubble-user p-3 rounded-3">
                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-1 opacity-75" style="font-size: 0.7rem;">
                                        <span class="fw-semibold"><i class="bi bi-person-fill me-1"></i>{{ currentScenario.mechanicName }}</span>
                                        <span>À l'instant</span>
                                    </div>
                                    <div class="small">{{ currentScenario.prompt }}</div>
                                </div>
                            </div>

                            <!-- Bulle "en train d'analyser..." -->
                            <div v-if="phase === 'ai_thinking'" class="d-flex justify-content-start ai-fade-up">
                                <div class="ai-sim-bubble-ai p-3 rounded-3 d-inline-flex align-items-center gap-2">
                                    <span class="small text-info fw-medium">MboaCar IA Auto analyse</span>
                                    <span class="ai-typing-dots">
                                        <span></span><span></span><span></span>
                                    </span>
                                </div>
                            </div>

                            <!-- Réponse de MboaCar IA Auto -->
                            <div v-if="phase === 'show_ai'" class="d-flex justify-content-start ai-fade-up">
                                <div
                                    class="p-3 rounded-3 w-100"
                                    :class="currentScenario.isOffTopic ? 'ai-sim-bubble-offtopic' : 'ai-sim-bubble-ai'"
                                >
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2" style="font-size: 0.72rem;">
                                        <span class="fw-bold d-inline-flex align-items-center gap-1" :class="currentScenario.isOffTopic ? 'text-warning' : 'text-info'">
                                            <i class="bi" :class="currentScenario.isOffTopic ? 'bi-slash-circle-fill' : 'bi-stars'"></i>
                                            {{ currentScenario.replyTitle }}
                                        </span>
                                        <span class="badge bg-white bg-opacity-10 text-white-50">
                                            <i class="bi bi-stopwatch me-1"></i>{{ currentScenario.responseTime }}
                                        </span>
                                    </div>
                                    <div class="vstack gap-2 small">
                                        <div
                                            v-for="(line, lIdx) in currentScenario.replyLines.slice(0, visibleReplyCount)"
                                            :key="lIdx"
                                            class="ai-fade-up"
                                            :class="currentScenario.isOffTopic ? 'text-warning-emphasis text-white' : 'text-light'"
                                        >
                                            {{ line }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Barre de saisie simulée en bas -->
                        <div class="ai-sim-footer px-3 py-2 d-flex align-items-center gap-2">
                            <div class="ai-sim-input flex-grow-1 small text-truncate">
                                <span v-if="typedPrompt" class="text-white">{{ typedPrompt }}<span class="ai-cursor">|</span></span>
                                <span v-else class="text-white-50">Posez une question mécanique ou un code OBD-II...</span>
                            </div>
                            <span class="btn btn-primary btn-sm px-3 disabled opacity-100">
                                <i class="bi bi-send-fill"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.ai-stat-box {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(8px);
}

.ai-feature-icon {
    width: 38px;
    height: 38px;
    border-radius: 0.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.05rem;
}

.ai-sim-window {
    background: rgba(15, 23, 42, 0.92);
    border: 1px solid rgba(148, 163, 184, 0.22);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
}

.ai-sim-header {
    background: rgba(30, 41, 59, 0.85);
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
}

.ai-avatar-glow {
    width: 38px;
    height: 38px;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #2563eb 0%, #06b6d4 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    box-shadow: 0 0 18px rgba(56, 189, 248, 0.45);
}

.ai-live-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: #22c55e;
    box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.22);
    display: inline-block;
}

.ai-sc-pill {
    font-size: 0.7rem;
    border-radius: 999px;
    color: rgba(255, 255, 255, 0.7);
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.12);
    transition: all 0.2s ease;
}

.ai-sc-pill.active,
.ai-sc-pill:hover {
    color: #fff;
    background: #2563eb;
    border-color: #3b82f6;
}

.ai-sim-vehicle-bar {
    background: rgba(15, 23, 42, 0.75);
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    font-size: 0.78rem;
}

.ai-sim-body {
    min-height: 320px;
    max-height: 360px;
    overflow-y: auto;
    background: radial-gradient(circle at top right, rgba(37, 99, 235, 0.12), transparent 60%);
}

.ai-sim-bubble-user {
    max-width: 86%;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.28);
}

.ai-sim-bubble-ai {
    max-width: 94%;
    background: rgba(30, 41, 59, 0.85);
    border: 1px solid rgba(56, 189, 248, 0.28);
    color: #e2e8f0;
}

.ai-sim-bubble-offtopic {
    max-width: 94%;
    background: rgba(120, 53, 15, 0.32);
    border: 1px solid rgba(251, 191, 36, 0.45);
    color: #fef3c7;
}

.ai-sim-footer {
    background: rgba(30, 41, 59, 0.88);
    border-top: 1px solid rgba(148, 163, 184, 0.16);
}

.ai-sim-input {
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 0.5rem;
    padding: 0.45rem 0.75rem;
    min-height: 36px;
}

.ai-cursor {
    display: inline-block;
    color: #38bdf8;
    animation: blinkCursor 0.8s infinite;
}

.ai-typing-dots {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.ai-typing-dots span {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #38bdf8;
    animation: bounceDot 1.1s infinite ease-in-out;
}

.ai-typing-dots span:nth-child(2) {
    animation-delay: 0.18s;
}

.ai-typing-dots span:nth-child(3) {
    animation-delay: 0.36s;
}

.ai-fade-up {
    animation: aiFadeUp 0.32s ease forwards;
}

@keyframes aiFadeUp {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes blinkCursor {
    0%, 49% {
        opacity: 1;
    }
    50%, 100% {
        opacity: 0;
    }
}

@keyframes bounceDot {
    0%, 80%, 100% {
        transform: scale(0.65);
        opacity: 0.4;
    }
    40% {
        transform: scale(1.1);
        opacity: 1;
    }
}
</style>
