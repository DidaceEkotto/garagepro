<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const { isOnline, enqueueOperation, pendingItemsForEntity } = useOfflineSync();
const offlineVehicles = pendingItemsForEntity('vehicle');
const offlineCustomers = pendingItemsForEntity('customer');

const props = defineProps({
    vehicles: Object,
    search: { type: String, default: '' },
    customers: { type: Array, default: () => [] },
});

const selectableCustomers = computed(() => [
    ...offlineCustomers.value.map((item) => ({
        id: item.temp_id,
        name: `${item.label} (Hors ligne)`,
    })),
    ...(props.customers || []),
]);

const query = ref(props.search ?? '');
const editingVehicle = ref(null);

const editForm = useForm({
    customer_id: '',
    license_plate: '',
    brand: '',
    model: '',
    year: '',
    vin: '',
    color: '',
    fuel_type: 'essence',
    transmission: 'manuelle',
    mileage: 0,
    first_registration_date: '',
    next_service_date: '',
    next_service_mileage: '',
    notes: '',
});

async function openEditVehicle(v) {
    editingVehicle.value = v;
    editForm.customer_id = v.customer_id ?? '';
    editForm.license_plate = v.license_plate ?? '';
    editForm.brand = v.brand ?? '';
    editForm.model = v.model ?? '';
    editForm.year = v.year ?? '';
    editForm.vin = v.vin ?? '';
    editForm.color = v.color ?? '';
    editForm.fuel_type = v.fuel_type ?? 'essence';
    editForm.transmission = v.transmission ?? 'manuelle';
    editForm.mileage = v.mileage ?? 0;
    editForm.first_registration_date = v.first_registration_date ? String(v.first_registration_date).slice(0, 10) : '';
    editForm.next_service_date = v.next_service_date ? String(v.next_service_date).slice(0, 10) : '';
    editForm.next_service_mileage = v.next_service_mileage ?? '';
    editForm.notes = v.notes ?? '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditVehicle'))?.show();
}

async function updateVehicle() {
    if (!editingVehicle.value) return;
    if (!isOnline.value) {
        await enqueueOperation({
            entity: 'vehicle',
            action: 'update',
            recordId: editingVehicle.value.id,
            baseUpdatedAt: editingVehicle.value.updated_at || null,
            label: `Modif. véhicule ${editForm.license_plate}`,
            payload: {
                customer_id: editForm.customer_id,
                license_plate: editForm.license_plate,
                brand: editForm.brand,
                model: editForm.model,
                year: editForm.year || null,
                vin: editForm.vin || null,
                color: editForm.color || null,
                fuel_type: editForm.fuel_type || null,
                transmission: editForm.transmission || null,
                mileage: editForm.mileage || 0,
                next_service_date: editForm.next_service_date || null,
                notes: editForm.notes || null,
            },
        });
        window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditVehicle'))?.hide();
        editingVehicle.value = null;
        return;
    }

    editForm.put(`/vehicules/${editingVehicle.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditVehicle'))?.hide();
            editingVehicle.value = null;
        },
    });
}

const form = useForm({
    customer_id: props.customers?.[0]?.id ?? '',
    license_plate: '',
    brand: '',
    model: '',
    year: '',
    vin: '',
    color: '',
    fuel_type: 'essence',
    transmission: 'manuelle',
    mileage: 0,
    first_registration_date: '',
    next_service_date: '',
    next_service_mileage: '',
    notes: '',
});

function doSearch() {
    router.get('/vehicules', { q: query.value }, { preserveState: true });
}

async function createVehicle() {
    if (!isOnline.value) {
        await enqueueOperation({
            entity: 'vehicle',
            action: 'create',
            label: `${String(form.license_plate || '').toUpperCase()} — ${form.brand} ${form.model}`.trim(),
            payload: {
                customer_id: form.customer_id,
                license_plate: form.license_plate,
                brand: form.brand,
                model: form.model,
                year: form.year || null,
                vin: form.vin || null,
                color: form.color || null,
                fuel_type: form.fuel_type || null,
                transmission: form.transmission || null,
                mileage: form.mileage || 0,
                next_service_date: form.next_service_date || null,
                notes: form.notes || null,
            },
        });
        form.reset(
            'license_plate',
            'brand',
            'model',
            'year',
            'vin',
            'color',
            'mileage',
            'first_registration_date',
            'next_service_date',
            'next_service_mileage',
            'notes'
        );
        window.bootstrap?.Modal.getInstance(document.getElementById('modalAddVehicle'))?.hide();
        return;
    }

    form.post('/vehicules', {
        onSuccess: () => {
            form.reset(
                'license_plate',
                'brand',
                'model',
                'year',
                'vin',
                'color',
                'mileage',
                'first_registration_date',
                'next_service_date',
                'next_service_mileage',
                'notes'
            );
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddVehicle'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Véhicules" />

    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Véhicules</h1>
                <p class="text-muted small mb-0">Parc véhicules enregistrés, affectation aux clients, carnet d'entretien numérique et QR Code</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddVehicle">
                <i class="bi bi-car-front me-1"></i>Nouveau véhicule
            </button>
        </div>

        <form class="input-group mb-3 shadow-sm" @submit.prevent="doSearch">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
            <input
                v-model="query"
                type="search"
                class="form-control border-start-0"
                placeholder="Rechercher par immatriculation, marque, modèle, N° châssis ou client..."
            />
            <button class="btn btn-primary px-4" type="submit">Rechercher</button>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Immatriculation</th>
                                <th>Véhicule</th>
                                <th>Client affecté</th>
                                <th>Kilométrage</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in offlineVehicles" :key="item.client_uuid" class="table-warning">
                                <td class="fw-bold">
                                    {{ String(item.payload?.license_plate || '').toUpperCase() }}
                                    <span class="badge bg-warning text-dark ms-1">
                                        <i class="bi bi-wifi-off me-1"></i>Hors ligne
                                    </span>
                                </td>
                                <td>{{ item.payload?.brand }} {{ item.payload?.model }}</td>
                                <td class="small text-muted">En attente de synchro</td>
                                <td>{{ Number(item.payload?.mileage || 0).toLocaleString('fr-FR') }} km</td>
                                <td class="text-end small text-muted">Sauvegardé localement</td>
                            </tr>
                            <tr v-if="!vehicles?.data?.length && !offlineVehicles.length">
                                <td colspan="5" class="text-center text-muted py-4 small">Aucun véhicule enregistré.</td>
                            </tr>
                            <tr v-for="v in vehicles.data" :key="v.id">
                                <td>
                                    <div class="fw-bold">{{ v.license_plate }}</div>
                                    <div v-if="v.vin" class="small text-muted">VIN : {{ v.vin }}</div>
                                </td>
                                <td>
                                    <div>{{ v.brand }} {{ v.model }} <span v-if="v.year">({{ v.year }})</span></div>
                                    <div class="small text-muted">
                                        {{ v.fuel_type }} · {{ v.transmission }}
                                        <span v-if="v.color"> · {{ v.color }}</span>
                                    </div>
                                </td>
                                <td>
                                    <Link v-if="v.customer" :href="`/clients/${v.customer.id}`" class="text-decoration-none fw-semibold">
                                        {{ v.customer.name }} <span v-if="v.customer.first_name">{{ v.customer.first_name }}</span>
                                    </Link>
                                    <div v-if="v.customer?.phone" class="small text-muted">{{ v.customer.phone }}</div>
                                </td>
                                <td>{{ (v.mileage ?? 0).toLocaleString('fr-FR') }} km</td>
                                <td class="text-end text-nowrap">
                                    <a
                                        v-if="v.public_token"
                                        :href="`/carnet/${v.public_token}`"
                                        target="_blank"
                                        class="btn btn-outline-success btn-sm me-1"
                                        title="Carnet d'entretien numérique & QR"
                                    >
                                        <i class="bi bi-qr-code"></i>
                                    </a>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm me-1"
                                        title="Modifier le véhicule"
                                        @click="openEditVehicle(v)"
                                    >
                                        <i class="bi bi-pencil me-1"></i>Modifier
                                    </button>
                                    <Link :href="`/vehicules/${v.id}`" class="btn btn-outline-primary btn-sm">Historique</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddVehicle" title="Nouveau véhicule" size="modal-lg">
            <form @submit.prevent="createVehicle">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Affecter au client <span class="text-danger">*</span></label>
                    <select v-model="form.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in selectableCustomers" :key="c.id" :value="c.id">
                            {{ c.name }} {{ c.first_name || '' }} <template v-if="c.phone">({{ c.phone }})</template>
                        </option>
                    </select>
                    <div v-if="form.errors.customer_id" class="text-danger small mt-1">{{ form.errors.customer_id }}</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Immatriculation <span class="text-danger">*</span></label>
                        <input v-model="form.license_plate" type="text" class="form-control text-uppercase" :class="{ 'is-invalid': form.errors.license_plate }" placeholder="LT 123 AB" required />
                        <div v-if="form.errors.license_plate" class="invalid-feedback">{{ form.errors.license_plate }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Marque <span class="text-danger">*</span></label>
                        <input v-model="form.brand" type="text" class="form-control" placeholder="Toyota" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Modèle <span class="text-danger">*</span></label>
                        <input v-model="form.model" type="text" class="form-control" placeholder="Corolla" required />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Carburant</label>
                        <select v-model="form.fuel_type" class="form-select">
                            <option value="essence">Essence</option>
                            <option value="diesel">Diesel</option>
                            <option value="hybride">Hybride</option>
                            <option value="electrique">Électrique</option>
                            <option value="gpl">GPL</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Boîte de vitesse</label>
                        <select v-model="form.transmission" class="form-select">
                            <option value="manuelle">Manuelle</option>
                            <option value="automatique">Automatique</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Kilométrage <span class="text-danger">*</span></label>
                        <input v-model.number="form.mileage" type="number" min="0" class="form-control" required />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Numéro de châssis / VIN (facultatif)</label>
                        <input v-model="form.vin" type="text" class="form-control" placeholder="Ex: JTDBR32E000123456" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Année (facultatif)</label>
                        <input v-model="form.year" type="number" min="1950" max="2100" class="form-control" placeholder="2020" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Couleur (facultatif)</label>
                        <input v-model="form.color" type="text" class="form-control" placeholder="Gris" />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">1<sup>re</sup> mise en circulation (facultatif)</label>
                        <DatePickerInput v-model="form.first_registration_date" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Prochaine révision — Date (facultatif)</label>
                        <DatePickerInput v-model="form.next_service_date" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Prochaine révision — Km (facultatif)</label>
                        <input v-model.number="form.next_service_mileage" type="number" min="0" class="form-control" placeholder="95000" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small">Notes (facultatif)</label>
                    <textarea v-model="form.notes" rows="2" class="form-control" placeholder="Observations sur le véhicule..."></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Enregistrer le véhicule</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalEditVehicle" title="Modifier le véhicule" size="modal-lg">
            <form v-if="editingVehicle" @submit.prevent="updateVehicle">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client affecté <span class="text-danger">*</span></label>
                    <select v-model="editForm.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">
                            {{ c.name }} {{ c.first_name ?? '' }} ({{ c.phone }})
                        </option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Immatriculation <span class="text-danger">*</span></label>
                        <input v-model="editForm.license_plate" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Marque <span class="text-danger">*</span></label>
                        <input v-model="editForm.brand" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Modèle <span class="text-danger">*</span></label>
                        <input v-model="editForm.model" type="text" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small">Année</label>
                        <input v-model="editForm.year" type="number" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">N° Châssis (VIN)</label>
                        <input v-model="editForm.vin" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Couleur</label>
                        <input v-model="editForm.color" type="text" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Kilométrage</label>
                        <input v-model="editForm.mileage" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Carburant</label>
                        <select v-model="editForm.fuel_type" class="form-select">
                            <option value="essence">Essence</option>
                            <option value="diesel">Diesel</option>
                            <option value="hybride">Hybride</option>
                            <option value="electrique">Électrique</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Boîte</label>
                        <select v-model="editForm.transmission" class="form-select">
                            <option value="manuelle">Manuelle</option>
                            <option value="automatique">Automatique</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">1ère mise en circulation</label>
                        <DatePickerInput v-model="editForm.first_registration_date" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Prochaine vidange (date)</label>
                        <DatePickerInput v-model="editForm.next_service_date" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Prochaine vidange (km)</label>
                        <input v-model="editForm.next_service_mileage" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Notes</label>
                    <textarea v-model="editForm.notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="editForm.processing">Enregistrer les modifications</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
