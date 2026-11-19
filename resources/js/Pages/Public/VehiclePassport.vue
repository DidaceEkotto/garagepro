<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    vehicle: Object,
    garage: Object,
    orders: Array,
    passportUrl: String,
});

const qrImageUrl = computed(() =>
    `https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=${encodeURIComponent(props.passportUrl || window.location.href)}`
);

function printPassport() {
    window.print();
}

function formatDate(d) {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('fr-FR');
}
</script>

<template>
    <Head :title="`Carnet d'entretien — ${vehicle.license_plate}`" />
    <div class="min-vh-100 bg-light py-4">
        <div class="container" style="max-width: 900px;">
            <div class="d-flex justify-content-between align-items-center mb-3 print-hide">
                <Link href="/" class="text-decoration-none d-inline-flex align-items-center gap-2">
                    <img src="/assets/images/logo.png" alt="MboaCar" class="bg-white rounded-3 px-2 py-1 shadow-sm" style="height: 36px; width: auto; object-fit: contain;" />
                </Link>
                <button type="button" class="btn btn-primary btn-sm" @click="printPassport">
                    <i class="bi bi-printer me-1"></i>Imprimer / Étiquette QR
                </button>
            </div>

            <div class="card border-0 shadow-sm mb-4 overflow-hidden">
                <div class="card-body p-4">
                    <div class="row align-items-center g-4">
                        <div class="col-md-8">
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 mb-2">
                                <i class="bi bi-patch-check-fill me-1"></i>Carnet d'entretien numérique certifié MboaCar
                            </span>
                            <h1 class="h3 fw-bold mb-1">{{ vehicle.license_plate }}</h1>
                            <p class="text-muted mb-3">
                                {{ vehicle.brand }} {{ vehicle.model }}
                                <span v-if="vehicle.year">({{ vehicle.year }})</span>
                                <span v-if="vehicle.fuel_type"> · {{ vehicle.fuel_type }}</span>
                            </p>
                            <div class="row g-2 small">
                                <div class="col-sm-4">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted">Kilométrage actuel</div>
                                        <div class="fw-bold">{{ (vehicle.mileage ?? 0).toLocaleString('fr-FR') }} km</div>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted">Prochaine révision</div>
                                        <div class="fw-bold">{{ vehicle.next_service_date ? formatDate(vehicle.next_service_date) : (vehicle.next_service_mileage ? `${vehicle.next_service_mileage.toLocaleString('fr-FR')} km` : 'Non planifiée') }}</div>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="p-2 rounded bg-light border">
                                        <div class="text-muted">Garage émetteur</div>
                                        <div class="fw-bold">{{ garage?.name ?? 'Garage MboaCar' }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="p-3 bg-white border rounded-3 d-inline-block shadow-sm">
                                <img :src="qrImageUrl" alt="QR Code Carnet d'entretien" style="width: 140px; height: 140px;" />
                                <div class="small text-muted mt-2 fw-semibold">Scannez pour vérifier</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 fw-bold">
                    <i class="bi bi-clock-history text-primary me-2"></i>Historique des interventions ({{ orders?.length ?? 0 }})
                </div>
                <div class="card-body p-4">
                    <div v-if="!orders?.length" class="text-center text-muted py-4 small">
                        Aucune intervention enregistrée pour ce véhicule pour le moment.
                    </div>
                    <div v-else class="vstack gap-3">
                        <div v-for="o in orders" :key="o.id" class="border rounded-3 p-3 bg-white">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="fw-bold text-primary">{{ o.order_number }}</span>
                                    <span class="text-muted small ms-2">{{ formatDate(o.created_at) }}</span>
                                </div>
                                <span class="badge bg-primary">{{ o.status }}</span>
                            </div>
                            <p v-if="o.diagnostic_notes" class="small text-muted mb-2">{{ o.diagnostic_notes }}</p>
                            <ul v-if="o.tasks?.length" class="list-unstyled small mb-0 ps-2 border-start border-2 border-primary">
                                <li v-for="t in o.tasks" :key="t.id" class="mb-1">
                                    <i class="bi bi-check2-circle text-success me-1"></i>{{ t.title }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
