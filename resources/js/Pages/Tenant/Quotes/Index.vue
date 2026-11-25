<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    quotes: Object,
    agencies: Array,
    customers: Array,
    vehicles: Array,
    repairOrders: Array,
    parts: Array,
});

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    vehicle_id: '',
    repair_order_id: '',
    discount_amount: 0,
    deposit_required: 0,
    notes: '',
    items: [
        { type: 'main_oeuvre', part_condition: null, urgency: 'urgent', description: '', quantity: 1, unit_price: 15000 },
    ],
});

const filteredVehicles = computed(() => {
    if (!form.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(form.customer_id));
});

function syncVehicleSelect2() {
    nextTick(() => {
        if (window.$ && window.$.fn?.select2) {
            window.$('#quoteVehicleSelect').trigger('change.select2');
        }
    });
}

watch(
    () => form.customer_id,
    (customerId) => {
        if (!customerId) {
            form.vehicle_id = '';
            syncVehicleSelect2();
            return;
        }
        const list = (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(customerId));
        if (list.length === 1) {
            form.vehicle_id = list[0].id;
        } else if (!list.some((v) => Number(v.id) === Number(form.vehicle_id))) {
            form.vehicle_id = '';
        }
        syncVehicleSelect2();
    }
);

const totalHt = computed(() =>
    form.items.reduce((acc, item) => acc + (Number(item.quantity) || 0) * (Number(item.unit_price) || 0), 0)
);

function addItem() {
    form.items.push({ type: 'piece', part_condition: 'neuve', urgency: 'urgent', description: '', quantity: 1, unit_price: 0 });
}

function removeItem(idx) {
    if (form.items.length > 1) form.items.splice(idx, 1);
}

const b2bMatches = ref({});
async function lookupB2B(idx, query) {
    if (!query || query.trim().length < 2) return;
    const res = await fetch(`/marketplace-b2b/rechercher?q=${encodeURIComponent(query.trim())}`, {
        headers: { Accept: 'application/json' },
    });
    const data = await res.json();
    b2bMatches.value = { ...b2bMatches.value, [idx]: data.items || [] };
}

function submitQuote() {
    form.post('/devis', {
        onSuccess: () => {
            form.reset('notes');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddQuote'))?.hide();
        },
    });
}

const { askConfirm } = useConfirm();

function acceptQuote(id) {
    askConfirm({
        title: 'Valider le devis',
        message: 'Confirmez-vous la validation de ce devis par le client ?',
        confirmLabel: 'Valider le devis',
        confirmClass: 'btn-success',
        onConfirm: () => router.post(`/devis/${id}/valider`),
    });
}

function newVersion(id) {
    askConfirm({
        title: 'Créer une nouvelle version',
        message: 'Voulez-vous créer une nouvelle version modifiable de ce devis ?',
        confirmLabel: 'Créer la version',
        confirmClass: 'btn-primary',
        onConfirm: () => router.post(`/devis/${id}/nouvelle-version`),
    });
}

const remindingQuote = ref(null);
const remindForm = useForm({
    channel: 'auto',
    message: '',
});

const remindHasEmail = computed(() => Boolean(remindingQuote.value?.customer?.email?.trim()));
const remindPhone = computed(() => (remindingQuote.value?.customer?.whatsapp_phone || remindingQuote.value?.customer?.phone || '').trim());
const remindHasWhatsapp = computed(() => Boolean(remindPhone.value));
const remindHasAnyChannel = computed(() => remindHasEmail.value || remindHasWhatsapp.value);

async function openRemindModal(q) {
    remindingQuote.value = q;
    remindForm.clearErrors();
    const hasEmail = Boolean(q.customer?.email?.trim());
    const hasWhatsapp = Boolean((q.customer?.whatsapp_phone || q.customer?.phone || '').trim());
    if (hasEmail && !hasWhatsapp) {
        remindForm.channel = 'email';
    } else if (!hasEmail && hasWhatsapp) {
        remindForm.channel = 'whatsapp';
    } else {
        remindForm.channel = 'whatsapp';
    }
    const total = new Intl.NumberFormat('fr-FR').format(q.total_ttc ?? 0);
    const plate = q.vehicle?.license_plate ? ` (${q.vehicle.license_plate})` : '';
    remindForm.message = `Bonjour ${q.customer?.name ?? ''}, nous vous rappelons que votre devis ${q.quote_number} (v${q.version}) d'un montant de ${total} FCFA pour votre véhicule${plate} est en attente de validation. Merci de nous faire un retour !`;
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalRemindQuote'))?.show();
}

function submitQuoteRemind() {
    if (!remindingQuote.value || !remindHasAnyChannel.value) return;
    remindForm.post(`/devis/${remindingQuote.value.id}/rappeler`, {
        preserveScroll: true,
        onSuccess: () => {
            remindingQuote.value = null;
            window.bootstrap?.Modal.getInstance(document.getElementById('modalRemindQuote'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Devis" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Devis clients</h1>
                <p class="text-muted small mb-0">Devis multi-versions, niveaux d'urgence, validation client et conversion en OR</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddQuote">
                <i class="bi bi-file-earmark-plus me-1"></i>Nouveau devis
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Numéro</th>
                                <th>Client / Véhicule</th>
                                <th>Statut</th>
                                <th class="text-end">Total TTC</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="q in quotes.data" :key="q.id">
                                <td class="fw-bold">{{ q.quote_number }} <span class="text-muted small">v{{ q.version }}</span></td>
                                <td>
                                    <div class="fw-semibold">{{ q.customer?.name }}</div>
                                    <div class="small text-muted">{{ q.vehicle?.license_plate }}</div>
                                </td>
                                <td><span class="badge bg-info text-dark">{{ q.status }}</span></td>
                                <td class="text-end">{{ new Intl.NumberFormat('fr-FR').format(q.total_ttc) }} FCFA</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-warning btn-sm me-1 text-dark" title="Envoyer un rappel par E-mail ou WhatsApp" @click="openRemindModal(q)">
                                        <i class="bi bi-bell me-1"></i>Rappel
                                    </button>
                                    <button v-if="q.status === 'brouillon' || q.status === 'envoye'" type="button" class="btn btn-outline-success btn-sm me-1" @click="acceptQuote(q.id)">Valider → OR</button>
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="newVersion(q.id)">+Version</button>
                                    <a :href="`/devis/${q.id}/pdf`" class="btn btn-outline-dark btn-sm">PDF</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddQuote" title="Créer un devis client" size="modal-lg">
            <form @submit.prevent="submitQuote">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Agence</label>
                        <select v-model="form.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Client</label>
                        <select v-model="form.customer_id" class="form-select" required>
                            <option value="">-- Client --</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Véhicule</label>
                        <select id="quoteVehicleSelect" v-model="form.vehicle_id" class="form-select" required>
                            <option value="">-- Véhicule --</option>
                            <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">{{ v.license_plate }} ({{ v.brand }})</option>
                        </select>
                    </div>
                </div>

                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <span class="form-label small mb-0">Lignes du devis</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="addItem">+ Ajouter une ligne</button>
                </div>

                <div v-for="(item, idx) in form.items" :key="idx" class="border rounded p-2 mb-2 bg-light">
                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <select v-model="item.type" class="form-select form-select-sm">
                                <option value="main_oeuvre">Main-d'œuvre</option>
                                <option value="piece">Pièce</option>
                                <option value="consommable">Consommable</option>
                                <option value="sous_traitance">Sous-traitance</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select v-model="item.urgency" class="form-select form-select-sm">
                                <option value="urgent">Urgent</option>
                                <option value="recommande">Recommandé</option>
                                <option value="optionnel">Optionnel</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group input-group-sm">
                                <input v-model="item.description" type="text" class="form-control" placeholder="Description..." required />
                                <button
                                    v-if="item.type === 'piece'"
                                    type="button"
                                    class="btn btn-outline-warning"
                                    title="Rechercher chez d'autres garages (Marketplace B2B)"
                                    @click="lookupB2B(idx, item.description)"
                                >
                                    <i class="bi bi-shop"></i> B2B
                                </button>
                            </div>
                            <div v-if="b2bMatches[idx]?.length" class="mt-1 small">
                                <span v-for="m in b2bMatches[idx].slice(0, 2)" :key="m.id" class="badge bg-warning text-dark me-1">
                                    <a :href="m.url" target="_blank" class="text-dark text-decoration-none">
                                        {{ m.garage_name }} : {{ m.price }} FCFA ({{ m.stock_quantity }} en stock)
                                    </a>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 align-items-center">
                        <div class="col-4">
                            <input v-model.number="item.quantity" type="number" min="1" class="form-control form-control-sm" placeholder="Qté" required />
                        </div>
                        <div class="col-6">
                            <input v-model.number="item.unit_price" type="number" min="0" class="form-control form-control-sm" placeholder="PU FCFA" required />
                        </div>
                        <div class="col-2 text-end">
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="removeItem(idx)"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                </div>

                <div class="text-end fw-bold small mb-3">
                    Total HT estimé : {{ new Intl.NumberFormat('fr-FR').format(totalHt) }} FCFA
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer le devis</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalRemindQuote" title="Envoyer un rappel de devis">
            <form v-if="remindingQuote" @submit.prevent="submitQuoteRemind">
                <div class="p-3 rounded bg-light border mb-3 small">
                    <div class="fw-bold text-dark">{{ remindingQuote.quote_number }} (v{{ remindingQuote.version }}) — {{ remindingQuote.customer?.name }}</div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge" :class="remindHasEmail ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-secondary-subtle text-muted'">
                            <i class="bi bi-envelope me-1"></i>{{ remindHasEmail ? remindingQuote.customer.email : 'Aucun e-mail' }}
                        </span>
                        <span class="badge" :class="remindHasWhatsapp ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-muted'">
                            <i class="bi bi-whatsapp me-1"></i>{{ remindHasWhatsapp ? remindPhone : 'Aucun WhatsApp' }}
                        </span>
                    </div>
                </div>

                <div v-if="!remindHasAnyChannel" class="alert alert-warning small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Ce client n'a ni adresse e-mail ni numéro WhatsApp enregistré. Aucun rappel ne peut être envoyé.
                </div>

                <template v-else>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Canal d'envoi</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="remindForm.channel === 'whatsapp' ? 'btn-success' : 'btn-outline-success'"
                                :disabled="!remindHasWhatsapp"
                                @click="remindForm.channel = 'whatsapp'"
                            >
                                <i class="bi bi-whatsapp me-1"></i>WhatsApp
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="remindForm.channel === 'email' ? 'btn-primary' : 'btn-outline-primary'"
                                :disabled="!remindHasEmail"
                                @click="remindForm.channel = 'email'"
                            >
                                <i class="bi bi-envelope me-1"></i>E-mail
                            </button>
                        </div>
                        <div class="form-text small">
                            Si le canal choisi n'est pas renseigné, le système bascule automatiquement vers l'autre canal disponible.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Message de rappel</label>
                        <textarea v-model="remindForm.message" rows="4" class="form-control" required></textarea>
                    </div>
                </template>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="remindForm.processing || !remindHasAnyChannel">
                        <i class="bi bi-send me-1"></i>Envoyer le rappel
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
