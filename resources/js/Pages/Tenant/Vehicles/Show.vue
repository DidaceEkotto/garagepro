<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    vehicle: Object,
});

const passportUrl = computed(() =>
    props.vehicle?.public_token ? `${window.location.origin}/carnet/${props.vehicle.public_token}` : ''
);

const whatsappReminderUrl = computed(() => {
    const rawPhone = (props.vehicle?.customer?.phone || '').replace(/[^\d+]/g, '');
    const clientName = props.vehicle?.customer?.name || 'Client';
    const plate = props.vehicle?.license_plate || '';
    const km = (props.vehicle?.mileage ?? 0).toLocaleString('fr-FR');
    const msg = `Bonjour ${clientName}, votre véhicule ${plate} (${km} km) approche de son prochain entretien périodique. Réservez votre créneau chez nous !`
        + (passportUrl.value ? ` Carnet numérique : ${passportUrl.value}` : '');
    const target = rawPhone ? `https://wa.me/${rawPhone.replace('+', '')}` : 'https://wa.me/';
    return `${target}?text=${encodeURIComponent(msg)}`;
});
</script>

<template>
    <Head :title="vehicle?.license_plate ? `Véhicule ${vehicle.license_plate}` : 'Fiche Véhicule'" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">{{ vehicle.license_plate }} — {{ vehicle.brand }} {{ vehicle.model }}</h1>
                <div class="text-muted small">Client : {{ vehicle.customer?.name }} • {{ vehicle.mileage }} km</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a v-if="vehicle.public_token" :href="`/carnet/${vehicle.public_token}`" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-qr-code me-1"></i>Carnet numérique &amp; QR
                </a>
                <a :href="whatsappReminderUrl" target="_blank" rel="noopener" class="btn btn-success btn-sm">
                    <i class="bi bi-whatsapp me-1"></i>Relance entretien WhatsApp
                </a>
                <Link :href="`/clients/${vehicle.customer_id}`" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Retour fiche client
                </Link>
            </div>
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
