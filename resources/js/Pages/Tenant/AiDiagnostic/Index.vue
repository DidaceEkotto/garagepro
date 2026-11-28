<script setup>
import PhoneInput from '@/Components/PhoneInput.vue';
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
    modelName: { type: String, default: 'gemini-flash-lite-latest' },
});

const { askConfirm } = useConfirm();
const { openLightbox } = useLightbox();
const chatContainer = ref(null);
const photoInputRef = ref(null);
const cameraInputRef = ref(null);
const docFileInputRef = ref(null);
const ecuFileInputRef = ref(null);
const obdFileInputRef = ref(null);
const attachMenuRef = ref(null);
const attachMenuOpen = ref(false);
const copiedId = ref(null);
const mediaWarning = ref('');

const FORBIDDEN_VIDEO_EXTS = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp', 'mpeg', 'mpg'];
const FORBIDDEN_ARCHIVE_EXTS = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'iso', 'cab', 'zst'];
const FORBIDDEN_EXEC_EXTS = ['exe', 'dll', 'bat', 'cmd', 'sh', 'php', 'phtml', 'js', 'mjs', 'html', 'htm', 'svg', 'msi', 'apk', 'ps1'];
const ALLOWED_TECH_EXTS = [
    'bin', 'ori', 'mod', 'ols', 'kp', 'mappack', 'hex', 's19', 'srec', 'a2l',
    'dbc', 'ldf', 'arxml', 'blf', 'asc', 'trc',
    'csv', 'mdf', 'mf4', 'log', 'vbo', 'gpx',
    'xml', 'json', 'sql', 'sqlite', 'db', 'txt', 'pdf',
];

const form = useForm({
    conversation_id: props.activeConversation?.id ?? null,
    vehicle_id: props.prefillRepairOrder?.vehicle_id ?? props.activeConversation?.vehicle_id ?? '',
    repair_order_id: props.prefillRepairOrder?.id ?? props.activeConversation?.repair_order_id ?? '',
    prompt: props.prefillRepairOrder?.suggested_prompt ?? '',
    photos: [],
    files: [],
    audio: null,
    audio_duration: 0,
});

function getFileExtension(name) {
    const parts = String(name || '').toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
}

function toggleAttachMenu() {
    attachMenuOpen.value = !attachMenuOpen.value;
}

function closeAttachMenu() {
    attachMenuOpen.value = false;
}

const ecuDiffForm = useForm({
    ori_file: null,
    mod_file: null,
    ask_ai: true,
    conversation_id: props.activeConversation?.id ?? null,
    vehicle_id: props.prefillRepairOrder?.vehicle_id ?? props.activeConversation?.vehicle_id ?? '',
});

const acousticForm = useForm({
    zone: 'moteur_haut',
    condition: 'ralenti',
    description: '',
    audio: null,
    conversation_id: props.activeConversation?.id ?? null,
    vehicle_id: props.prefillRepairOrder?.vehicle_id ?? props.activeConversation?.vehicle_id ?? '',
});

function openAcousticModal() {
    closeAttachMenu();
    acousticForm.conversation_id = form.conversation_id;
    acousticForm.vehicle_id = form.vehicle_id;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalAcousticDiag'))?.show();
}

function submitAcousticDiag() {
    acousticForm.post('/assistant-ia/diagnostic-acoustique', {
        forceFormData: true,
        onSuccess: () => {
            acousticForm.reset('description', 'audio');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAcousticDiag'))?.hide();
        },
    });
}

function openEcuDiffModal() {
    closeAttachMenu();
    ecuDiffForm.conversation_id = form.conversation_id;
    ecuDiffForm.vehicle_id = form.vehicle_id;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalCompareEcu'))?.show();
}

function submitEcuDiff() {
    if (!ecuDiffForm.ori_file || !ecuDiffForm.mod_file) return;
    ecuDiffForm.post('/assistant-ia/comparer-ecu', {
        forceFormData: true,
        onSuccess: () => {
            ecuDiffForm.reset('ori_file', 'mod_file');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalCompareEcu'))?.hide();
        },
    });
}

function triggerAttach(kind) {
    closeAttachMenu();
    if (kind === 'doc') docFileInputRef.value?.click();
    else if (kind === 'photo') photoInputRef.value?.click();
    else if (kind === 'camera') cameraInputRef.value?.click();
    else if (kind === 'ecu') ecuFileInputRef.value?.click();
    else if (kind === 'obd') obdFileInputRef.value?.click();
    else if (kind === 'audio') toggleRecording();
}

function handleDocumentClick(e) {
    if (attachMenuOpen.value && attachMenuRef.value && !attachMenuRef.value.contains(e.target)) {
        attachMenuOpen.value = false;
    }
}

function onTechFilesSelected(e) {
    mediaWarning.value = '';
    const picked = Array.from(e.target.files || []);
    if (!picked.length) return;

    const valid = [];
    for (const f of picked) {
        const ext = getFileExtension(f.name);
        if (f.type.startsWith('video/') || FORBIDDEN_VIDEO_EXTS.includes(ext)) {
            mediaWarning.value = 'Les vidéos sont interdites dans l\'Assistant IA (utilisez des photos, logs ou fichiers techniques).';
            continue;
        }
        if (FORBIDDEN_ARCHIVE_EXTS.includes(ext)) {
            mediaWarning.value = 'Les fichiers compressés (.zip, .rar, .7z, .tar, .gz) sont refusés. Extrayez le fichier automobile avant envoi.';
            continue;
        }
        if (FORBIDDEN_EXEC_EXTS.includes(ext)) {
            mediaWarning.value = `Le format .${ext} est bloqué par sécurité.`;
            continue;
        }
        if (!ALLOWED_TECH_EXTS.includes(ext)) {
            mediaWarning.value = `Format .${ext || '?'} non reconnu. Formats auto acceptés : ECU (.bin/.ori/.mod/.ols/.hex/.a2l), CAN (.dbc/.asc/.trc/.blf), OBD (.csv/.log/.mdf/.mf4), PDF/XML/JSON/SQLite.`;
            continue;
        }
        if (f.size > 15 * 1024 * 1024) {
            mediaWarning.value = `Le fichier « ${f.name} » dépasse la limite de 15 Mo.`;
            continue;
        }
        valid.push(f);
    }

    if (form.files.length + valid.length > 3) {
        mediaWarning.value = 'Maximum 3 fichiers techniques par message.';
    }

    form.files = [...form.files, ...valid].slice(0, 3);
    if (e.target) e.target.value = '';
}

function removeTechFile(idx) {
    form.files.splice(idx, 1);
}

function formatFileSize(bytes) {
    if (!bytes) return '0 Ko';
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
    return `${Math.max(1, Math.round(bytes / 1024))} Ko`;
}

function getFileAttachments(msg) {
    if (!Array.isArray(msg?.attachments)) return [];
    return msg.attachments.filter((a) => a && a.type === 'file');
}

const firstFileError = computed(() => {
    if (form.errors.files) return form.errors.files;
    const key = Object.keys(form.errors || {}).find((k) => k.startsWith('files.'));
    return key ? form.errors[key] : '';
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
    !form.processing
    && !isRecording.value
    && (form.prompt.trim().length > 0 || form.photos.length > 0 || form.files.length > 0 || Boolean(form.audio))
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

onMounted(() => {
    scrollToBottom();
    document.addEventListener('click', handleDocumentClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleDocumentClick);
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
            form.reset('prompt', 'photos', 'files', 'audio', 'audio_duration');
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

function submitFeedback(msg, isResolved) {
    router.post(
        `/assistant-ia/messages/${msg.id}/feedback`,
        { is_resolved: isResolved },
        { preserveScroll: true }
    );
}

function retryOnline(assistantMsg) {
    const msgs = props.activeConversation?.messages ?? [];
    const idx = msgs.findIndex((m) => m.id === assistantMsg.id);
    if (idx <= 0) return;
    const prevUserMsg = [...msgs.slice(0, idx)].reverse().find((m) => m.role === 'user');
    if (!prevUserMsg?.content) return;

    router.post(
        '/assistant-ia/messages',
        {
            conversation_id: props.activeConversation?.id,
            vehicle_id: form.vehicle_id || props.activeConversation?.vehicle_id || null,
            prompt: prevUserMsg.content,
            force_online: true,
        },
        { preserveScroll: true }
    );
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
                                            <span v-else-if="msg.matched_from_message_id || msg.model === 'mboacar-kb' || msg.model === 'mboacar-obd'" class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-database-check me-1"></i>{{ msg.model === 'mboacar-obd' ? 'Référentiel OBD MboaCar (0 token)' : 'Base MboaCar vérifiée (0 token)' }}
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

                                    <!-- Fichiers techniques automobiles joints (ECU, CAN, OBD, PDF, BDD) -->
                                    <div v-if="getFileAttachments(msg).length" class="d-flex flex-wrap gap-2 mb-2">
                                        <div
                                            v-for="(att, fIdx) in getFileAttachments(msg)"
                                            :key="fIdx"
                                            class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded border"
                                            :class="msg.role === 'user' ? 'bg-white bg-opacity-25 border-white border-opacity-25 text-white' : 'bg-light border-secondary-subtle text-dark'"
                                            style="font-size: 0.74rem;"
                                        >
                                            <i class="bi bi-file-earmark-code-fill"></i>
                                            <span class="fw-semibold text-truncate" style="max-width: 190px;">{{ att.name }}</span>
                                            <span class="badge" :class="msg.role === 'user' ? 'bg-light text-primary' : 'bg-primary-subtle text-primary'">.{{ att.ext }}</span>
                                            <span v-if="att.size_kb" class="opacity-75">{{ att.size_kb }} Ko</span>
                                        </div>
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

                                    <!-- Retour garagiste : MboaCar IA Auto a-t-il résolu votre problème ? -->
                                    <div
                                        v-if="msg.role === 'assistant' && !msg.is_off_topic"
                                        class="mt-2 pt-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2"
                                        style="font-size: 0.75rem;"
                                    >
                                        <span class="text-muted">
                                            <i class="bi bi-question-circle me-1"></i>MboaCar IA Auto a-t-il résolu votre problème ?
                                        </span>
                                        <div class="d-flex flex-wrap align-items-center gap-1">
                                            <button
                                                type="button"
                                                class="btn btn-sm py-0 px-2"
                                                :class="msg.is_resolved === true ? 'btn-success' : 'btn-outline-success'"
                                                style="font-size: 0.72rem;"
                                                @click="submitFeedback(msg, true)"
                                            >
                                                <i class="bi bi-hand-thumbs-up-fill me-1"></i>Oui, résolu
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm py-0 px-2"
                                                :class="msg.is_resolved === false ? 'btn-danger' : 'btn-outline-danger'"
                                                style="font-size: 0.72rem;"
                                                @click="submitFeedback(msg, false)"
                                            >
                                                <i class="bi bi-hand-thumbs-down-fill me-1"></i>Non
                                            </button>
                                            <button
                                                v-if="msg.is_resolved === false && (msg.matched_from_message_id || msg.model === 'mboacar-kb' || msg.model === 'mboacar-obd')"
                                                type="button"
                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                style="font-size: 0.72rem;"
                                                @click="retryOnline(msg)"
                                            >
                                                <i class="bi bi-cloud-arrow-down me-1"></i>Rechercher en ligne (Gemini)
                                            </button>
                                        </div>
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

                        <!-- Aperçu des fichiers techniques sélectionnés (ECU, CAN, OBD, PDF, BDD) -->
                        <div v-if="form.files.length" class="d-flex flex-wrap gap-2 mb-2">
                            <div
                                v-for="(f, idx) in form.files"
                                :key="idx"
                                class="d-inline-flex align-items-center gap-2 px-2 py-1 rounded bg-light border small"
                            >
                                <i class="bi bi-cpu-fill text-primary"></i>
                                <span class="fw-semibold text-truncate" style="max-width: 180px;">{{ f.name }}</span>
                                <span class="badge bg-primary-subtle text-primary">.{{ getFileExtension(f.name) }}</span>
                                <span class="text-muted" style="font-size: 0.7rem;">{{ formatFileSize(f.size) }}</span>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm p-0 text-danger lh-1"
                                    title="Retirer le fichier"
                                    @click="removeTechFile(idx)"
                                >
                                    <i class="bi bi-x-circle-fill"></i>
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
                            <input
                                ref="cameraInputRef"
                                type="file"
                                accept="image/*"
                                capture="environment"
                                class="d-none"
                                @change="onPhotosSelected"
                            />
                            <input
                                ref="docFileInputRef"
                                type="file"
                                accept=".pdf,.txt,.xml,.json,.sql,.sqlite,.db"
                                multiple
                                class="d-none"
                                @change="onTechFilesSelected"
                            />
                            <input
                                ref="ecuFileInputRef"
                                type="file"
                                accept=".bin,.ori,.mod,.ols,.kp,.mappack,.hex,.s19,.srec,.a2l"
                                multiple
                                class="d-none"
                                @change="onTechFilesSelected"
                            />
                            <input
                                ref="obdFileInputRef"
                                type="file"
                                accept=".csv,.log,.dbc,.ldf,.arxml,.blf,.asc,.trc,.mdf,.mf4,.vbo,.gpx"
                                multiple
                                class="d-none"
                                @change="onTechFilesSelected"
                            />
                            <div class="d-flex align-items-stretch gap-2">
                                <!-- Bouton trombone style WhatsApp avec menu popover -->
                                <div ref="attachMenuRef" class="position-relative d-flex">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary d-flex align-items-center justify-content-center px-3"
                                        :class="{ 'active bg-light': attachMenuOpen }"
                                        title="Joindre un fichier automobile, une photo, la caméra ou un audio"
                                        :disabled="form.processing"
                                        @click.stop="toggleAttachMenu"
                                    >
                                        <i class="bi bi-paperclip fs-5"></i>
                                    </button>

                                    <div v-if="attachMenuOpen" class="ai-attach-popover shadow-lg border" @click.stop>
                                        <div class="ai-attach-grid">
                                            <button type="button" class="ai-attach-item" @click="triggerAttach('doc')">
                                                <span class="ai-attach-icon" style="color: #7f66ff;"><i class="bi bi-file-earmark-text-fill"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Document &amp; BDD</span>
                                                    <span class="ai-attach-sub">PDF, XML, JSON, SQL, SQLite, TXT</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" :disabled="form.photos.length >= 4" @click="triggerAttach('photo')">
                                                <span class="ai-attach-icon" style="color: #007bfc;"><i class="bi bi-images"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Photos</span>
                                                    <span class="ai-attach-sub">Voyants, pièces, valise (max 4)</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" :disabled="form.photos.length >= 4" @click="triggerAttach('camera')">
                                                <span class="ai-attach-icon" style="color: #ff2e74;"><i class="bi bi-camera-fill"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Caméra</span>
                                                    <span class="ai-attach-sub">Prendre une photo directe</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" @click="triggerAttach('audio')">
                                                <span class="ai-attach-icon" style="color: #fa6533;"><i class="bi bi-headphones"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Audio atelier</span>
                                                    <span class="ai-attach-sub">Bruit moteur ou note vocale</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" :disabled="form.files.length >= 3" @click="triggerAttach('ecu')">
                                                <span class="ai-attach-icon" style="color: #00a884;"><i class="bi bi-cpu-fill"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">ECU / Cartographie</span>
                                                    <span class="ai-attach-sub">.bin, .ori, .mod, .ols, .kp, .hex, .a2l</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" :disabled="form.files.length >= 3" @click="triggerAttach('obd')">
                                                <span class="ai-attach-icon" style="color: #f59e0b;"><i class="bi bi-activity"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">OBD-II &amp; Réseau CAN</span>
                                                    <span class="ai-attach-sub">.csv, .log, .dbc, .asc, .trc, .blf, .mf4</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" @click="openEcuDiffModal">
                                                <span class="ai-attach-icon" style="color: #0ea5e9;"><i class="bi bi-file-diff-fill"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Comparer ECU (.ori vs .mod)</span>
                                                    <span class="ai-attach-sub">Diff local 0 token + risques IA</span>
                                                </span>
                                            </button>
                                            <button type="button" class="ai-attach-item" @click="openAcousticModal">
                                                <span class="ai-attach-icon" style="color: #e11d48;"><i class="bi bi-soundwave"></i></span>
                                                <span class="d-flex flex-column text-start">
                                                    <span class="fw-medium">Diagnostic Acoustique IA</span>
                                                    <span class="ai-attach-sub">Bruit moteur/freins + Feu tricolore</span>
                                                </span>
                                            </button>
                                        </div>
                                        <div class="px-3 pt-2 pb-2 text-muted border-top" style="font-size: 0.7rem;">
                                            <i class="bi bi-shield-lock me-1"></i>Vidéos &amp; archives (.zip/.rar) bloquées
                                        </div>
                                    </div>
                                </div>

                                <div class="input-group flex-grow-1">
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
                                        :class="{ 'is-invalid': form.errors.prompt || form.errors.photos || form.errors.audio || firstFileError }"
                                        placeholder="Décrivez la panne, un code OBD-II, ou cliquez sur 📎 pour joindre un fichier ECU / CAN / OBD / photo..."
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
                            </div>
                            <div v-if="form.errors.prompt" class="text-danger small mt-1">{{ form.errors.prompt }}</div>
                            <div v-if="form.errors.photos" class="text-danger small mt-1">{{ form.errors.photos }}</div>
                            <div v-if="firstFileError" class="text-danger small mt-1">{{ firstFileError }}</div>
                            <div v-if="form.errors.audio" class="text-danger small mt-1">{{ form.errors.audio }}</div>
                            <div class="d-flex justify-content-between align-items-center mt-2 text-muted" style="font-size: 0.72rem;">
                                <span><i class="bi bi-cpu me-1"></i>Pré-analyse locale anti-tokens active • Fichiers auto uniquement (vidéos &amp; .zip/.rar interdits).</span>
                                <span class="d-none d-md-inline">Entrée : envoyer • Maj+Entrée : saut de ligne</span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Modale : Diagnostic Acoustique & Gravité IA -->
        <StaticModal id="modalAcousticDiag" title="Diagnostic Acoustique IA — Analyse des Bruits">
            <form @submit.prevent="submitAcousticDiag">
                <p class="small text-muted mb-3">
                    Décrivez ou importez l'enregistrement audio d'un bruit suspect : l'IA évalue la gravité (🟢 Vert / 🟠 Orange / 🔴 Arrêt immédiat) et identifie les 3 causes les plus probables.
                </p>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Zone du bruit</label>
                        <select v-model="acousticForm.zone" class="form-select no-select2" required>
                            <option value="moteur_haut">Haut moteur (injecteurs, soupapes)</option>
                            <option value="bas_moteur">Bas moteur (bielles, vilebrequin)</option>
                            <option value="courroie_poulies">Courroie / Poulies / Alternateur</option>
                            <option value="turbo">Turbo / Admission (sifflement)</option>
                            <option value="freins_roues">Freins / Roulements / Roues</option>
                            <option value="suspension">Train avant / Suspension</option>
                            <option value="echappement">Échappement / Catalyseur</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Quand apparaît le bruit ?</label>
                        <select v-model="acousticForm.condition" class="form-select no-select2" required>
                            <option value="ralenti">Au ralenti</option>
                            <option value="acceleration">À l'accélération</option>
                            <option value="freinage">Au freinage</option>
                            <option value="virage">En tournant / Dos d'âne</option>
                            <option value="froid">À froid uniquement</option>
                            <option value="chaud">À chaud uniquement</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Description du bruit (claquement, sifflement, grincement...)</label>
                    <textarea v-model="acousticForm.description" rows="2" class="form-control" placeholder="Ex: Claquement métallique régulier qui accélère avec le régime moteur..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Enregistrement audio du bruit (facultatif — .webm, .mp3, .wav, .m4a)</label>
                    <input type="file" accept="audio/*" class="form-control form-control-sm" @change="(e) => (acousticForm.audio = e.target.files?.[0] || null)" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger px-4" :disabled="acousticForm.processing">
                        <span v-if="acousticForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                        Analyser le bruit
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Modale : Comparateur ECU .ori vs .mod (0 token en local + interprétation risques IA) -->
        <StaticModal id="modalCompareEcu" title="Comparateur ECU .ori vs .mod (0 token)">
            <form @submit.prevent="submitEcuDiff">
                <p class="small text-muted mb-3">
                    Déposez le fichier d'origine (<code>.ori/.bin</code>) et le fichier reprogrammé (<code>.mod/.bin</code>).
                    Le calcul des offsets modifiés, du pourcentage de diff et des checksums CRC32/SHA-256 s'effectue localement sans consommer de tokens.
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Fichier Origine (.ori / .bin)</label>
                    <input type="file" accept=".ori,.bin,.ols,.hex,.s19,.srec" class="form-control" required @change="(e) => (ecuDiffForm.ori_file = e.target.files?.[0] || null)" />
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Fichier Modifié (.mod / .bin)</label>
                    <input type="file" accept=".mod,.bin,.ols,.hex,.s19,.srec" class="form-control" required @change="(e) => (ecuDiffForm.mod_file = e.target.files?.[0] || null)" />
                </div>
                <div class="form-check mb-4">
                    <input id="ecuAskAi" v-model="ecuDiffForm.ask_ai" type="checkbox" class="form-check-input" />
                    <label class="form-check-label small" for="ecuAskAi">
                        Demander aussi à MboaCar IA Auto d'interpréter les risques de flashage sur le résumé compact
                    </label>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="ecuDiffForm.processing || !ecuDiffForm.ori_file || !ecuDiffForm.mod_file">
                        <span v-if="ecuDiffForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                        Lancer la comparaison
                    </button>
                </div>
            </form>
        </StaticModal>

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
                        <PhoneInput v-model="clientWhatsappForm.phone" required />
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
        overflow: visible;
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

.ai-attach-popover {
    position: absolute;
    bottom: calc(100% + 10px);
    left: 0;
    width: 500px;
    max-width: calc(100vw - 32px);
    max-height: 70vh;
    overflow-y: auto;
    background: #ffffff;
    border-radius: 16px;
    padding: 8px 6px 0;
    z-index: 1060;
    animation: popoverFadeIn 0.14s ease-out;
}

.ai-attach-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 2px;
}

@media (max-width: 575.98px) {
    .ai-attach-popover {
        width: 280px;
    }
    .ai-attach-grid {
        grid-template-columns: 1fr;
    }
}

.ai-attach-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 9px 14px;
    border: 0;
    background: transparent;
    color: #0f172a;
    font-size: 0.87rem;
    transition: background-color 0.12s ease;
    cursor: pointer;
}

.ai-attach-item:hover:not(:disabled) {
    background-color: #f1f5f9;
}

.ai-attach-item:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.ai-attach-icon {
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.ai-attach-sub {
    font-size: 0.69rem;
    color: #64748b;
    line-height: 1.2;
}

@keyframes popoverFadeIn {
    from {
        opacity: 0;
        transform: translateY(6px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
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
