<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';

const props = defineProps({
    receptions: Object,
    agencies: Array,
    customers: Array,
    vehicles: Array,
    appointments: Array,
    courtesyVehicles: Array,
});

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    vehicle_id: '',
    appointment_id: '',
    courtesy_vehicle_id: '',
    courtesy_deposit_amount: 0,
    visit_reason: 'entretien',
    mileage_in: 0,
    fuel_level: 50,
    belongings_left: '',
    notes: '',
    photos: [],
});

const filteredVehicles = computed(() => {
    if (!form.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(form.customer_id));
});

function syncMileageFromVehicle(vehicleId) {
    const vehicle = (props.vehicles ?? []).find((v) => Number(v.id) === Number(vehicleId));
    form.mileage_in = vehicle ? Number(vehicle.mileage ?? 0) : 0;
}

watch(
    () => form.customer_id,
    () => {
        const list = filteredVehicles.value;
        if (list.length === 1) {
            form.vehicle_id = list[0].id;
            syncMileageFromVehicle(list[0].id);
        } else {
            form.vehicle_id = '';
            form.mileage_in = 0;
        }
        nextTick(() => {
            if (window.$ && window.$.fn?.select2) {
                window.$('#receptionVehicleSelect').trigger('change.select2');
            }
        });
    },
);

watch(
    () => form.vehicle_id,
    (newId) => {
        syncMileageFromVehicle(newId);
    },
);

watch(
    () => form.courtesy_vehicle_id,
    (newId) => {
        const cv = (props.courtesyVehicles ?? []).find((item) => Number(item.id) === Number(newId));
        form.courtesy_deposit_amount = cv ? Number(cv.deposit_amount ?? 0) : 0;
    },
);

function submitReception() {
    form.post('/receptions', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('belongings_left', 'notes', 'photos');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddReception'))?.hide();
        },
    });
}

function returnCourtesy(id) {
    router.post(`/receptions/${id}/retour-courtoisie`);
}
</script>

<template>
    <Head title="Réceptions véhicules" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Réception des véhicules</h1>
                <p class="text-muted small mb-0">État des lieux d'entrée, photos sécurisées illimitées et véhicule de courtoisie</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddReception">
                <i class="bi bi-clipboard-plus me-1"></i>Nouvelle réception
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Véhicule</th>
                                <th>Client</th>
                                <th>Motif &amp; Km</th>
                                <th>Carburant</th>
                                <th>Courtoisie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!receptions.data?.length">
                                <td colspan="5" class="text-center text-muted py-4 small">Aucune réception enregistrée.</td>
                            </tr>
                            <tr v-for="r in receptions.data" :key="r.id">
                                <td class="fw-semibold">{{ r.vehicle?.license_plate }}</td>
                                <td>{{ r.customer?.name }}</td>
                                <td class="small">
                                    {{ r.visit_reason }} • {{ r.mileage_in }} km
                                    <span v-if="r.photos?.length" class="badge bg-primary-subtle text-primary ms-1">
                                        <i class="bi bi-images me-1"></i>{{ r.photos.length }}
                                    </span>
                                </td>
                                <td>{{ r.fuel_level }} %</td>
                                <td>
                                    <span v-if="r.courtesy_vehicle && !r.courtesy_returned_at">
                                        {{ r.courtesy_vehicle.license_plate }}
                                        <button type="button" class="btn btn-outline-primary btn-sm ms-1" @click="returnCourtesy(r.id)">Retourner</button>
                                    </span>
                                    <span v-else class="text-muted small">{{ r.courtesy_vehicle?.license_plate ?? '-' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddReception" title="Nouvelle fiche de réception" size="modal-lg">
            <form @submit.prevent="submitReception">
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
                        <select id="receptionVehicleSelect" v-model="form.vehicle_id" class="form-select" required @change="syncMileageFromVehicle($event.target.value)">
                            <option value="">-- Véhicule --</option>
                            <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">{{ v.license_plate }}</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Motif visite</label>
                        <select v-model="form.visit_reason" class="form-select">
                            <option value="entretien">Entretien</option>
                            <option value="panne">Panne</option>
                            <option value="controle">Contrôle</option>
                            <option value="accident">Accident</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Kilométrage entrée</label>
                        <input v-model.number="form.mileage_in" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Carburant ({{ form.fuel_level }}%)</label>
                        <input v-model.number="form.fuel_level" type="range" min="0" max="100" class="form-range mt-2" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Objets laissés dans le véhicule</label>
                    <input v-model="form.belongings_left" type="text" class="form-control" placeholder="Cric, roue de secours, documents..." />
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Véhicule de courtoisie</label>
                        <select v-model="form.courtesy_vehicle_id" class="form-select">
                            <option value="">Aucun</option>
                            <option v-for="cv in courtesyVehicles" :key="cv.id" :value="cv.id">{{ cv.name }} ({{ cv.license_plate }})</option>
                        </select>
                        <div class="form-text small">Véhicule de remplacement prêté temporairement au client pendant la réparation.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Caution (FCFA)</label>
                        <input v-model.number="form.courtesy_deposit_amount" type="number" min="0" class="form-control" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Photos du véhicule (illimitées)</label>
                    <FileDropzone v-model="form.photos" :multiple="true" label="Glisser-déposer ou ajouter autant de photos que vous souhaitez" />
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Valider la réception</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
