<script setup>
import { useLightbox } from '@/composables/useLightbox';
import { Link } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const emit = defineEmits(['unread-change']);
const { openLightbox } = useLightbox();

const subView = ref('chat'); // chat | sos
const loading = ref(true);
const sending = ref(false);
const errorMsg = ref('');
const searchQuery = ref('');
const typeFilter = ref('');
const sosCityFilter = ref('');
const b2bVisible = ref(true);
const myCity = ref('');

const directory = ref([]);
const conversations = ref([]);
const activeConversation = ref(null);
const activeConvId = ref(null);
const messages = ref([]);
const sosRequests = ref([]);
const shareable = ref({ vehicles: [], repair_orders: [], technical_pvs: [] });

// Formulaire message
const body = ref('');
const isUrgent = ref(false);
const shareType = ref('');
const shareId = ref('');
const files = ref([]);
const fileInputRef = ref(null);
const chatBodyRef = ref(null);

// Note vocale atelier (audio uniquement)
const isRecording = ref(false);
const recordingSec = ref(0);
const recordedAudioFile = ref(null);
const recordedAudioUrl = ref('');
let mediaRecorder = null;
let audioStream = null;
let audioChunks = [];
let recTimer = null;

// Formulaire Bourse d'entraide SOS
const sosForm = ref({
    category: 'piece',
    title: '',
    description: '',
    city: '',
    is_urgent: false,
});
const sosSubmitting = ref(false);

let pollTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function scrollToBottom() {
    nextTick(() => {
        if (chatBodyRef.value) {
            chatBodyRef.value.scrollTop = chatBodyRef.value.scrollHeight;
        }
    });
}

async function fetchState(silent = false) {
    if (!silent) loading.value = true;
    try {
        const params = new URLSearchParams();
        if (searchQuery.value.trim()) params.set('q', searchQuery.value.trim());
        if (typeFilter.value) params.set('type', typeFilter.value);
        if (sosCityFilter.value.trim()) params.set('sos_city', sosCityFilter.value.trim());
        if (activeConvId.value) params.set('conversation_id', String(activeConvId.value));

        const res = await fetch(`/reseau-pro/etat?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok) return;

        b2bVisible.value = Boolean(data.b2b_directory_visible);
        myCity.value = data.my_city || '';
        if (!sosForm.value.city && myCity.value) sosForm.value.city = myCity.value;
        directory.value = data.directory || [];
        conversations.value = data.conversations || [];
        activeConversation.value = data.active_conversation || null;
        sosRequests.value = data.sos_requests || [];
        shareable.value = data.shareable || { vehicles: [], repair_orders: [], technical_pvs: [] };
        emit('unread-change', Number(data.total_unread || 0));

        const prevLen = messages.value.length;
        messages.value = data.messages || [];
        if (messages.value.length > prevLen || !silent) {
            scrollToBottom();
        }
    } finally {
        loading.value = false;
    }
}

async function openPartner(partnerId) {
    errorMsg.value = '';
    const res = await fetch('/reseau-pro/conversations', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        body: JSON.stringify({ target_tenant_id: partnerId }),
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
        errorMsg.value = data.error || 'Impossible d\'ouvrir la conversation.';
        return;
    }
    subView.value = 'chat';
    activeConvId.value = data.conversation_id;
    await fetchState(false);
}

async function selectConversation(convId) {
    activeConvId.value = convId;
    await fetchState(false);
}

async function toggleVisibility() {
    const nextVal = !b2bVisible.value;
    const res = await fetch('/reseau-pro/visibilite', {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            Accept: 'application/json',
        },
        body: JSON.stringify({ visible: nextVal }),
    });
    if (res.ok) b2bVisible.value = nextVal;
}

async function toggleBlockPeer() {
    const peer = activeConversation.value?.peer;
    if (!peer?.id) return;
    const isBlocked = Boolean(peer.is_blocked_by_me);
    const res = await fetch(`/reseau-pro/bloquer/${peer.id}`, {
        method: isBlocked ? 'DELETE' : 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
    });
    if (res.ok) {
        await fetchState(false);
    }
}

// Enregistreur vocal atelier
async function toggleRecording() {
    errorMsg.value = '';
    if (isRecording.value) {
        stopRecording();
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        errorMsg.value = 'Votre navigateur ne supporte pas les notes vocales.';
        return;
    }
    try {
        audioStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : (MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '');
        mediaRecorder = mime ? new MediaRecorder(audioStream, { mimeType: mime }) : new MediaRecorder(audioStream);
        audioChunks = [];
        recordingSec.value = 0;

        mediaRecorder.ondataavailable = (ev) => {
            if (ev.data?.size > 0) audioChunks.push(ev.data);
        };
        mediaRecorder.onstop = () => {
            const cleanMime = (mediaRecorder?.mimeType || 'audio/webm').split(';')[0];
            const ext = cleanMime.includes('ogg') ? 'ogg' : 'webm';
            const blob = new Blob(audioChunks, { type: cleanMime });
            if (recordedAudioUrl.value) URL.revokeObjectURL(recordedAudioUrl.value);
            recordedAudioUrl.value = URL.createObjectURL(blob);
            recordedAudioFile.value = new File([blob], `vocal-b2b-${Date.now()}.${ext}`, { type: cleanMime });
            if (audioStream) {
                audioStream.getTracks().forEach((t) => t.stop());
                audioStream = null;
            }
        };

        mediaRecorder.start();
        isRecording.value = true;
        recTimer = setInterval(() => {
            recordingSec.value += 1;
            if (recordingSec.value >= 120) stopRecording();
        }, 1000);
    } catch {
        errorMsg.value = 'Impossible d\'accéder au microphone.';
    }
}

function stopRecording() {
    if (recTimer) {
        clearInterval(recTimer);
        recTimer = null;
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
    }
    isRecording.value = false;
}

function clearAudio() {
    if (recordedAudioUrl.value) URL.revokeObjectURL(recordedAudioUrl.value);
    recordedAudioUrl.value = '';
    recordedAudioFile.value = null;
    recordingSec.value = 0;
}

function onFilesPicked(e) {
    files.value = Array.from(e.target.files || []).slice(0, 3);
    if (fileInputRef.value) fileInputRef.value.value = '';
}

async function sendMessage() {
    if (!activeConvId.value || sending.value) return;
    if (!body.value.trim() && !recordedAudioFile.value && !files.value.length) return;
    sending.value = true;
    errorMsg.value = '';

    try {
        const fd = new FormData();
        fd.append('body', body.value.trim());
        fd.append('is_urgent', isUrgent.value ? '1' : '0');
        if (shareType.value && shareId.value) {
            fd.append('share_type', shareType.value);
            fd.append('share_id', String(shareId.value));
        }
        files.value.forEach((f) => fd.append('attachments[]', f));
        if (recordedAudioFile.value) {
            fd.append('audio', recordedAudioFile.value);
            fd.append('audio_duration', String(recordingSec.value));
        }

        const res = await fetch(`/reseau-pro/conversations/${activeConvId.value}/messages`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            errorMsg.value = data.error || 'Envoi impossible.';
            return;
        }

        messages.value = [...messages.value, data.message];
        body.value = '';
        isUrgent.value = false;
        shareType.value = '';
        shareId.value = '';
        files.value = [];
        clearAudio();
        scrollToBottom();
        fetchState(true);
    } finally {
        sending.value = false;
    }
}

// Bourse d'entraide SOS
async function submitSos() {
    if (!sosForm.value.title.trim() || sosSubmitting.value) return;
    sosSubmitting.value = true;
    try {
        const res = await fetch('/reseau-pro/demandes', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify(sosForm.value),
        });
        if (res.ok) {
            sosForm.value.title = '';
            sosForm.value.description = '';
            sosForm.value.is_urgent = false;
            await fetchState(true);
        }
    } finally {
        sosSubmitting.value = false;
    }
}

async function resolveSos(id) {
    const res = await fetch(`/reseau-pro/demandes/${id}/resoudre`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
    });
    if (res.ok) await fetchState(true);
}

async function replySos(sos) {
    const res = await fetch(`/reseau-pro/demandes/${sos.id}/repondre`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
        errorMsg.value = data.error || 'Impossible de répondre à cette annonce.';
        return;
    }
    subView.value = 'chat';
    activeConvId.value = data.conversation_id;
    body.value = data.prefill || '';
    await fetchState(false);
}

function openImageLightbox(m, clickedIdx) {
    const imgs = (m.attachments || []).filter((a) => a.is_image && a.preview_url);
    const urls = imgs.map((a) => a.preview_url);
    const startIdx = Math.max(0, imgs.findIndex((a) => a.index === clickedIdx));
    if (urls.length) openLightbox(urls, startIdx, `Photo — ${m.sender_name}`);
}

function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

function categoryBadge(cat) {
    return {
        piece: { label: 'SOS Pièce', cls: 'bg-primary' },
        diagnostic: { label: 'Diagnostic / Valise', cls: 'bg-info text-dark' },
        sous_traitance: { label: 'Sous-traitance', cls: 'bg-warning text-dark' },
        outillage: { label: 'Outillage', cls: 'bg-secondary' },
    }[cat] || { label: cat, cls: 'bg-secondary' };
}

watch([searchQuery, typeFilter, sosCityFilter], () => fetchState(true));

onMounted(() => {
    fetchState(false);
    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') fetchState(true);
    }, 10000);
});

onBeforeUnmount(() => {
    stopRecording();
    clearAudio();
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <div>
        <!-- Barre interne Réseau Pro : Discussions vs Bourse d'entraide SOS -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn" :class="subView === 'chat' ? 'btn-primary' : 'btn-outline-primary'" @click="subView = 'chat'">
                    <i class="bi bi-chat-dots-fill me-1"></i>Discussions &amp; Annuaire ({{ conversations.length }})
                </button>
                <button type="button" class="btn" :class="subView === 'sos' ? 'btn-danger' : 'btn-outline-danger'" @click="subView = 'sos'">
                    <i class="bi bi-megaphone-fill me-1"></i>Bourse d'entraide SOS ({{ sosRequests.length }})
                </button>
            </div>
            <div class="form-check form-switch mb-0 small">
                <input id="b2bVisSwitch" class="form-check-input" type="checkbox" :checked="b2bVisible" @change="toggleVisibility" />
                <label class="form-check-label fw-semibold" for="b2bVisSwitch">Visible dans l'annuaire B2B</label>
            </div>
        </div>

        <!-- VUE 1 : Bourse d'entraide Express par ville (SOS Pièces & Sous-traitance) -->
        <div v-if="subView === 'sos'" class="row g-3">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3 small">
                        <i class="bi bi-plus-circle-fill text-danger me-1"></i>Publier une demande SOS Express
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitSos">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Catégorie</label>
                                <select v-model="sosForm.category" class="form-select form-select-sm no-select2">
                                    <option value="piece">SOS Pièce détachée rare</option>
                                    <option value="diagnostic">Diagnostic / Valise spécialisée</option>
                                    <option value="sous_traitance">Sous-traitance (Tôlerie, Peinture, ECU...)</option>
                                    <option value="outillage">Prêt / Location outillage</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Ville ciblée</label>
                                <input v-model="sosForm.city" type="text" class="form-control form-control-sm" placeholder="Ex: Douala, Yaoundé..." />
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Besoin (Titre court)</label>
                                <input v-model="sosForm.title" type="text" class="form-control form-control-sm" placeholder="Ex: Cherche injecteur 1.6 HDi d'occasion" required />
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold">Détails (réf OEM, véhicule, délai)</label>
                                <textarea v-model="sosForm.description" rows="2" class="form-control form-control-sm" placeholder="Précisez la référence ou le modèle..."></textarea>
                            </div>
                            <div class="form-check mb-3 small">
                                <input id="sosUrg" v-model="sosForm.is_urgent" type="checkbox" class="form-check-input" />
                                <label class="form-check-label text-danger fw-semibold" for="sosUrg">Demande urgente (véhicule immobilisé)</label>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm w-100 fw-semibold" :disabled="sosSubmitting">
                                <i class="bi bi-broadcast me-1"></i>Diffuser aux confrères
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="fw-bold small"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Demandes actives des confrères</span>
                        <input v-model="sosCityFilter" type="search" class="form-control form-control-sm" style="max-width: 200px;" placeholder="Filtrer par ville..." />
                    </div>
                    <div class="list-group list-group-flush">
                        <div v-if="!sosRequests.length" class="p-4 text-center text-muted small">
                            Aucune demande SOS active pour le moment. Publiez votre besoin à gauche !
                        </div>
                        <div v-for="r in sosRequests" :key="r.id" class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="badge me-1" :class="categoryBadge(r.category).cls">{{ categoryBadge(r.category).label }}</span>
                                    <span v-if="r.is_urgent" class="badge bg-danger me-1">URGENT</span>
                                    <span v-if="r.city" class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i>{{ r.city }}</span>
                                    <h3 class="h6 fw-bold mt-2 mb-1">{{ r.title }}</h3>
                                    <p v-if="r.description" class="small text-muted mb-1">{{ r.description }}</p>
                                    <div class="small text-muted" style="font-size: 0.74rem;">
                                        Par <strong>{{ r.tenant_name }}</strong> ({{ r.tenant_type_label }})
                                    </div>
                                </div>
                                <div class="text-nowrap">
                                    <button v-if="r.is_mine" type="button" class="btn btn-outline-success btn-sm" @click="resolveSos(r.id)">
                                        <i class="bi bi-check2-circle me-1"></i>Résolu
                                    </button>
                                    <button v-else type="button" class="btn btn-primary btn-sm" @click="replySos(r)">
                                        <i class="bi bi-chat-fill me-1"></i>J'ai ça / Répondre
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VUE 2 : Discussions & Annuaire -->
        <div v-else class="row g-3">
            <!-- Colonne gauche -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white py-2 px-3 fw-bold small">
                        <i class="bi bi-chat-dots-fill text-primary me-1"></i>Mes discussions B2B ({{ conversations.length }})
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 240px; overflow-y: auto;">
                        <div v-if="!conversations.length" class="p-3 text-muted small text-center">
                            Aucune discussion active. Sélectionnez un établissement ci-dessous.
                        </div>
                        <button
                            v-for="c in conversations"
                            :key="c.id"
                            type="button"
                            class="list-group-item list-group-item-action py-2 px-3 text-start"
                            :class="{ 'active': c.id === activeConvId }"
                            @click="selectConversation(c.id)"
                        >
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold small text-truncate">{{ c.peer.name }}</span>
                                <span v-if="c.unread_count > 0" class="badge bg-danger rounded-pill">{{ c.unread_count }}</span>
                            </div>
                            <div class="small" :class="c.id === activeConvId ? 'text-white-50' : 'text-muted'" style="font-size: 0.72rem;">
                                {{ c.peer.type_label }}<span v-if="c.peer.city"> • {{ c.peer.city }}</span>
                            </div>
                            <div v-if="c.last_preview" class="text-truncate mt-1" :class="c.id === activeConvId ? 'text-white-50' : 'text-muted'" style="font-size: 0.72rem;">
                                {{ c.last_preview }}
                            </div>
                        </button>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-2 px-3">
                        <div class="fw-bold small mb-2"><i class="bi bi-building-check text-success me-1"></i>Annuaire Garages &amp; Centres Techniques</div>
                        <div class="d-flex gap-1">
                            <input v-model="searchQuery" type="search" class="form-control form-control-sm" placeholder="Nom ou ville..." />
                            <select v-model="typeFilter" class="form-select form-select-sm no-select2" style="max-width: 135px;">
                                <option value="">Tous types</option>
                                <option value="garage">Garages</option>
                                <option value="centre_technique">Centres Tech.</option>
                                <option value="assurance">Assurances</option>
                                <option value="boutique_pieces">Pièces</option>
                            </select>
                        </div>
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 260px; overflow-y: auto;">
                        <div v-if="!directory.length" class="p-3 text-muted small text-center">
                            Aucun établissement partenaire trouvé.
                        </div>
                        <div v-for="p in directory" :key="p.id" class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small text-truncate">{{ p.name }}</div>
                                <div class="text-muted" style="font-size: 0.72rem;">
                                    <span class="badge bg-light text-dark border me-1">{{ p.type_label }}</span>
                                    <span v-if="p.city">{{ p.city }}</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 text-nowrap" @click="openPartner(p.id)">
                                <i class="bi bi-chat-left-text me-1"></i>Écrire
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Colonne droite : Fil de discussion -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm d-flex flex-column" style="height: 620px;">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-2">
                        <div v-if="activeConversation">
                            <div class="fw-bold">{{ activeConversation.peer.name }}</div>
                            <div class="small text-muted">
                                {{ activeConversation.peer.type_label }}
                                <span v-if="activeConversation.peer.city"> • {{ activeConversation.peer.city }}</span>
                            </div>
                        </div>
                        <div v-else class="fw-bold text-muted small">
                            <i class="bi bi-diagram-3 me-1"></i>Réseau Pro Inter-Garages &amp; Centres Techniques
                        </div>
                        <div v-if="activeConvId" class="d-flex gap-1">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="activeConversation?.peer?.is_blocked_by_me ? 'btn-success' : 'btn-outline-danger'"
                                :title="activeConversation?.peer?.is_blocked_by_me ? 'Débloquer cet établissement' : 'Bloquer cet établissement (anti-spam)'"
                                @click="toggleBlockPeer"
                            >
                                <i class="bi" :class="activeConversation?.peer?.is_blocked_by_me ? 'bi-unlock me-1' : 'bi-slash-circle me-1'"></i>
                                {{ activeConversation?.peer?.is_blocked_by_me ? 'Débloquer' : 'Bloquer' }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" title="Actualiser" @click="fetchState(true)">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>

                    <div ref="chatBodyRef" class="card-body flex-grow-1 overflow-auto bg-light p-3">
                        <div v-if="!activeConvId" class="h-100 d-flex flex-column align-items-center justify-content-center text-center text-muted p-4">
                            <i class="bi bi-building-gear fs-1 text-primary opacity-50 mb-2"></i>
                            <h3 class="h6 fw-bold text-dark">Communiquez directement avec vos confrères et centres techniques</h3>
                            <p class="small mb-0" style="max-width: 440px;">
                                Choisissez un établissement à gauche pour échanger par texte, note vocale ou photo, transférer un PV ou partager une fiche véhicule en 1 clic.
                            </p>
                        </div>

                        <div v-else class="d-flex flex-column gap-2">
                            <div v-if="!messages.length" class="text-center text-muted small py-5">
                                Démarrez la conversation avec <strong>{{ activeConversation?.peer?.name }}</strong>.
                            </div>
                            <div
                                v-for="m in messages"
                                :key="m.id"
                                class="d-flex"
                                :class="m.is_mine ? 'justify-content-end' : 'justify-content-start'"
                            >
                                <div
                                    class="p-3 rounded-3 shadow-sm small"
                                    :class="m.is_mine ? 'bg-primary text-white' : 'bg-white border text-dark'"
                                    style="max-width: 82%;"
                                >
                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-1" style="font-size: 0.72rem;">
                                        <span class="fw-bold">{{ m.sender_name }}</span>
                                        <span class="d-flex align-items-center gap-1" :class="m.is_mine ? 'text-white-50' : 'text-muted'">
                                            <span v-if="m.is_urgent" class="badge bg-danger">URGENT</span>
                                            {{ formatTime(m.created_at) }}
                                            <!-- Accusé de lecture ✓ / ✓✓ -->
                                            <i
                                                v-if="m.is_mine"
                                                class="bi"
                                                :class="m.read_at ? 'bi-check2-all text-info' : 'bi-check2'"
                                                :title="m.read_at ? 'Lu par le destinataire' : 'Envoyé'"
                                            ></i>
                                        </span>
                                    </div>

                                    <!-- Contexte partagé + Actions 1-clic -->
                                    <div
                                        v-if="m.shared_context"
                                        class="rounded p-2 mb-2"
                                        :class="m.is_mine ? 'bg-white bg-opacity-25 text-white' : 'bg-light border text-dark'"
                                        style="font-size: 0.76rem;"
                                    >
                                        <span class="badge bg-dark me-1">{{ m.shared_context.badge }}</span>
                                        <strong>{{ m.shared_context.title }}</strong>
                                        <div v-if="m.shared_context.details" class="mt-1 opacity-75">{{ m.shared_context.details }}</div>
                                        <div class="d-flex flex-wrap gap-1 mt-2">
                                            <Link
                                                v-if="m.shared_context.action_url"
                                                :href="m.shared_context.action_url"
                                                class="btn btn-sm py-0 px-2"
                                                :class="m.is_mine ? 'btn-light text-primary' : 'btn-primary'"
                                                style="font-size: 0.72rem;"
                                            >
                                                <i class="bi bi-box-arrow-up-right me-1"></i>{{ m.shared_context.action_label || 'Ouvrir' }}
                                            </Link>
                                            <a
                                                v-if="m.shared_context.pv_url"
                                                :href="m.shared_context.pv_url"
                                                target="_blank"
                                                class="btn btn-sm py-0 px-2 btn-outline-dark bg-white"
                                                style="font-size: 0.72rem;"
                                            >
                                                <i class="bi bi-file-earmark-check me-1"></i>Voir le PV
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Note vocale atelier -->
                                    <div v-if="m.audio_url" class="mb-2">
                                        <audio :src="m.audio_url" controls class="w-100" style="height: 34px; min-width: 210px;"></audio>
                                    </div>

                                    <div style="white-space: pre-wrap;">{{ m.body }}</div>

                                    <!-- Photos en Lightbox + autres pièces jointes -->
                                    <div v-if="m.attachments?.length" class="d-flex flex-wrap gap-2 mt-2">
                                        <template v-for="att in m.attachments" :key="att.index">
                                            <button
                                                v-if="att.is_image && att.preview_url"
                                                type="button"
                                                class="p-0 border-0 bg-transparent"
                                                style="cursor: zoom-in;"
                                                title="Agrandir la photo"
                                                @click="openImageLightbox(m, att.index)"
                                            >
                                                <img :src="att.preview_url" :alt="att.name" class="rounded border shadow-sm" style="width: 74px; height: 74px; object-fit: cover;" />
                                            </button>
                                            <a
                                                v-else
                                                :href="att.download_url"
                                                class="btn btn-sm py-0 px-2 align-self-center"
                                                :class="m.is_mine ? 'btn-light text-primary' : 'btn-outline-primary'"
                                                style="font-size: 0.72rem;"
                                            >
                                                <i class="bi bi-paperclip me-1"></i>{{ att.name }} ({{ att.size_kb }} Ko)
                                            </a>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Zone d'envoi -->
                    <div v-if="activeConvId" class="card-footer bg-white border-top p-3">
                        <div v-if="errorMsg" class="alert alert-danger py-1 px-2 small mb-2">{{ errorMsg }}</div>

                        <div v-if="isRecording" class="alert alert-danger py-1 px-2 small mb-2 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-mic-fill me-1"></i>Enregistrement vocal en cours... {{ recordingSec }}s</span>
                            <button type="button" class="btn btn-danger btn-sm py-0 px-2" @click="stopRecording">Arrêter</button>
                        </div>

                        <div v-if="recordedAudioUrl" class="d-flex align-items-center gap-2 p-2 mb-2 rounded bg-light border">
                            <audio :src="recordedAudioUrl" controls class="flex-grow-1" style="height: 30px;"></audio>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" @click="clearAudio"><i class="bi bi-trash"></i></button>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                            <select v-model="shareType" class="form-select form-select-sm no-select2" style="max-width: 175px;" @change="shareId = ''">
                                <option value="">Partager un dossier...</option>
                                <option value="vehicle">Fiche Véhicule</option>
                                <option value="repair_order">Ordre de Réparation</option>
                                <option value="technical_pv">PV Visite Technique</option>
                            </select>
                            <select v-if="shareType === 'vehicle'" v-model="shareId" class="form-select form-select-sm no-select2" style="max-width: 240px;">
                                <option value="">-- Choisir véhicule --</option>
                                <option v-for="v in shareable.vehicles" :key="v.id" :value="v.id">{{ v.label }}</option>
                            </select>
                            <select v-if="shareType === 'repair_order'" v-model="shareId" class="form-select form-select-sm no-select2" style="max-width: 240px;">
                                <option value="">-- Choisir un OR --</option>
                                <option v-for="o in shareable.repair_orders" :key="o.id" :value="o.id">{{ o.label }}</option>
                            </select>
                            <select v-if="shareType === 'technical_pv'" v-model="shareId" class="form-select form-select-sm no-select2" style="max-width: 240px;">
                                <option value="">-- Choisir un PV --</option>
                                <option v-for="pv in shareable.technical_pvs" :key="pv.id" :value="pv.id">{{ pv.label }}</option>
                            </select>
                            <div class="form-check form-check-inline mb-0 ms-auto">
                                <input id="b2bUrgent" v-model="isUrgent" type="checkbox" class="form-check-input" />
                                <label class="form-check-label text-danger fw-semibold" for="b2bUrgent">Urgent (+WhatsApp)</label>
                            </div>
                        </div>

                        <div v-if="files.length" class="d-flex flex-wrap gap-1 mb-2">
                            <span v-for="(f, idx) in files" :key="idx" class="badge bg-light text-dark border">
                                <i class="bi bi-paperclip me-1"></i>{{ f.name }}
                                <button type="button" class="btn-close ms-1" style="font-size: 0.55rem;" @click="files.splice(idx, 1)"></button>
                            </span>
                        </div>

                        <form class="input-group" @submit.prevent="sendMessage">
                            <input
                                ref="fileInputRef"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp,.pdf,.csv,.txt,.log,.xml,.json,.bin,.ori,.mod,.dbc,.asc,.trc"
                                multiple
                                class="d-none"
                                @change="onFilesPicked"
                            />
                            <button type="button" class="btn btn-outline-secondary" title="Joindre photos, PDF, OBD/ECU" @click="fileInputRef?.click()">
                                <i class="bi bi-paperclip"></i>
                            </button>
                            <button
                                type="button"
                                class="btn"
                                :class="isRecording ? 'btn-danger' : 'btn-outline-secondary'"
                                title="Enregistrer une note vocale atelier"
                                @click="toggleRecording"
                            >
                                <i class="bi" :class="isRecording ? 'bi-stop-circle-fill' : 'bi-mic-fill'"></i>
                            </button>
                            <input
                                v-model="body"
                                type="text"
                                class="form-control"
                                placeholder="Écrivez votre message ou enregistrez une note vocale..."
                                :disabled="sending"
                            />
                            <button type="submit" class="btn btn-primary px-3 fw-semibold" :disabled="sending || (!body.trim() && !recordedAudioFile && !files.length)">
                                <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="bi bi-send-fill me-1"></i>Envoyer
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
