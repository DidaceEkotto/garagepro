<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    vehicle: Object,
});
</script>

<template>
    <Head :title="vehicle.license_plate" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">{{ vehicle.license_plate }} — {{ vehicle.brand }} {{ vehicle.model }}</h1>
                <div class="text-muted small">Client : {{ vehicle.customer?.name }} • {{ vehicle.mileage }} km</div>
            </div>
            <Link :href="`/clients/${vehicle.customer_id}`" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Retour fiche client
            </Link>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold py-3">Réceptions &amp; passages au garage</div>
            <div class="card-body">
                <p v-if="!vehicle.receptions?.length" class="text-muted small mb-0">Aucune réception enregistrée.</p>
                <ul v-else class="list-group list-group-flush">
                    <li v-for="r in vehicle.receptions" :key="r.id" class="list-group-item">
                        <strong>{{ r.visit_reason }}</strong> — {{ r.mileage_in }} km (reçu le {{ r.received_at }})
                    </li>
                </ul>
            </div>
        </div>
    </TenantLayout>
</template>
