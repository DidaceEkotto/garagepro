<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';

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

function submitQuote() {
    form.post('/devis', {
        onSuccess: () => {
            form.reset('notes');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddQuote'))?.hide();
        },
    });
}

function acceptQuote(id) {
    router.post(`/devis/${id}/valider`);
}

function newVersion(id) {
    router.post(`/devis/${id}/nouvelle-version`);
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
                            <input v-model="item.description" type="text" class="form-control form-control-sm" placeholder="Description..." required />
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
    </TenantLayout>
</template>
