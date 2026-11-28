<script setup>
import B2bNetworkChat from '@/Components/B2bNetworkChat.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    messages: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
});

const activeTab = ref('b2b');
const b2bUnread = ref(0);
const customerSearch = ref('');
const replyingMessage = ref(null);

const composeForm = useForm({
    recipient_scope: 'customers',
    customer_ids: [],
    subject: '',
    body: '',
    attachments: [],
});

const replyForm = useForm({
    body: '',
    attachments: [],
});

const filteredCustomers = computed(() => {
    const q = customerSearch.value.trim().toLowerCase();
    if (!q) return props.customers;
    return props.customers.filter(
        (c) => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q)
    );
});

function toggleAllCustomers() {
    if (composeForm.customer_ids.length === filteredCustomers.value.length) {
        composeForm.customer_ids = [];
    } else {
        composeForm.customer_ids = filteredCustomers.value.map((c) => c.id);
    }
}

function submitCompose() {
    composeForm.post('/messagerie', {
        forceFormData: true,
        onSuccess: () => {
            composeForm.reset('customer_ids', 'subject', 'body', 'attachments');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalComposeMessage'))?.hide();
        },
    });
}

function openReply(msg) {
    replyingMessage.value = msg;
    replyForm.reset('body', 'attachments');
}

function submitReply() {
    if (!replyingMessage.value) return;
    replyForm.post(`/messagerie/${replyingMessage.value.id}/repondre`, {
        forceFormData: true,
        onSuccess: () => {
            replyingMessage.value = null;
            replyForm.reset();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalReplyMessage'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Messagerie & Notifications" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Messagerie &amp; Réseau Pro B2B</h1>
                <p class="text-muted small mb-0">Échanges directs Garages ↔ Centres Techniques, messagerie clients et support Admin</p>
            </div>
            <button v-if="activeTab === 'classic'" type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalComposeMessage">
                <i class="bi bi-pencil-square me-1"></i>Nouveau message
            </button>
        </div>

        <ul class="nav nav-pills mb-3 gap-2">
            <li class="nav-item">
                <button type="button" class="nav-link fw-semibold" :class="{ active: activeTab === 'b2b' }" @click="activeTab = 'b2b'">
                    <i class="bi bi-building-gear me-1"></i>Réseau Pro (Garages &amp; Centres Techniques)
                    <span v-if="b2bUnread > 0" class="badge bg-danger ms-1">{{ b2bUnread }}</span>
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link fw-semibold" :class="{ active: activeTab === 'classic' }" @click="activeTab = 'classic'">
                    <i class="bi bi-envelope-paper me-1"></i>Clients &amp; Admin ({{ messages.length }})
                </button>
            </li>
        </ul>

        <B2bNetworkChat v-show="activeTab === 'b2b'" @unread-change="(n) => (b2bUnread = n)" />

        <div v-show="activeTab === 'classic'" class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div v-if="!messages.length" class="text-center text-muted py-5 small">
                    Aucun message pour le moment. Cliquez sur « Nouveau message » pour écrire à un client ou à l'administration.
                </div>
                <div v-else class="list-group list-group-flush">
                    <div v-for="msg in messages" :key="msg.id" class="list-group-item p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <span
                                    class="badge me-2"
                                    :class="msg.sender_type === 'admin' || msg.recipient_type === 'admin' ? 'bg-dark' : 'bg-primary'"
                                >
                                    {{ msg.sender_type === 'admin' || msg.recipient_type === 'admin' ? 'Admin (Interne)' : 'Client (Email)' }}
                                </span>
                                <span class="fw-bold">{{ msg.subject }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-muted">{{ new Date(msg.created_at).toLocaleString('fr-FR') }}</span>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalReplyMessage"
                                    @click="openReply(msg)"
                                >
                                    <i class="bi bi-reply me-1"></i>Répondre
                                </button>
                            </div>
                        </div>

                        <div class="small text-muted mb-2">
                            De : <strong>{{ msg.sender_name }}</strong>
                            <span v-if="msg.recipient_email"> • À : <strong>{{ msg.recipient_email }}</strong></span>
                        </div>

                        <p class="mb-3 small" style="white-space: pre-line;">{{ msg.body }}</p>

                        <div v-if="msg.attachments?.length" class="d-flex flex-wrap gap-2">
                            <a
                                v-for="att in msg.attachments"
                                :key="att.index"
                                :href="att.downloadUrl"
                                class="btn btn-light btn-sm border d-inline-flex align-items-center gap-2"
                            >
                                <i class="bi bi-paperclip text-primary"></i>
                                <span>{{ att.name }}</span>
                                <i class="bi bi-download small text-muted"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Static Modal Nouveau message (Client unique, groupé ou Admin interne) -->
        <StaticModal id="modalComposeMessage" title="Nouveau message" size="modal-lg">
            <form @submit.prevent="submitCompose">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Destinataire</label>
                    <div class="btn-group w-100" role="group">
                        <input id="scopeCustomers" v-model="composeForm.recipient_scope" type="radio" value="customers" class="btn-check" />
                        <label class="btn btn-outline-primary btn-sm" for="scopeCustomers">
                            <i class="bi bi-people me-1"></i>Clients (1 ou plusieurs — E-mail)
                        </label>
                        <input id="scopeAdmin" v-model="composeForm.recipient_scope" type="radio" value="admin" class="btn-check" />
                        <label class="btn btn-outline-dark btn-sm" for="scopeAdmin">
                            <i class="bi bi-shield-lock me-1"></i>Administration Plateforme (Interne — sans e-mail)
                        </label>
                    </div>
                </div>

                <div v-if="composeForm.recipient_scope === 'customers'" class="mb-3 p-3 bg-light border rounded-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label small fw-semibold mb-0">
                            Sélectionner les clients ({{ composeForm.customer_ids.length }} sélectionné(s))
                        </label>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="toggleAllCustomers">
                            {{ composeForm.customer_ids.length === filteredCustomers.length ? 'Tout désélectionner' : 'Tout sélectionner' }}
                        </button>
                    </div>
                    <input
                        v-model="customerSearch"
                        type="search"
                        class="form-control form-control-sm mb-2"
                        placeholder="Filtrer un client par nom ou e-mail..."
                    />
                    <div v-if="!filteredCustomers.length" class="small text-muted">
                        Aucun client avec une adresse e-mail renseignée.
                    </div>
                    <div v-else class="vstack gap-1" style="max-height: 150px; overflow-y: auto;">
                        <label v-for="c in filteredCustomers" :key="c.id" class="form-check small d-flex align-items-center gap-2 mb-0">
                            <input v-model="composeForm.customer_ids" type="checkbox" :value="c.id" class="form-check-input mt-0" />
                            <span><strong>{{ c.name }}</strong> <span class="text-muted">({{ c.email }})</span></span>
                        </label>
                    </div>
                    <div v-if="composeForm.errors.customer_ids" class="text-danger small mt-1">{{ composeForm.errors.customer_ids }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Objet</label>
                    <input v-model="composeForm.subject" type="text" class="form-control" required />
                    <div v-if="composeForm.errors.subject" class="text-danger small">{{ composeForm.errors.subject }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Message</label>
                    <textarea v-model="composeForm.body" rows="4" class="form-control" required></textarea>
                    <div v-if="composeForm.errors.body" class="text-danger small">{{ composeForm.errors.body }}</div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Pièces jointes (image, pdf, docx, excel, txt)</label>
                    <FileDropzone
                        v-model="composeForm.attachments"
                        :multiple="true"
                        accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,image/*"
                        label="Glisser-déposer un ou plusieurs fichiers (images, PDF, Word, Excel, TXT)"
                    />
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="composeForm.processing">
                        <i class="bi bi-send me-1"></i>{{ composeForm.recipient_scope === 'admin' ? 'Envoyer à l\'Admin (Interne)' : 'Envoyer par e-mail' }}
                    </button>
                </div>
            </form>
        </StaticModal>

        <!-- Static Modal Répondre -->
        <StaticModal id="modalReplyMessage" title="Répondre au message">
            <form @submit.prevent="submitReply">
                <div v-if="replyingMessage" class="alert alert-light border small mb-3">
                    <div class="fw-bold">{{ replyingMessage.subject }}</div>
                    <div class="text-muted">{{ replyingMessage.sender_name }}</div>
                    <div v-if="replyingMessage.sender_type === 'admin' || replyingMessage.recipient_type === 'admin'" class="text-info mt-1 fw-semibold">
                        <i class="bi bi-shield-lock me-1"></i>Réponse dans la messagerie interne Admin (sans e-mail)
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Votre réponse</label>
                    <textarea v-model="replyForm.body" rows="4" class="form-control" required></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Pièces jointes (image, pdf, docx, excel, txt)</label>
                    <FileDropzone
                        v-model="replyForm.attachments"
                        :multiple="true"
                        accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,image/*"
                        label="Glisser-déposer un ou plusieurs fichiers"
                    />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="replyForm.processing">
                        <i class="bi bi-send me-1"></i>Envoyer
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
