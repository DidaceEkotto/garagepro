<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    customer: Object,
});

const vehicleForm = useForm({
    customer_id: props.customer.id,
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

function addVehicle() {
    vehicleForm.post('/vehicules', {
        onSuccess: () => vehicleForm.reset('license_plate', 'brand', 'model', 'year', 'vin', 'color', 'mileage', 'first_registration_date', 'next_service_date', 'next_service_mileage', 'notes'),
    });
}
</script>

<template>
    <Head :title="customer.name" />

    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-3">
                <img
                    v-if="customer.photo_path"
                    :src="`/storage/${customer.photo_path}`"
                    :alt="customer.name"
                    class="rounded-circle border shadow-sm"
                    style="width: 56px; height: 56px; object-fit: cover;"
                />
                <div>
                    <h1 class="h4 fw-bold mb-0">
                        {{ customer.name }} <span v-if="customer.first_name">{{ customer.first_name }}</span>
                    </h1>
                    <div class="text-muted small">
                        <i class="bi bi-whatsapp text-success me-1"></i>{{ customer.whatsapp_phone || customer.phone }}
                        <span v-if="customer.id_number"> • CNI/Passeport : {{ customer.id_number }}</span>
                        <span v-if="customer.gender"> • {{ customer.gender }}</span>
                        <span v-if="customer.profession"> • {{ customer.profession }}</span>
                    </div>
                    <div v-if="customer.description" class="small text-muted mt-1">{{ customer.description }}</div>
                </div>
            </div>
            <Link href="/clients" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Retour aux clients
            </Link>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Véhicules enregistrés</div>
                    <div class="card-body p-0">
                        <table class="table mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Immatriculation</th>
                                    <th>Marque / Modèle</th>
                                    <th>Kilométrage</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="v in customer.vehicles" :key="v.id">
                                    <td>
                                        <div class="fw-bold">{{ v.license_plate }}</div>
                                        <div v-if="v.vin" class="small text-muted">Châssis : {{ v.vin }}</div>
                                    </td>
                                    <td>
                                        <div>{{ v.brand }} {{ v.model }} <span v-if="v.year">({{ v.year }})</span></div>
                                        <div v-if="v.color" class="small text-muted">{{ v.color }}</div>
                                    </td>
                                    <td>{{ v.mileage }} km</td>
                                    <td class="text-end">
                                        <Link :href="`/vehicules/${v.id}`" class="btn btn-outline-primary btn-sm">Historique</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 fw-bold mb-0"><i class="bi bi-car-front me-2 text-primary"></i>Ajouter un véhicule</h2>
                    </div>
                    <div class="card-body p-4">
                        <form @submit.prevent="addVehicle">
                            <div class="mb-3">
                                <label class="form-label small">Immatriculation</label>
                                <input v-model="vehicleForm.license_plate" type="text" class="form-control" placeholder="LT 123 AB" required />
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">Marque</label>
                                    <input v-model="vehicleForm.brand" type="text" class="form-control" placeholder="Toyota" required />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Modèle</label>
                                    <input v-model="vehicleForm.model" type="text" class="form-control" placeholder="Hilux" required />
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">Carburant</label>
                                    <select v-model="vehicleForm.fuel_type" class="form-select">
                                        <option value="essence">Essence</option>
                                        <option value="diesel">Diesel</option>
                                        <option value="hybride">Hybride</option>
                                        <option value="electrique">Électrique</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Boîte</label>
                                    <select v-model="vehicleForm.transmission" class="form-select">
                                        <option value="manuelle">Manuelle</option>
                                        <option value="automatique">Automatique</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <label class="form-label small">Année (facultatif)</label>
                                    <input v-model="vehicleForm.year" type="number" min="1950" max="2100" class="form-control" placeholder="2020" />
                                </div>
                                <div class="col-4">
                                    <label class="form-label small">Couleur (facultatif)</label>
                                    <input v-model="vehicleForm.color" type="text" class="form-control" placeholder="Noir" />
                                </div>
                                <div class="col-4">
                                    <label class="form-label small">Kilométrage</label>
                                    <input v-model.number="vehicleForm.mileage" type="number" min="0" class="form-control" required />
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Numéro de châssis / VIN (facultatif)</label>
                                <input v-model="vehicleForm.vin" type="text" class="form-control" placeholder="Ex: JTDBR32E000123456" />
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">1<sup>re</sup> mise en circulation (facultatif)</label>
                                    <DatePickerInput v-model="vehicleForm.first_registration_date" />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Prochaine révision — Date (facultatif)</label>
                                    <DatePickerInput v-model="vehicleForm.next_service_date" />
                                </div>
                            </div>
                            <div class="row g-2 mb-4">
                                <div class="col-6">
                                    <label class="form-label small">Prochaine révision — Km (facultatif)</label>
                                    <input v-model.number="vehicleForm.next_service_mileage" type="number" min="0" class="form-control" placeholder="95000" />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Notes (facultatif)</label>
                                    <input v-model="vehicleForm.notes" type="text" class="form-control" placeholder="Observations..." />
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-2" :disabled="vehicleForm.processing">
                                <i class="bi bi-plus-lg me-1"></i>Ajouter le véhicule
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
