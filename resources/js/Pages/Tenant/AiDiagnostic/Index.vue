<script setup>
import { useConfirm } from '@/composables/useConfirm';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeConversation: { type: Object, default: null },
    vehicles: { type: Array, default: () => [] },
    isConfigured: { type: Boolean, default: false },
    modelName: { type: String, default: 'gemini-3.8-flash' },
});

const { askConfirm } = useConfirm();
const chatContainer = ref(null);
const copiedId = ref(null);

const form = useForm({
    conversation_id: props.activeConversation?.id ?? null,
    vehicle_id: props.activeConversation?.vehicle_id ?? '',
    prompt: '',
});

const starterPrompts = [
    'Code défaut OBD P0300 (ratés d\'allumage multiples) : causes probables et contrôles à faire ?',
    'Moteur diesel avec fumée noire à l\'accélération et perte de puissance : diagnostic étape par étape ?',
    'Voyant ABS + ESP allumé en permanence après changement de plaquettes : quoi vérifier ?',
    'Claquement métallique sur le train avant en passant sur les dos d\'âne : quelles pièces contrôler ?',
];

function scrollToBottom() {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}

onMounted(scrollToBottom);

watch(
    () => props.activeConversation,
    (conv) => {
        form.conversation_id = conv?.id ?? null;
        form.vehicle_id = conv?.vehicle_id ?? '';
        scrollToBottom();
    },
    { deep: true }
);

function selectConversation(id) {
    router.get('/assistant-ia', { conversation: id }, { preserveScroll: true });
}

function startNewConversation() {
    router.get('/assistant-ia', { new: 1 }, { preserveScroll: true });
}

function useStarter(text) {
    form.prompt = text;
}

function sendPrompt() {
    if (!form.prompt.trim() || form.processing) return;
    form.post('/assistant-ia/messages', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt');
            scrollToBottom();
        },
    });
}

function handleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendPrompt();
    }
}

function deleteConversation(conv) {
    askConfirm({
        title: 'Supprimer ce diagnostic',
        message: `Voulez-vous supprimer définitivement la conversation « ${conv.title} » ?`,
        confirmLabel: 'Supprimer',
        confirmClass: 'btn-danger',
        onConfirm: () => router.delete(`/assistant-ia/${conv.id}`),
    });
}

async function copyMessage(msg) {
    try {
        await navigator.clipboard.writeText(msg.content);
        copiedId.value = msg.id;
        setTimeout(() => {
            if (copiedId.value === msg.id) copiedId.value = null;
        }, 1800);
    } catch {
        // ignore
    }
}

function formatDateTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Assistant IA Diagnostic Auto" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h1 class="h4 fw-bold mb-0">
                        <i class="bi bi-stars text-primary me-1"></i>Assistant IA Diagnostic Automobile
                    </h1>
                    <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">
                        100 % Automobile
                    </span>
                </div>
                <p class="text-muted small mb-0 mt-1">
                    Aide au diagnostic OBD-II, pannes mécaniques, électricité et entretien — historique privé et isolé pour votre garage
                </p>
            </div>
            <button type="button" class="btn btn-primary btn-sm px-3" @click="startNewConversation">
                <i class="bi bi-plus-lg me-1"></i>Nouveau diagnostic
            </button>
        </div>

        <div v-if="!isConfigured" class="alert alert-warning border-0 shadow-sm small mb-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Clé API manquante :</strong> Veuillez renseigner <code>GEMINI_API_KEY</code> dans le fichier <code>.env</code> pour activer les réponses de l'assistant IA.
        </div>

        <div class="row g-3">
            <!-- Historique privé des conversations du garage -->
            <div class="col-lg-4 col-xl-3">
                <div class="card border-0 shadow-sm ai-sidebar-card">
                    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center border-bottom">
                        <span class="fw-bold small">
                            <i class="bi bi-clock-history text-primary me-1"></i>Vos diagnostics ({{ conversations.length }})
                        </span>
                        <span class="badge bg-light text-muted border" title="Isolé pour votre garage">
                            <i class="bi bi-lock-fill"></i> Privé
                        </span>
                    </div>
                    <div class="list-group list-group-flush ai-conv-list">
                        <div v-if="!conversations.length" class="p-4 text-center text-muted small">
                            <i class="bi bi-chat-square-dots fs-4 d-block mb-1 opacity-50"></i>
                            Aucun diagnostic enregistré pour votre garage.
                        </div>
                        <div
                            v-for="conv in conversations"
                            :key="conv.id"
                            class="list-group-item list-group-item-action px-3 py-2 d-flex justify-content-between align-items-start gap-2 ai-conv-item"
                            :class="{ active: activeConversation?.id === conv.id }"
                            style="cursor: pointer;"
                            @click="selectConversation(conv.id)"
                        >
                            <div class="min-w-0 flex-grow-1" style="min-width: 0;">
                                <div class="fw-semibold small text-truncate">{{ conv.title }}</div>
                                <div class="d-flex flex-wrap align-items-center gap-1 mt-1" style="font-size: 0.7rem;">
                                    <span v-if="conv.vehicle" class="badge bg-white text-dark border">
                                        {{ conv.vehicle.license_plate }}
                                    </span>
                                    <span :class="activeConversation?.id === conv.id ? 'text-white-50' : 'text-muted'">
                                        {{ conv.messages_count ?? 0 }} msg • {{ formatDateTime(conv.last_message_at || conv.updated_at) }}
                                    </span>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 flex-shrink-0"
                                :class="activeConversation?.id === conv.id ? 'text-white' : 'text-muted'"
                                title="Supprimer cette conversation"
                                @click.stop="deleteConversation(conv)"
                            >
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zone de discussion IA -->
            <div class="col-lg-8 col-xl-9">
                <div class="card border-0 shadow-sm ai-chat-card d-flex flex-column">
                    <!-- Barre de contexte véhicule -->
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 420px;">
                            <i class="bi bi-car-front-fill text-primary"></i>
                            <select v-model="form.vehicle_id" class="form-select form-select-sm">
                                <option value="">-- Véhicule concerné (facultatif) --</option>
                                <option v-for="v in vehicles" :key="v.id" :value="v.id">
                                    {{ v.license_plate }} — {{ v.brand }} {{ v.model }} {{ v.year ? `(${v.year})` : '' }}
                                </option>
                            </select>
                        </div>
                        <span class="small text-muted">
                            <i class="bi bi-shield-check text-success me-1"></i>Spécialisé diagnostic auto • {{ modelName }}
                        </span>
                    </div>

                    <!-- Fil des messages -->
                    <div ref="chatContainer" class="card-body p-3 ai-messages-area flex-grow-1">
                        <div v-if="!activeConversation?.messages?.length" class="text-center py-4 px-2">
                            <div class="ai-hero-icon mx-auto mb-3">
                                <i class="bi bi-wrench-adjustable-circle-fill"></i>
                            </div>
                            <h2 class="h5 fw-bold mb-1">Comment puis-je vous aider à l'atelier ?</h2>
                            <p class="text-muted small mx-auto mb-4" style="max-width: 560px;">
                                Décrivez un symptôme, un voyant ou un code défaut OBD-II. Sélectionnez un véhicule ci-dessus pour un diagnostic encore plus précis.
                                <br />
                                <span class="text-primary fw-medium">Note : cet assistant répond uniquement aux questions automobiles.</span>
                            </p>
                            <div class="row g-2 justify-content-center text-start" style="max-width: 720px; margin: 0 auto;">
                                <div v-for="(s, idx) in starterPrompts" :key="idx" class="col-md-6">
                                    <button
                                        type="button"
                                        class="btn btn-light border w-100 text-start p-2 small ai-starter-btn"
                                        @click="useStarter(s)"
                                    >
                                        <i class="bi bi-lightning-charge-fill text-primary me-1"></i>{{ s }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-else class="d-flex flex-column gap-3">
                            <div
                                v-for="msg in activeConversation.messages"
                                :key="msg.id"
                                class="d-flex"
                                :class="msg.role === 'user' ? 'justify-content-end' : 'justify-content-start'"
                            >
                                <div
                                    class="ai-bubble p-3 rounded-3 shadow-sm"
                                    :class="msg.role === 'user' ? 'ai-bubble-user' : (msg.is_off_topic ? 'ai-bubble-offtopic' : 'ai-bubble-assistant')"
                                >
                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-1" style="font-size: 0.72rem;">
                                        <span class="fw-bold">
                                            <i v-if="msg.role === 'user'" class="bi bi-person-fill me-1"></i>
                                            <i v-else class="bi bi-stars me-1 text-primary"></i>
                                            {{ msg.role === 'user' ? (msg.user?.name || 'Vous') : 'MboaCar IA Auto' }}
                                        </span>
                                        <div class="d-flex align-items-center gap-2">
                                            <span v-if="msg.is_off_topic" class="badge bg-warning text-dark">
                                                <i class="bi bi-slash-circle me-1"></i>Hors domaine auto
                                            </span>
                                            <span :class="msg.role === 'user' ? 'text-white-50' : 'text-muted'">
                                                {{ formatDateTime(msg.created_at) }}
                                            </span>
                                            <button
                                                v-if="msg.role === 'assistant'"
                                                type="button"
                                                class="btn btn-link btn-sm p-0 text-muted"
                                                title="Copier la réponse"
                                                @click="copyMessage(msg)"
                                            >
                                                <i class="bi" :class="copiedId === msg.id ? 'bi-check2 text-success' : 'bi-clipboard'"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="ai-message-text small">{{ msg.content }}</div>
                                </div>
                            </div>

                            <div v-if="form.processing" class="d-flex justify-content-start">
                                <div class="ai-bubble ai-bubble-assistant p-3 rounded-3 shadow-sm small text-muted">
                                    <span class="spinner-border spinner-border-sm text-primary me-2" role="status"></span>
                                    Analyse du diagnostic automobile en cours...
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Zone de saisie -->
                    <div class="card-footer bg-white border-top p-3">
                        <form @submit.prevent="sendPrompt">
                            <div class="input-group">
                                <textarea
                                    v-model="form.prompt"
                                    rows="2"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.prompt }"
                                    placeholder="Ex: Toyota Corolla 2016 essence, code P0171 mélange trop pauvre et ralenti instable..."
                                    :disabled="form.processing"
                                    required
                                    @keydown="handleKeydown"
                                ></textarea>
                                <button
                                    type="submit"
                                    class="btn btn-primary px-4 fw-semibold"
                                    :disabled="form.processing || !form.prompt.trim()"
                                >
                                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="bi bi-send-fill me-1"></i>Envoyer
                                </button>
                            </div>
                            <div v-if="form.errors.prompt" class="text-danger small mt-1">{{ form.errors.prompt }}</div>
                            <div class="d-flex justify-content-between align-items-center mt-2 text-muted" style="font-size: 0.72rem;">
                                <span><i class="bi bi-info-circle me-1"></i>Aide au diagnostic IA — la vérification physique par un technicien reste indispensable.</span>
                                <span class="d-none sm:inline">Entrée pour envoyer • Maj+Entrée pour nouvelle ligne</span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>

<style scoped>
.ai-sidebar-card,
.ai-chat-card {
    height: calc(100vh - 185px);
    min-height: 520px;
}

.ai-conv-list {
    overflow-y: auto;
    flex: 1;
}

.ai-messages-area {
    overflow-y: auto;
    background-color: #f8fafc;
}

.ai-hero-icon {
    width: 56px;
    height: 56px;
    border-radius: 1rem;
    background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.65rem;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
}

.ai-starter-btn {
    border-radius: 0.6rem;
    transition: border-color 0.15s ease, transform 0.12s ease;
}

.ai-starter-btn:hover {
    border-color: #2563eb !important;
    transform: translateY(-1px);
}

.ai-bubble {
    max-width: 85%;
    word-wrap: break-word;
}

.ai-bubble-user {
    background: #2563eb;
    color: #ffffff;
}

.ai-bubble-assistant {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    color: #0f172a;
}

.ai-bubble-offtopic {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #78350f;
}

.ai-message-text {
    white-space: pre-wrap;
    line-height: 1.55;
}
</style>
