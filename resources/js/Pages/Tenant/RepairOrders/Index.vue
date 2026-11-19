<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    repairOrders: Object,
    agencies: Array,
    customers: Array,
    vehicles: Array,
    mechanics: Array,
});

const selectedOrderId = ref(null);
const activeTab = ref('diagnostic');

const selectedOrder = computed(() =>
    (props.repairOrders?.data ?? []).find((o) => o.id === selectedOrderId.value) ?? null
);

function toggleOrderPanel(orderId) {
    selectedOrderId.value = selectedOrderId.value === orderId ? null : orderId;
}

function trackingUrl(order) {
    return order?.tracking_token ? `${window.location.origin}/suivi/${order.tracking_token}` : '';
}

function whatsappShareUrl(order) {
    const url = trackingUrl(order);
    const plate = order?.vehicle?.license_plate || '';
    const msg = `Bonjour ${order?.customer?.name ?? ''}, suivez l'intervention sur votre véhicule ${plate} (${order.order_number}) et validez votre devis en direct ici : ${url}`;
    return `https://wa.me/?text=${encodeURIComponent(msg)}`;
}

const createForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    vehicle_id: '',
    assigned_mechanic_id: '',
    priority: 'normale',
    diagnostic_action: '',
    fault_codes_text: '',
    diagnostic_notes: '',
    tasks: [],
});

function addCreateTask() {
    createForm.tasks.push({
        title: '',
        bay_name: 'Pont 1',
        estimated_minutes: 60,
    });
}

function removeCreateTask(index) {
    createForm.tasks.splice(index, 1);
}

const diagForm = useForm({
    action: 'finish',
    fault_codes_text: '',
    diagnostic_notes: '',
});

const taskForm = useForm({
    title: '',
    assigned_mechanic_id: '',
    bay_name: 'Pont 1',
    subcontractor_name: '',
    subcontractor_type: '',
    priority: 'normale',
    estimated_minutes: 60,
});

const filteredVehicles = computed(() => {
    if (!createForm.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(createForm.customer_id));
});

function syncVehicleSelect2() {
    nextTick(() => {
        if (window.$ && window.$.fn?.select2) {
            window.$('#repairOrderVehicleSelect').trigger('change.select2');
        }
    });
}

watch(
    () => createForm.customer_id,
    (customerId) => {
        if (!customerId) {
            createForm.vehicle_id = '';
            syncVehicleSelect2();
            return;
        }
        const list = (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(customerId));
        if (list.length === 1) {
            createForm.vehicle_id = list[0].id;
        } else if (!list.some((v) => Number(v.id) === Number(createForm.vehicle_id))) {
            createForm.vehicle_id = '';
        }
        syncVehicleSelect2();
    }
);

function createOrder() {
    const codes = createForm.fault_codes_text
        .split(',')
        .map((c) => c.trim())
        .filter(Boolean);

    createForm.transform((data) => ({
        agency_id: data.agency_id,
        customer_id: data.customer_id,
        vehicle_id: data.vehicle_id,
        assigned_mechanic_id: data.assigned_mechanic_id || null,
        priority: data.priority,
        diagnostic_action: data.diagnostic_action || null,
        fault_codes: codes,
        diagnostic_notes: data.diagnostic_notes || null,
        tasks: data.tasks.filter((t) => t.title && t.title.trim() !== ''),
    })).post('/ordres-reparation', {
        onSuccess: () => {
            createForm.reset('customer_id', 'vehicle_id', 'assigned_mechanic_id', 'diagnostic_action', 'fault_codes_text', 'diagnostic_notes');
            createForm.tasks = [];
            syncVehicleSelect2();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddOrder'))?.hide();
        },
    });
}

function submitDiagnostic() {
    if (!selectedOrderId.value) return;
    const codes = diagForm.fault_codes_text.split(',').map((c) => c.trim()).filter(Boolean);
    diagForm.transform((data) => ({
        action: data.action,
        fault_codes: codes,
        diagnostic_notes: data.diagnostic_notes,
    })).post(`/ordres-reparation/${selectedOrderId.value}/diagnostic`, {
        onSuccess: () => diagForm.reset('fault_codes_text', 'diagnostic_notes'),
    });
}

function submitTask() {
    if (!selectedOrderId.value) return;
    taskForm.post(`/ordres-reparation/${selectedOrderId.value}/taches`, {
        onSuccess: () => taskForm.reset('title', 'subcontractor_name', 'subcontractor_type'),
    });
}
</script>

<template>
    <Head title="Ordres de réparation" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Ordres de réparation (OR)</h1>
                <p class="text-muted small mb-0">Création d'OR, diagnostic avec codes défauts et planification des tâches/ponts</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddOrder">
                <i class="bi bi-plus-circle me-1"></i>Nouvel OR
            </button>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Numéro OR</th>
                                <th>Client / Véhicule</th>
                                <th>Mécanicien</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in repairOrders.data" :key="o.id">
                                <td class="fw-bold">{{ o.order_number }}</td>
                                <td>
                                    <div class="fw-semibold">{{ o.customer?.name }}</div>
                                    <div class="small text-muted">{{ o.vehicle?.license_plate }}</div>
                                </td>
                                <td>{{ o.mechanic?.name ?? 'Non affecté' }}</td>
                                <td><span class="badge bg-primary">{{ o.status }}</span></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a
                                            v-if="o.tracking_token"
                                            :href="`/suivi/${o.tracking_token}`"
                                            target="_blank"
                                            class="btn btn-outline-secondary btn-sm"
                                            title="Suivi client en direct"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a
                                            v-if="o.tracking_token"
                                            :href="whatsappShareUrl(o)"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-outline-success btn-sm"
                                            title="Envoyer le suivi sur WhatsApp"
                                        >
                                            <i class="bi bi-whatsapp"></i>
                                        </a>
                                        <button
                                            type="button"
                                            class="btn btn-sm"
                                            :class="selectedOrderId === o.id ? 'btn-primary' : 'btn-outline-primary'"
                                            @click="toggleOrderPanel(o.id)"
                                        >
                                            <i class="bi me-1" :class="selectedOrderId === o.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                            {{ selectedOrderId === o.id ? 'Fermer Diagnostic / Tâches' : 'Diagnostic / Tâches' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="selectedOrder" class="card border-0 shadow-sm border-start border-4 border-primary">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold">{{ selectedOrder.order_number }} — Atelier &amp; Planification</span>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn" :class="activeTab === 'diagnostic' ? 'btn-primary' : 'btn-outline-primary'" @click="activeTab = 'diagnostic'">Diagnostic</button>
                        <button type="button" class="btn" :class="activeTab === 'task' ? 'btn-primary' : 'btn-outline-primary'" @click="activeTab = 'task'">Ajouter tâche</button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" title="Fermer le panneau" @click="selectedOrderId = null">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-4">
                <div v-if="activeTab === 'diagnostic'">
                    <div v-if="selectedOrder.diagnostic_notes || (selectedOrder.fault_codes && selectedOrder.fault_codes.length)" class="alert alert-light border small mb-3">
                        <div v-if="selectedOrder.fault_codes?.length"><strong>Codes défauts :</strong> {{ selectedOrder.fault_codes.join(', ') }}</div>
                        <div v-if="selectedOrder.diagnostic_notes"><strong>Notes :</strong> {{ selectedOrder.diagnostic_notes }}</div>
                    </div>
                    <form @submit.prevent="submitDiagnostic">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small">Codes défauts (séparés par virgule)</label>
                                <input v-model="diagForm.fault_codes_text" type="text" class="form-control" placeholder="P0300, C1201" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Chrono diagnostic</label>
                                <select v-model="diagForm.action" class="form-select">
                                    <option value="start">Démarrer diagnostic</option>
                                    <option value="finish">Terminer diagnostic</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Notes d'inspection</label>
                            <textarea v-model="diagForm.diagnostic_notes" rows="2" class="form-control"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="diagForm.processing">Enregistrer le diagnostic</button>
                    </form>
                </div>

                <div v-else>
                    <div v-if="selectedOrder.tasks?.length" class="mb-3">
                        <div class="small fw-semibold text-muted mb-2">Tâches planifiées ({{ selectedOrder.tasks.length }})</div>
                        <ul class="list-group list-group-flush border rounded small">
                            <li v-for="t in selectedOrder.tasks" :key="t.id" class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <span>{{ t.title }} <span v-if="t.bay_name" class="text-muted">({{ t.bay_name }})</span></span>
                                <span class="badge bg-light text-dark border">{{ t.estimated_minutes }} min · {{ t.status }}</span>
                            </li>
                        </ul>
                    </div>
                    <form @submit.prevent="submitTask">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small">Intitulé de la tâche</label>
                                <input v-model="taskForm.title" type="text" class="form-control" required />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Pont / Baie</label>
                                <input v-model="taskForm.bay_name" type="text" class="form-control" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Durée (min)</label>
                                <input v-model.number="taskForm.estimated_minutes" type="number" min="10" class="form-control" required />
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="taskForm.processing">Planifier la tâche</button>
                    </form>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddOrder" title="Nouvel Ordre de Réparation" size="modal-lg">
            <form @submit.prevent="createOrder">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Agence</label>
                        <select v-model="createForm.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Client</label>
                        <select v-model="createForm.customer_id" class="form-select" required>
                            <option value="">-- Choisir un client --</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Véhicule</label>
                        <select id="repairOrderVehicleSelect" v-model="createForm.vehicle_id" class="form-select" required>
                            <option value="">-- Choisir un véhicule --</option>
                            <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">{{ v.license_plate }} ({{ v.brand }})</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label small">Mécanicien</label>
                        <select v-model="createForm.assigned_mechanic_id" class="form-select">
                            <option value="">-- À affecter --</option>
                            <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">Priorité</label>
                        <select v-model="createForm.priority" class="form-select">
                            <option value="normale">Normale</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </div>

                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="fw-semibold small mb-2">Diagnostic initial (optionnel)</div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small">Codes défauts (séparés par virgule)</label>
                            <input v-model="createForm.fault_codes_text" type="text" class="form-control form-control-sm" placeholder="P0300, C1201" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Chrono diagnostic</label>
                            <select v-model="createForm.diagnostic_action" class="form-select form-select-sm no-select2">
                                <option value="">-- Plus tard --</option>
                                <option value="start">Démarrer diagnostic</option>
                                <option value="finish">Terminer diagnostic</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label small">Notes d'inspection</label>
                        <textarea v-model="createForm.diagnostic_notes" rows="2" class="form-control form-control-sm" placeholder="Symptômes constatés..."></textarea>
                    </div>
                </div>

                <div class="border rounded p-3 mb-4 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold small">Tâches à planifier (optionnel)</span>
                        <button type="button" class="btn btn-outline-primary btn-sm" @click="addCreateTask">+ Ajouter une tâche</button>
                    </div>
                    <div v-if="!createForm.tasks.length" class="text-muted small">
                        Aucune tâche ajoutée pour le moment. Vous pouvez en ajouter maintenant ou plus tard.
                    </div>
                    <div v-for="(task, idx) in createForm.tasks" :key="idx" class="row g-2 align-items-end mb-2">
                        <div class="col-md-6">
                            <label class="form-label small mb-1">Intitulé de la tâche</label>
                            <input v-model="task.title" type="text" class="form-control form-control-sm" placeholder="Ex: Vidange + filtre à huile" required />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Pont / Baie</label>
                            <input v-model="task.bay_name" type="text" class="form-control form-control-sm" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Durée (min)</label>
                            <input v-model.number="task.estimated_minutes" type="number" min="10" class="form-control form-control-sm" required />
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="button" class="btn btn-outline-danger btn-sm" title="Retirer" @click="removeCreateTask(idx)">&times;</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="createForm.processing">Ouvrir l'OR</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
