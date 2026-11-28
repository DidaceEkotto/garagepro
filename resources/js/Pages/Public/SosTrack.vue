<script setup>
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    sos: Object,
});

function acceptOffer(offerId) {
    router.post(`/sos-depannage/${props.sos.token}/offres/${offerId}/accepter`);
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Suivi SOS Dépannage en direct" />
    <div class="min-vh-100 bg-light py-4">
        <div class="container" style="max-width: 720px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <Link href="/sos-depannage" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Retour carte</Link>
                <span class="badge fs-6" :class="sos.status === 'resolved' ? 'bg-success' : (sos.status === 'open' ? 'bg-danger' : 'bg-primary')">
                    Statut : {{ sos.status }}
                </span>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h1 class="h5 fw-bold mb-1">Demande SOS — {{ sos.breakdown_type }}</h1>
                    <div class="small text-muted mb-2">{{ sos.vehicle_info || 'Véhicule' }} • {{ sos.location_label }} • {{ sos.created_at }}</div>
                    <p v-if="sos.description" class="small mb-0">{{ sos.description }}</p>
                </div>
            </div>

            <div v-if="sos.accepted_tenant" class="alert alert-success border-0 shadow-sm mb-4">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-truck me-2"></i>Dépanneur confirmé : {{ sos.accepted_tenant.name }}</h2>
                <div class="small">
                    Tarif validé : <strong>{{ fmt(sos.accepted_price) }}</strong> • Arrivée estimée : <strong>{{ sos.accepted_eta_minutes }} min</strong>
                </div>
                <div class="mt-2">
                    <a v-if="sos.accepted_tenant.phone" :href="`tel:${sos.accepted_tenant.phone}`" class="btn btn-success btn-sm">
                        <i class="bi bi-telephone-fill me-1"></i>Appeler le dépanneur ({{ sos.accepted_tenant.phone }})
                    </a>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-bold mb-0">Offres des garages partenaires ({{ sos.offers?.length || 0 }})</h2>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="router.reload()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
                    </button>
                </div>
                <div class="list-group list-group-flush">
                    <div v-if="!sos.offers?.length" class="p-4 text-center text-muted small">
                        En attente des réponses des garages à proximité... Cliquez sur « Actualiser » dans quelques instants.
                    </div>
                    <div v-for="o in sos.offers" :key="o.id" class="list-group-item p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <div class="fw-bold">{{ o.garage_name }} <span v-if="o.distance_km" class="badge bg-light text-dark border ms-1">{{ o.distance_km }} km</span></div>
                            <div class="small text-success fw-semibold">{{ fmt(o.price) }} • Arrivée estimée : {{ o.eta_minutes }} min</div>
                            <div v-if="o.message" class="small text-muted">{{ o.message }}</div>
                        </div>
                        <div>
                            <button v-if="sos.status === 'open'" type="button" class="btn btn-success btn-sm fw-semibold" @click="acceptOffer(o.id)">
                                <i class="bi bi-check-circle me-1"></i>Accepter cette offre
                            </button>
                            <span v-else class="badge" :class="o.status === 'accepted' ? 'bg-success' : 'bg-secondary'">{{ o.status }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
