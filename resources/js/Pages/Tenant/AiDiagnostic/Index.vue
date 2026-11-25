<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeConversation: { type: Object, default: null },
    vehicles: { type: Array, default: () => [] },
    activeRepairOrders: { type: Array, default: () => [] },
    prefillRepairOrder: { type: Object, default: null },
    dailyQuota: { type: Number, default: 50 },
    usedToday: { type: Number, default: 0 },
    isConfigured: { type: Boolean, default: false },
    modelName: { type: String, default: 'gemini-3.1-flash-lite' },
});

const { askConfirm } = useConfirm();
const { openLightbox } = useLightbox();
const chatContainer = ref(null);
const photoInputRef = ref(null);
const copiedId = ref(null);
const mediaWarning = ref('');

const form = useForm({
    conversation_id: props.activeConversation?.id ?? null,
    vehicle_id: props.prefillRepairOrder?.vehicle_id ?? props.activeConversation?.vehicle_id ?? '',
    repair_order_id: props.prefillRepairOrder?.id ?? props.activeConversation?.repair_order_id ?? '',
    prompt: props.prefillRepairOrder?.suggested_prompt ?? '',
    photos: [],
    audio: null,
    audio_duration: 0,
});

// Aperçus photos avant envoi
const photoPreviews = ref([]);

function onPhotosSelected(e) {
    mediaWarning.value = '';
    const files = Array.from(e.target.files || []);
    if (!files.length) return;

    const valid = [];
    for (const f of files) {
        if (f.type.startsWith('video/')) {
            mediaWarning.value = 'Les vidéos ne sont pas autorisées. Sélectionnez uniquement des photos (JPG, PNG, WEBP).';
            continue;
        }
        if (!f.type.startsWith('image/')) {
            mediaWarning.value = 'Format non pris en charge : seules les photos sont autorisées.';
            continue;
        }
        valid.push(f);
    }

    const combined = [...form.photos, ...valid].slice(0, 4);
    form.photos = combined;
    rebuildPhotoPreviews();
    if (photoInputRef.value) photoInputRef.value.value = '';
}

function rebuildPhotoPreviews() {
    photoPreviews.value.forEach((p) => URL.revokeObjectURL(p.url));
    photoPreviews.value = form.photos.map((f) => ({
        name: f.name,
        url: URL.createObjectURL(f),
    }));
}

function removePhoto(idx) {
    form.photos.splice(idx, 1);
    rebuildPhotoPreviews();
}

// Enregistreur vocal (audio uniquement, pas de vidéo)
const isRecording = ref(false);
const recordingSeconds = ref(0);
const recordedAudioUrl = ref('');
let mediaRecorder = null;
let audioStream = null;
let audioChunks = [];
let recordTimer = null;

async function toggleRecording() {
    mediaWarning.value = '';
    if (isRecording.value) {
        stopRecording();
        return;
    }

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        mediaWarning.value = "Votre navigateur ne supporte pas l'enregistrement vocal.";
        return;
    }

    try {
        audioStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        const preferredMime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');

        mediaRecorder = preferredMime
            ? new MediaRecorder(audioStream, { mimeType: preferredMime })
            : new MediaRecorder(audioStream);

        audioChunks = [];
        recordingSeconds.value = 0;

        mediaRecorder.ondataavailable = (ev) => {
            if (ev.data && ev.data.size > 0) audioChunks.push(ev.data);
        };

        mediaRecorder.onstop = () => {
            const mime = mediaRecorder?.mimeType || 'audio/webm';
            const cleanMime = mime.split(';')[0] || 'audio/webm';
            const ext = cleanMime.includes('ogg') ? 'ogg' : (cleanMime.includes('mp4') ? 'm4a' : 'webm');
            const blob = new Blob(audioChunks, { type: cleanMime });
            const file = new File([blob], `vocal-atelier-${Date.now()}.${ext}`, { type: cleanMime });

            if (recordedAudioUrl.value) URL.revokeObjectURL(recordedAudioUrl.value);
            recordedAudioUrl.value = URL.createObjectURL(blob);
            form.audio = file;
            form.audio_duration = recordingSeconds.value;

            if (audioStream) {
                audioStream.getTracks().forEach((t) => t.stop());
                audioStream = null;
            }
        };

        mediaRecorder.start();
        isRecording.value = true;
        recordTimer = setInterval(() => {
            recordingSeconds.value += 1;
            if (recordingSeconds.value >= 180) stopRecording();
        }, 1000);
    } catch {
        mediaWarning.value = "Impossible d'accéder au microphone. Vérifiez les permissions de votre navigateur.";
    }
}

function stopRecording() {
    if (recordTimer) {
        clearInterval(recordTimer);
        recordTimer = null;
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    isRecording.value = false;
}

function clearRecordedAudio() {
    if (recordedAudioUrl.value) {
        URL.revokeObjectURL(recordedAudioUrl.value);
        recordedAudioUrl.value = '';
    }
    form.audio = null;
    form.audio_duration = 0;
}

function formatSeconds(sec) {
    const m = Math.floor((sec || 0) / 60);
    const s = (sec || 0) % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

const canSend = computed(() =>
    !form.processing && !isRecording.value && (form.prompt.trim().length > 0 || form.photos.length > 0 || Boolean(form.audio))
);

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

onBeforeUnmount(() => {
    stopRecording();
    photoPreviews.value.forEach((p) => URL.revokeObjectURL(p.url));
    if (recordedAudioUrl.value) URL.revokeObjectURL(recordedAudioUrl.value);
});

watch(
    () => props.activeConversation,
    (conv) => {
        form.conversation_id = conv?.id ?? null;
        if (!props.prefillRepairOrder) {
            form.vehicle_id = conv?.vehicle_id ?? '';
            form.repair_order_id = conv?.repair_order_id ?? '';
        }
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
    if (!canSend.value) return;
    mediaWarning.value = '';
    form.post('/assistant-ia/messages', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'photos', 'audio', 'audio_duration');
            rebuildPhotoPreviews();
            clearRecordedAudio();
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

// 1-clic : Créer brouillon de devis
function createQuoteFromAi(msg) {
    const vehicleId = form.vehicle_id || props.activeConversation?.vehicle_id || '';
    if (!vehicleId) {
        mediaWarning.value = 'Sélectionnez d\'abord un véhicule en haut du chat pour créer un brouillon de devis.';
        return;
    }
    askConfirm({
        title: 'Créer un brouillon de devis',
        message: 'Voulez-vous générer un brouillon de devis (/devis) pré-rempli à partir de ce diagnostic IA ?',
        confirmLabel: 'Créer le devis',
        confirmClass: 'btn-primary',
        onConfirm: () => router.post(`/assistant-ia/messages/${msg.id}/creer-devis`, { vehicle_id: vehicleId }),
    });
}

// 1-clic : Ajouter à un Ordre de Réparation
const applyingRoMessage = ref(null);
const applyRoForm = useForm({
    repair_order_id: '',
});

async function openApplyRoModal(msg) {
    applyingRoMessage.value = msg;
    applyRoForm.repair_order_id = props.activeConversation?.repair_order_id
        || props.activeRepairOrders?.[0]?.id
        || '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalApplyAiToRo'))?.show();
}

function submitApplyToRo() {
    if (!applyingRoMessage.value || !applyRoForm.repair_order_id) return;
    applyRoForm.post(`/assistant-ia/messages/${applyingRoMessage.value.id}/appliquer-or`, {
        preserveScroll: true,
        onSuccess: () => {
            applyingRoMessage.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalApplyAiToRo'))?.hide();
        },
    });
}

// 1-clic : Expliquer au client + WhatsApp
const explainingMessage = ref(null);
const loadingClientSummary = ref(false);
const explainError = ref('');
const clientWhatsappForm = useForm({
    phone: '',
    summary: '',
});

async function openExplainClientModal(msg, regenerate = false) {
    explainingMessage.value = msg;
    explainError.value = '';
    clientWhatsappForm.summary = msg.client_summary || '';
    clientWhatsappForm.phone = props.activeConversation?.vehicle?.customer?.whatsapp_phone
        || props.activeConversation?.vehicle?.customer?.phone
        || '';

    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalExplainClient'))?.show();

    if (!msg.client_summary || regenerate) {
        await fetchClientSummary(msg.id, regenerate);
    }
}

async function fetchClientSummary(messageId, regenerate = false) {
    loadingClientSummary.value = true;
    explainError.value = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch(`/assistant-ia/messages/${messageId}/expliquer-client`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ regenerate }),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) {
            explainError.value = json.error || 'Impossible de générer le résumé client.';
        } else {
            clientWhatsappForm.summary = json.summary || '';
            if (json.phone && !clientWhatsappForm.phone) {
                clientWhatsappForm.phone = json.phone;
            }
        }
    } catch {
        explainError.value = 'Erreur réseau lors de la génération du résumé client.';
    } finally {
        loadingClientSummary.value = false;
    }
}

function submitClientWhatsapp() {
    if (!explainingMessage.value) return;
    clientWhatsappForm.post(`/assistant-ia/messages/${explainingMessage.value.id}/envoyer-whatsapp`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalExplainClient'))?.hide();
        },
    });
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
        <div class="ai-workspace-shell">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 flex-shrink-0">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="h4 fw-bold mb-0">
                        <i class="bi bi-stars text-primary me-1"></i>Assistant IA Diagnostic Automobile
                    </h1>
                    <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">
                        100 % Automobile
                    </span>
                    <span class="badge rounded-pill bg-light text-dark border">
                        <i class="bi bi-speedometer2 text-primary me-1"></i>
                        {{ dailyQuota > 0 ? `${usedToday} / ${dailyQuota} aujourd'hui` : `${usedToday} aujourd'hui (illimité)` }}
                    </span>
                </div>
                <p class="text-muted small mb-0 mt-1">
                    Texte, photos (OBD, voyants, pièces) et messages vocaux d'atelier — historique privé et isolé pour votre garage
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

        <div v-if="prefillRepairOrder" class="alert alert-info border-0 shadow-sm small py-2 mb-3 d-flex align-items-center justify-content-between">
            <span>
                <i class="bi bi-wrench-adjustable me-1"></i>
                Diagnostic lancé depuis l'ordre de réparation <strong>{{ prefillRepairOrder.order_number }}</strong> — le véhicule et les notes ont été pré-remplis.
            </span>
        </div>

        <div class="row g-3 ai-workspace-grid">
            <!-- Historique privé des conversations du garage -->
            <div class="col-lg-4 col-xl-3 ai-workspace-col">
                <div class="card border-0 shadow-sm ai-sidebar-card d-flex flex-column">
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
                            <div class="flex-grow-1" style="min-width: 0;">
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
            <div class="col-lg-8 col-xl-9 ai-workspace-col">
                <div class="card border-0 shadow-sm ai-chat-card d-flex flex-column">
                    <!-- Barre de contexte véhicule + historique -->
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 440px;">
                            <i class="bi bi-car-front-fill text-primary"></i>
                            <select v-model="form.vehicle_id" class="form-select form-select-sm">
                                <option value="">-- Véhicule concerné (historique auto injecté) --</option>
                                <option v-for="v in vehicles" :key="v.id" :value="v.id">
                                    {{ v.license_plate }} — {{ v.brand }} {{ v.model }} {{ v.year ? `(${v.year})` : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-muted">
                            <span v-if="form.vehicle_id" class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-clock-history me-1"></i>Historique véhicule actif
                            </span>
                            <span>
                                <i class="bi bi-shield-check text-success me-1"></i>{{ modelName }}
                            </span>
                        </div>
                    </div>

                    <!-- Fil des messages -->
                    <div ref="chatContainer" class="card-body p-3 ai-messages-area flex-grow-1">
                        <div v-if="!activeConversation?.messages?.length" class="text-center py-4 px-2">
                            <div class="ai-hero-icon mx-auto mb-3">
                                <i class="bi bi-wrench-adjustable-circle-fill"></i>
                            </div>
                            <h2 class="h5 fw-bold mb-1">Comment puis-je vous aider à l'atelier ?</h2>
                            <p class="text-muted small mx-auto mb-4" style="max-width: 580px;">
                                Décrivez un symptôme, joignez jusqu'à <strong>4 photos</strong> (voyant, valise OBD, pièce usée) ou enregistrez un <strong>message vocal</strong> mains libres.
                                <br />
                                <span class="text-primary fw-medium">Spécialisé 100 % automobile • Photos &amp; Audio uniquement (vidéos interdites).</span>
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

                                    <!-- Photos jointes (cliquables dans la Lightbox globale) -->
                                    <div v-if="msg.photo_urls?.length" class="d-flex flex-wrap gap-2 mb-2">
                                        <button
                                            v-for="(url, pIdx) in msg.photo_urls"
                                            :key="pIdx"
                                            type="button"
                                            class="p-0 border-0 bg-transparent"
                                            style="cursor: zoom-in;"
                                            title="Agrandir la photo"
                                            @click="openLightbox(msg.photo_urls, pIdx, 'Photo diagnostic IA')"
                                        >
                                            <img :src="url" alt="Photo diagnostic" class="rounded border shadow-sm" style="width: 78px; height: 78px; object-fit: cover;" />
                                        </button>
                                    </div>

                                    <!-- Lecteur audio pour message vocal enregistré en BDD -->
                                    <div v-if="msg.audio_url" class="mb-2">
                                        <audio :src="msg.audio_url" controls class="w-100" style="height: 36px; min-width: 220px;"></audio>
                                    </div>

                                    <div class="ai-message-text small">{{ msg.content }}</div>

                                    <!-- Actions métier 1-clic sous chaque réponse valide de l'IA -->
                                    <div
                                        v-if="msg.role === 'assistant' && !msg.is_off_topic"
                                        class="mt-3 pt-2 border-top d-flex flex-wrap gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm py-1 px-2"
                                            style="font-size: 0.74rem;"
                                            @click="createQuoteFromAi(msg)"
                                        >
                                            <i class="bi bi-file-earmark-plus me-1"></i>Créer brouillon Devis
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm py-1 px-2"
                                            style="font-size: 0.74rem;"
                                            @click="openApplyRoModal(msg)"
                                        >
                                            <i class="bi bi-wrench-adjustable me-1"></i>Ajouter à un OR
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-success btn-sm py-1 px-2"
                                            style="font-size: 0.74rem;"
                                            @click="openExplainClientModal(msg)"
                                        >
                                            <i class="bi bi-whatsapp me-1"></i>Expliquer au client
                                            <i v-if="msg.client_whatsapp_sent_at" class="bi bi-check2-all ms-1"></i>
                                        </button>
                                    </div>
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

                    <!-- Zone de saisie (Texte + Photos + Vocal) -->
                    <div class="card-footer bg-white border-top p-3">
                        <div v-if="mediaWarning" class="alert alert-warning py-1 px-2 small mb-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-triangle me-1"></i>{{ mediaWarning }}</span>
                            <button type="button" class="btn-close btn-sm" @click="mediaWarning = ''"></button>
                        </div>

                        <!-- Aperçu des photos sélectionnées -->
                        <div v-if="photoPreviews.length" class="d-flex flex-wrap gap-2 mb-2">
                            <div v-for="(p, idx) in photoPreviews" :key="idx" class="position-relative">
                                <img :src="p.url" :alt="p.name" class="rounded border" style="width: 58px; height: 58px; object-fit: cover;" />
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm p-0 rounded-circle position-absolute top-0 end-0"
                                    style="width: 18px; height: 18px; font-size: 0.65rem; line-height: 1;"
                                    @click="removePhoto(idx)"
                                >
                                    ×
                                </button>
                            </div>
                        </div>

                        <!-- Aperçu du vocal enregistré -->
                        <div v-if="recordedAudioUrl" class="d-flex align-items-center gap-2 p-2 mb-2 rounded bg-light border">
                            <i class="bi bi-mic-fill text-danger"></i>
                            <audio :src="recordedAudioUrl" controls class="flex-grow-1" style="height: 32px;"></audio>
                            <span class="badge bg-secondary">{{ formatSeconds(form.audio_duration) }}</span>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" @click="clearRecordedAudio">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>

                        <!-- Indicateur enregistrement vocal en cours -->
                        <div v-if="isRecording" class="alert alert-danger py-2 px-3 small mb-2 d-flex justify-content-between align-items-center">
                            <span class="d-flex align-items-center gap-2 fw-semibold">
                                <span class="ai-rec-dot"></span>
                                Enregistrement vocal en cours... {{ formatSeconds(recordingSeconds) }}
                            </span>
                            <button type="button" class="btn btn-danger btn-sm py-0 px-3" @click="stopRecording">
                                <i class="bi bi-stop-fill me-1"></i>Arrêter
                            </button>
                        </div>

                        <form @submit.prevent="sendPrompt">
                            <input
                                ref="photoInputRef"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/gif"
                                multiple
                                class="d-none"
                                @change="onPhotosSelected"
                            />
                            <div class="input-group">
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    title="Joindre des photos (voyant, OBD, pièce — max 4, pas de vidéo)"
                                    :disabled="form.processing || form.photos.length >= 4"
                                    @click="photoInputRef?.click()"
                                >
                                    <i class="bi bi-camera-fill"></i>
                                </button>
                                <button
                                    type="button"
                                    class="btn"
                                    :class="isRecording ? 'btn-danger' : 'btn-outline-secondary'"
                                    :title="isRecording ? 'Arrêter l\'enregistrement vocal' : 'Enregistrer un message vocal atelier'"
                                    :disabled="form.processing"
                                    @click="toggleRecording"
                                >
                                    <i class="bi" :class="isRecording ? 'bi-stop-circle-fill' : 'bi-mic-fill'"></i>
                                </button>
                                <textarea
                                    v-model="form.prompt"
                                    rows="2"
                                    class="form-control"
                                    :class="{ 'is-invalid': form.errors.prompt || form.errors.photos || form.errors.audio }"
                                    placeholder="Décrivez la panne, un code OBD-II, joignez une photo ou enregistrez un vocal..."
                                    :disabled="form.processing"
                                    @keydown="handleKeydown"
                                ></textarea>
                                <button
                                    type="submit"
                                    class="btn btn-primary px-4 fw-semibold"
                                    :disabled="!canSend"
                                >
                                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="bi bi-send-fill me-1"></i>Envoyer
                                </button>
                            </div>
                            <div v-if="form.errors.prompt" class="text-danger small mt-1">{{ form.errors.prompt }}</div>
                            <div v-if="form.errors.photos" class="text-danger small mt-1">{{ form.errors.photos }}</div>
                            <div v-if="form.errors.audio" class="text-danger small mt-1">{{ form.errors.audio }}</div>
                            <div class="d-flex justify-content-between align-items-center mt-2 text-muted" style="font-size: 0.72rem;">
                                <span><i class="bi bi-info-circle me-1"></i>Photos &amp; vocaux enregistrés en BDD (vidéos interdites) • Vérification physique indispensable.</span>
                                <span class="d-none d-md-inline">Entrée : envoyer • Maj+Entrée : saut de ligne</span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Modale : Ajouter le diagnostic à un Ordre de Réparation -->
        <StaticModal id="modalApplyAiToRo" title="Ajouter le diagnostic à un Ordre de Réparation">
            <form @submit.prevent="submitApplyToRo">
                <div v-if="!activeRepairOrders.length" class="alert alert-warning small">
                    Aucun ordre de réparation actif trouvé dans votre garage.
                </div>
                <div v-else class="mb-4">
                    <label class="form-label small fw-semibold">Ordre de réparation cible</label>
                    <select v-model="applyRoForm.repair_order_id" class="form-select" required>
                        <option value="">-- Choisir un OR --</option>
                        <option v-for="ro in activeRepairOrders" :key="ro.id" :value="ro.id">
                            {{ ro.order_number }} — {{ ro.customer?.name }} ({{ ro.vehicle?.license_plate }}) [{{ ro.status }}]
                        </option>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="applyRoForm.processing || !activeRepairOrders.length">
                        <i class="bi bi-check2 me-1"></i>Enregistrer dans l'OR
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Modale : Expliquer au client & Envoyer sur WhatsApp -->
        <StaticModal id="modalExplainClient" title="Explication client prête pour WhatsApp" size="modal-lg">
            <form @submit.prevent="submitClientWhatsapp">
                <div v-if="loadingClientSummary" class="text-center py-4 text-muted small">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <div>Rédaction d'une explication claire et rassurante pour le client...</div>
                </div>
                <template v-else>
                    <div v-if="explainError" class="alert alert-danger small">{{ explainError }}</div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Numéro WhatsApp du client</label>
                        <input v-model="clientWhatsappForm.phone" type="text" class="form-control" placeholder="+237 6XX XXX XXX" required />
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Message vulgarisé pour le client</label>
                            <button
                                v-if="explainingMessage"
                                type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none"
                                @click="fetchClientSummary(explainingMessage.id, true)"
                            >
                                <i class="bi bi-arrow-clockwise me-1"></i>Régénérer
                            </button>
                        </div>
                        <textarea v-model="clientWhatsappForm.summary" rows="6" class="form-control" required></textarea>
                    </div>
                </template>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="submit" class="btn btn-success px-4" :disabled="loadingClientSummary || clientWhatsappForm.processing || !clientWhatsappForm.summary">
                        <i class="bi bi-whatsapp me-1"></i>Envoyer au client sur WhatsApp
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>

<style scoped>
@media (min-width: 992px) {
    .ai-workspace-shell {
        position: sticky;
        top: 72px;
        height: calc(100vh - 96px);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .ai-workspace-grid {
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
    }

    .ai-workspace-col {
        height: 100%;
        min-height: 0;
    }

    .ai-sidebar-card,
    .ai-chat-card {
        height: 100%;
        min-height: 0;
        overflow: hidden;
    }
}

@media (max-width: 991.98px) {
    .ai-sidebar-card {
        max-height: 260px;
    }

    .ai-chat-card {
        height: calc(100vh - 140px);
        min-height: 460px;
    }
}

.ai-conv-list {
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
}

.ai-messages-area {
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
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
    max-width: 88%;
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

.ai-rec-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: #dc2626;
    display: inline-block;
    animation: recPulse 1s infinite;
}

@keyframes recPulse {
    0%, 100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.35;
        transform: scale(0.85);
    }
}
</style>
