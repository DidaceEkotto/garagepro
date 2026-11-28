<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const emit = defineEmits(['unread-change']);

const loading = ref(true);
const sending = ref(false);
const errorMsg = ref('');
const searchQuery = ref('');
const typeFilter = ref('');
const b2bVisible = ref(true);

const directory = ref([]);
const conversations = ref([]);
const activeConversation = ref(null);
const activeConvId = ref(null);
const messages = ref([]);
const shareable = ref({ vehicles: [], repair_orders: [], technical_pvs: [] });

const body = ref('');
const isUrgent = ref(false);
const notifyWhatsapp = ref(false);
const shareType = ref('');
const shareId = ref('');
const files = ref([]);
const fileInputRef = ref(null);
const chatBodyRef = ref(null);

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
        if (activeConvId.value) params.set('conversation_id', String(activeConvId.value));

        const res = await fetch(`/reseau-pro/etat?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok) return;

        b2bVisible.value = Boolean(data.b2b_directory_visible);
        directory.value = data.directory || [];
        conversations.value = data.conversations || [];
        activeConversation.value = data.active_conversation || null;
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
    if (res.ok) {
        b2bVisible.value = nextVal;
    }
}

function onFilesPicked(e) {
    const picked = Array.from(e.target.files || []).slice(0, 3);
    files.value = picked;
    if (fileInputRef.value) fileInputRef.value.value = '';
}

async function sendMessage() {
    if (!activeConvId.value || !body.value.trim() || sending.value) return;
    sending.value = true;
    errorMsg.value = '';

    try {
        const fd = new FormData();
        fd.append('body', body.value.trim());
        fd.append('is_urgent', isUrgent.value ? '1' : '0');
        fd.append('notify_whatsapp', notifyWhatsapp.value ? '1' : '0');
        if (shareType.value && shareId.value) {
            fd.append('share_type', shareType.value);
            fd.append('share_id', String(shareId.value));
        }
        files.value.forEach((f) => fd.append('attachments[]', f));

        const res = await fetch(`/reseau-pro/conversations/${activeConvId.value}/messages`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
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
        notifyWhatsapp.value = false;
        shareType.value = '';
        shareId.value = '';
        files.value = [];
        scrollToBottom();
        fetchState(true);
    } finally {
        sending.value = false;
    }
}

function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

watch([searchQuery, typeFilter], () => {
    fetchState(true);
});

onMounted(() => {
    fetchState(false);
    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            fetchState(true);
        }
    }, 10000);
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <div class="row g-3">
        <!-- Colonne gauche : Conversations actives & Annuaire Partenaires -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold">
                        <i class="bi bi-globe2 text-primary me-1"></i>Visible dans l'annuaire B2B
                    </span>
                    <div class="form-check form-switch mb-0">
                        <input
                            id="b2bVisSwitch"
                            class="form-check-input"
                            type="checkbox"
                            :checked="b2bVisible"
                            @change="toggleVisibility"
                        />
                    </div>
                </div>
            </div>

            <!-- Conversations en cours -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 px-3 fw-bold small">
                    <i class="bi bi-chat-dots-fill text-primary me-1"></i>Mes discussions B2B ({{ conversations.length }})
                </div>
                <div class="list-group list-group-flush" style="max-height: 230px; overflow-y: auto;">
                    <div v-if="!conversations.length" class="p-3 text-muted small text-center">
                        Aucune discussion inter-établissements active. Sélectionnez un partenaire ci-dessous.
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

            <!-- Annuaire des garages & centres techniques partenaires -->
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
                    <div
                        v-for="p in directory"
                        :key="p.id"
                        class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center gap-2"
                    >
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

        <!-- Colonne droite : Fil de discussion fluide B2B -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm d-flex flex-column" style="height: 620px;">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div v-if="activeConversation">
                        <div class="fw-bold">{{ activeConversation.peer.name }}</div>
                        <div class="small text-muted">
                            {{ activeConversation.peer.type_label }}
                            <span v-if="activeConversation.peer.city"> • {{ activeConversation.peer.city }}</span>
                            • Échange direct chiffré inter-établissements
                        </div>
                    </div>
                    <div v-else class="fw-bold text-muted small">
                        <i class="bi bi-diagram-3 me-1"></i>Réseau Pro Inter-Garages &amp; Centres Techniques
                    </div>
                    <button v-if="activeConvId" type="button" class="btn btn-outline-secondary btn-sm" title="Actualiser" @click="fetchState(true)">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>

                <!-- Messages -->
                <div ref="chatBodyRef" class="card-body flex-grow-1 overflow-auto bg-light p-3">
                    <div v-if="!activeConvId" class="h-100 d-flex flex-column align-items-center justify-content-center text-center text-muted p-4">
                        <i class="bi bi-building-gear fs-1 text-primary opacity-50 mb-2"></i>
                        <h3 class="h6 fw-bold text-dark">Communiquez directement avec vos confrères et centres techniques</h3>
                        <p class="small mb-0" style="max-width: 440px;">
                            Choisissez un garage ou un centre technique dans l'annuaire à gauche pour transférer un PV de visite technique,
                            demander une pièce urgente, solliciter une sous-traitance ou partager une fiche véhicule en 1 clic.
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
                                    </span>
                                </div>

                                <!-- Contexte métier partagé (Véhicule / OR / PV) -->
                                <div
                                    v-if="m.shared_context"
                                    class="rounded p-2 mb-2"
                                    :class="m.is_mine ? 'bg-white bg-opacity-25 text-white' : 'bg-light border text-dark'"
                                    style="font-size: 0.76rem;"
                                >
                                    <span class="badge bg-dark me-1">{{ m.shared_context.badge }}</span>
                                    <strong>{{ m.shared_context.title }}</strong>
                                    <div v-if="m.shared_context.details" class="mt-1 opacity-75">{{ m.shared_context.details }}</div>
                                </div>

                                <div style="white-space: pre-wrap;">{{ m.body }}</div>

                                <!-- Pièces jointes -->
                                <div v-if="m.attachments?.length" class="d-flex flex-wrap gap-1 mt-2">
                                    <a
                                        v-for="att in m.attachments"
                                        :key="att.index"
                                        :href="att.download_url"
                                        class="btn btn-sm py-0 px-2"
                                        :class="m.is_mine ? 'btn-light text-primary' : 'btn-outline-primary'"
                                        style="font-size: 0.72rem;"
                                    >
                                        <i class="bi bi-paperclip me-1"></i>{{ att.name }} ({{ att.size_kb }} Ko)
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Zone d'envoi -->
                <div v-if="activeConvId" class="card-footer bg-white border-top p-3">
                    <div v-if="errorMsg" class="alert alert-danger py-1 px-2 small mb-2">{{ errorMsg }}</div>

                    <!-- Partage rapide Véhicule / OR / PV + options -->
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
                        <button type="button" class="btn btn-outline-secondary" title="Joindre photos, PDF, OBD/ECU (max 3)" @click="fileInputRef?.click()">
                            <i class="bi bi-paperclip"></i>
                        </button>
                        <input
                            v-model="body"
                            type="text"
                            class="form-control"
                            placeholder="Écrivez votre message au garage / centre technique..."
                            :disabled="sending"
                            required
                        />
                        <button type="submit" class="btn btn-primary px-3 fw-semibold" :disabled="sending || !body.trim()">
                            <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="bi bi-send-fill me-1"></i>Envoyer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
