<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const { openLightbox } = useLightbox();

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

const OFFLINE_QUEUE_KEY = 'mboacar_offline_receptions_queue';
const OFFLINE_DRAFT_KEY = 'mboacar_offline_reception_draft';
const isOffline = ref(typeof navigator !== 'undefined' ? !navigator.onLine : false);
const offlineQueueCount = ref(0);
const syncingOffline = ref(false);

function loadQueue() {
    try {
        const raw = localStorage.getItem(OFFLINE_QUEUE_KEY);
        const list = raw ? JSON.parse(raw) : [];
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

function refreshQueueCount() {
    offlineQueueCount.value = loadQueue().length;
}

function syncOfflineQueue() {
    if (isOffline.value || syncingOffline.value) return;
    const queue = loadQueue();
    if (!queue.length) return;

    syncingOffline.value = true;
    const [nextItem, ...rest] = queue;
    router.post('/receptions', nextItem, {
        preserveScroll: true,
        onSuccess: () => {
            localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(rest));
            refreshQueueCount();
            syncingOffline.value = false;
            if (rest.length > 0) syncOfflineQueue();
        },
        onError: () => {
            syncingOffline.value = false;
        },
    });
}

function handleOnline() {
    isOffline.value = false;
    syncOfflineQueue();
}

function handleOffline() {
    isOffline.value = true;
}

onMounted(() => {
    refreshQueueCount();
    try {
        const draftRaw = localStorage.getItem(OFFLINE_DRAFT_KEY);
        if (draftRaw) {
            const draft = JSON.parse(draftRaw);
            if (draft && typeof draft === 'object') {
                Object.keys(draft).forEach((k) => {
                    if (k in form && k !== 'photos') form[k] = draft[k];
                });
            }
        }
    } catch {
        // Ignore corrupted draft
    }
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    if (!isOffline.value && offlineQueueCount.value > 0) {
        syncOfflineQueue();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
});

watch(
    () => ({
        agency_id: form.agency_id,
        customer_id: form.customer_id,
        vehicle_id: form.vehicle_id,
        visit_reason: form.visit_reason,
        mileage_in: form.mileage_in,
        fuel_level: form.fuel_level,
        belongings_left: form.belongings_left,
        notes: form.notes,
    }),
    (val) => {
        try {
            localStorage.setItem(OFFLINE_DRAFT_KEY, JSON.stringify(val));
        } catch {
            // Ignore quota errors
        }
    },
    { deep: true }
);

function submitReception() {
    if (isOffline.value) {
        const queue = loadQueue();
        queue.push({
            agency_id: form.agency_id,
            customer_id: form.customer_id,
            vehicle_id: form.vehicle_id,
            visit_reason: form.visit_reason,
            mileage_in: form.mileage_in,
            fuel_level: form.fuel_level,
            belongings_left: form.belongings_left,
            courtesy_vehicle_id: form.courtesy_vehicle_id || null,
            courtesy_deposit_amount: form.courtesy_deposit_amount || 0,
            notes: form.notes,
        });
        localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(queue));
        localStorage.removeItem(OFFLINE_DRAFT_KEY);
        refreshQueueCount();
        form.reset('belongings_left', 'notes', 'photos');
        window.bootstrap?.Modal.getInstance(document.getElementById('modalAddReception'))?.hide();
        return;
    }

    form.post('/receptions', {
        forceFormData: true,
        onSuccess: () => {
            localStorage.removeItem(OFFLINE_DRAFT_KEY);
            form.reset('belongings_left', 'notes', 'photos');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddReception'))?.hide();
        },
    });
}

const { askConfirm } = useConfirm();
const viewingReception = ref(null);
const editingReception = ref(null);

const editForm = useForm({
    _method: 'PUT',
    agency_id: '',
    visit_reason: 'entretien',
    mileage_in: 0,
    fuel_level: 50,
    belongings_left: '',
    courtesy_deposit_amount: 0,
    notes: '',
    photos: [],
});

async function openReceptionDetails(r) {
    viewingReception.value = r;
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalReceptionDetails'))?.show();
}

async function openEditReception(r) {
    editingReception.value = r;
    editForm.clearErrors();
    editForm.agency_id = r.agency_id ?? props.agencies?.[0]?.id ?? '';
    editForm.visit_reason = r.visit_reason ?? 'entretien';
    editForm.mileage_in = r.mileage_in ?? 0;
    editForm.fuel_level = r.fuel_level ?? 50;
    editForm.belongings_left = r.belongings_left ?? '';
    editForm.courtesy_deposit_amount = r.courtesy_deposit_amount ?? 0;
    editForm.notes = r.notes ?? '';
    editForm.photos = [];
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditReception'))?.show();
}

function submitEditReception() {
    if (!editingReception.value) return;
    editForm.post(`/receptions/${editingReception.value.id}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            editingReception.value = null;
            editForm.reset('photos');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalEditReception'))?.hide();
        },
    });
}

function returnCourtesy(id) {
    askConfirm({
        title: 'Retour du véhicule de courtoisie',
        message: 'Confirmez-vous que le client a restitué le véhicule de courtoisie au garage ?',
        confirmLabel: 'Confirmer le retour',
        confirmClass: 'btn-success',
        onConfirm: () => router.post(`/receptions/${id}/retour-courtoisie`),
    });
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

        <div v-if="isOffline || offlineQueueCount > 0" class="alert border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small" :class="isOffline ? 'alert-warning' : 'alert-info'">
            <div>
                <i class="bi me-2" :class="isOffline ? 'bi-wifi-off' : 'bi-cloud-arrow-up'"></i>
                <span v-if="isOffline">
                    <strong>Mode hors-ligne actif :</strong> votre brouillon est sauvegardé localement et les réceptions saisies seront synchronisées dès le retour d'internet.
                </span>
                <span v-else>
                    <strong>{{ offlineQueueCount }} réception(s) hors-ligne en attente</strong> de synchronisation.
                </span>
            </div>
            <button v-if="!isOffline && offlineQueueCount > 0" type="button" class="btn btn-primary btn-sm" :disabled="syncingOffline" @click="syncOfflineQueue">
                {{ syncingOffline ? 'Synchronisation...' : 'Synchroniser maintenant' }}
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
                                <th class="text-end">Détails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!receptions.data?.length">
                                <td colspan="6" class="text-center text-muted py-4 small">Aucune réception enregistrée.</td>
                            </tr>
                            <tr v-for="r in receptions.data" :key="r.id">
                                <td class="fw-semibold">
                                    <div>{{ r.vehicle?.license_plate }}</div>
                                    <div class="small text-muted fw-normal">{{ r.received_at_formatted ?? '' }}</div>
                                </td>
                                <td>
                                    <div>{{ r.customer?.name }}</div>
                                    <div class="small text-muted">{{ r.agency?.name ?? '' }}</div>
                                </td>
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
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-1" @click="openEditReception(r)">
                                        <i class="bi bi-pencil-square me-1"></i>Modifier
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" @click="openReceptionDetails(r)">
                                        <i class="bi bi-eye me-1"></i>Détails
                                    </button>
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

        <StaticModal id="modalReceptionDetails" title="Détails complets de la réception" size="modal-lg">
            <div v-if="viewingReception" class="small">
                <div class="row g-3 mb-3 pb-3 border-bottom">
                    <div class="col-md-6"><strong>Date / Heure :</strong> {{ viewingReception.received_at_formatted ?? '-' }}</div>
                    <div class="col-md-6"><strong>Agence :</strong> {{ viewingReception.agency?.name ?? '-' }}</div>
                    <div class="col-md-6"><strong>Client :</strong> {{ viewingReception.customer?.name }} ({{ viewingReception.customer?.phone ?? '-' }})</div>
                    <div class="col-md-6"><strong>Véhicule :</strong> {{ viewingReception.vehicle?.license_plate }} — {{ viewingReception.vehicle?.brand }} {{ viewingReception.vehicle?.model }}</div>
                    <div class="col-md-6"><strong>Motif de visite :</strong> {{ viewingReception.visit_reason }}</div>
                    <div class="col-md-6"><strong>Réceptionné par :</strong> {{ viewingReception.receiver?.name ?? '-' }}</div>
                    <div class="col-md-6"><strong>Kilométrage entrée :</strong> {{ (viewingReception.mileage_in ?? 0).toLocaleString('fr-FR') }} km</div>
                    <div class="col-md-6"><strong>Niveau carburant :</strong> {{ viewingReception.fuel_level ?? 0 }} %</div>
                    <div v-if="viewingReception.courtesy_vehicle" class="col-md-6">
                        <strong>Véhicule de courtoisie :</strong> {{ viewingReception.courtesy_vehicle.name }} ({{ viewingReception.courtesy_vehicle.license_plate }})
                    </div>
                    <div v-if="viewingReception.courtesy_vehicle" class="col-md-6">
                        <strong>Caution :</strong> {{ (viewingReception.courtesy_deposit_amount ?? 0).toLocaleString('fr-FR') }} FCFA
                        <span class="badge ms-1" :class="viewingReception.courtesy_returned_at ? 'bg-success' : 'bg-warning text-dark'">
                            {{ viewingReception.courtesy_returned_at ? 'Restitué' : 'En prêt' }}
                        </span>
                    </div>
                </div>

                <div v-if="viewingReception.belongings_left" class="mb-3">
                    <strong>Objets laissés dans le véhicule :</strong>
                    <div class="text-muted mt-1">{{ viewingReception.belongings_left }}</div>
                </div>

                <div v-if="viewingReception.notes" class="mb-3">
                    <strong>Notes &amp; Observations :</strong>
                    <div class="text-muted mt-1">{{ viewingReception.notes }}</div>
                </div>

                <div v-if="viewingReception.photo_urls?.length" class="mb-3">
                    <strong class="d-block mb-2">Photos d'état des lieux ({{ viewingReception.photo_urls.length }}) :</strong>
                    <div class="d-flex flex-wrap gap-2">
                        <button
                            v-for="(url, idx) in viewingReception.photo_urls"
                            :key="idx"
                            type="button"
                            class="p-0 border-0 bg-transparent position-relative"
                            style="cursor: zoom-in;"
                            title="Cliquer pour ouvrir en plein écran"
                            @click="openLightbox(viewingReception.photo_urls, idx, `Réception — ${viewingReception.vehicle?.license_plate ?? ''}`)"
                        >
                            <img :src="url" alt="Photo réception" class="rounded border shadow-sm" style="width: 96px; height: 96px; object-fit: cover;" />
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </StaticModal>

        <StaticModal id="modalEditReception" title="Modifier la fiche de réception" size="modal-lg">
            <form v-if="editingReception" @submit.prevent="submitEditReception">
                <div class="p-2 px-3 rounded bg-light border mb-3 small">
                    <strong>{{ editingReception.vehicle?.license_plate }}</strong>
                    <span v-if="editingReception.vehicle?.brand"> — {{ editingReception.vehicle.brand }} {{ editingReception.vehicle.model }}</span>
                    • Client : <strong>{{ editingReception.customer?.name }}</strong>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Agence</label>
                        <select v-model="editForm.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Motif visite</label>
                        <select v-model="editForm.visit_reason" class="form-select" required>
                            <option value="entretien">Entretien</option>
                            <option value="panne">Panne</option>
                            <option value="controle">Contrôle</option>
                            <option value="accident">Accident</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Kilométrage entrée</label>
                        <input v-model.number="editForm.mileage_in" type="number" min="0" class="form-control" required />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Carburant ({{ editForm.fuel_level }}%)</label>
                        <input v-model.number="editForm.fuel_level" type="range" min="0" max="100" class="form-range mt-2" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Caution véhicule de courtoisie (FCFA)</label>
                        <input v-model.number="editForm.courtesy_deposit_amount" type="number" min="0" class="form-control" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Objets laissés dans le véhicule</label>
                    <input v-model="editForm.belongings_left" type="text" class="form-control" placeholder="Cric, roue de secours, documents..." />
                </div>

                <div class="mb-3">
                    <label class="form-label small">Notes &amp; Observations</label>
                    <textarea v-model="editForm.notes" rows="2" class="form-control"></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Ajouter des photos supplémentaires (facultatif)</label>
                    <FileDropzone
                        v-model="editForm.photos"
                        :multiple="true"
                        accept="image/*"
                        label="Glissez-déposez des photos à ajouter à la réception"
                    />
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="editForm.processing">Enregistrer les modifications</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
