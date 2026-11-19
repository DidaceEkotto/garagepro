<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    appointments: Object,
    agencies: Array,
    customers: Array,
    vehicles: Array,
    mechanics: Array,
});

const isNewCustomer = ref(false);
const scheduleDate = ref('');
const scheduleTime = ref('09:00');

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    new_customer_name: '',
    new_customer_phone: '',
    vehicle_id: '',
    assigned_mechanic_id: '',
    bay_name: 'Pont 1',
    channel: 'telephone',
    scheduled_at: '',
    duration_minutes: 60,
    reason: 'entretien',
    notes: '',
});

const filteredVehicles = computed(() => {
    if (!form.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(form.customer_id));
});

function syncVehicleSelect2() {
    nextTick(() => {
        if (window.$ && window.$.fn?.select2) {
            window.$('#appointmentVehicleSelect').trigger('change.select2');
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

function submitAppointment() {
    if (isNewCustomer.value) {
        form.customer_id = '';
    } else {
        form.new_customer_name = '';
        form.new_customer_phone = '';
    }
    form.scheduled_at = form.channel !== 'sans_rdv' && scheduleDate.value
        ? `${scheduleDate.value} ${scheduleTime.value || '09:00'}`
        : '';
    form.post('/rendez-vous', {
        onSuccess: () => {
            form.reset('new_customer_name', 'new_customer_phone', 'notes', 'scheduled_at');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddAppointment'))?.hide();
        },
    });
}

function updateStatus(id, status) {
    router.patch(`/rendez-vous/${id}/statut`, { status });
}
</script>

<template>
    <Head title="Rendez-vous" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Rendez-vous &amp; File d'attente</h1>
                <p class="text-muted small mb-0">Prise de rendez-vous, ponts disponibles, file sans rendez-vous et rappels</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddAppointment">
                <i class="bi bi-calendar-plus me-1"></i>Nouveau rendez-vous
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Client / Véhicule</th>
                                <th>Canal &amp; Pont</th>
                                <th>Motif</th>
                                <th>Date prévue</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!appointments.data?.length">
                                <td colspan="6" class="text-center text-muted py-4 small">Aucun rendez-vous enregistré.</td>
                            </tr>
                            <tr v-for="a in appointments.data" :key="a.id">
                                <td>
                                    <div class="fw-semibold">{{ a.customer?.name }}</div>
                                    <div class="small text-muted">{{ a.vehicle?.license_plate ?? 'Véhicule à préciser' }}</div>
                                </td>
                                <td class="small">
                                    <div>{{ a.channel }}</div>
                                    <span class="text-muted">{{ a.bay_name ?? '-' }}</span>
                                </td>
                                <td>{{ a.reason }}</td>
                                <td class="small">{{ a.scheduled_at ?? 'File sans RDV' }}</td>
                                <td><span class="badge bg-info text-dark">{{ a.status }}</span></td>
                                <td class="text-end text-nowrap">
                                    <button v-if="a.status !== 'recu' && a.status !== 'annule'" type="button" class="btn btn-outline-success btn-sm me-1" @click="updateStatus(a.id, 'recu')">Reçu</button>
                                    <button v-if="a.status !== 'annule' && a.status !== 'recu'" type="button" class="btn btn-outline-danger btn-sm" @click="updateStatus(a.id, 'annule')">Annuler</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddAppointment" title="Nouveau rendez-vous" size="modal-lg">
            <form @submit.prevent="submitAppointment">
                <div class="form-check form-switch mb-3">
                    <input id="newCustModal" v-model="isNewCustomer" type="checkbox" class="form-check-input" />
                    <label for="newCustModal" class="form-check-label small fw-semibold">Nouveau client (création automatique)</label>
                </div>

                <div v-if="!isNewCustomer" class="mb-3">
                    <label class="form-label small">Client</label>
                    <select v-model="form.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.phone }})</option>
                    </select>
                </div>

                <div v-else class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom du client</label>
                        <input v-model="form.new_customer_name" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Téléphone</label>
                        <input v-model="form.new_customer_phone" type="text" class="form-control" required />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Agence</label>
                        <select v-model="form.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Véhicule</label>
                        <select id="appointmentVehicleSelect" v-model="form.vehicle_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">{{ v.license_plate }}</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Canal</label>
                        <select v-model="form.channel" class="form-select">
                            <option value="telephone">Téléphone</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="en_ligne">En ligne</option>
                            <option value="sans_rdv">Sans RDV (File)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Motif</label>
                        <select v-model="form.reason" class="form-select">
                            <option value="entretien">Entretien</option>
                            <option value="panne">Panne</option>
                            <option value="controle">Contrôle</option>
                            <option value="accident">Accident</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Pont / Baie</label>
                        <input v-model="form.bay_name" type="text" class="form-control" />
                    </div>
                </div>

                <div v-if="form.channel !== 'sans_rdv'" class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small">Date prévue</label>
                        <DatePickerInput v-model="scheduleDate" required />
                    </div>
                    <div class="col-5">
                        <label class="form-label small">Heure</label>
                        <TimePickerInput v-model="scheduleTime" required />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Notes</label>
                    <textarea v-model="form.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
