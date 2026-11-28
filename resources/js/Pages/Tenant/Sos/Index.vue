<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    openRequests: { type: Array, default: () => [] },
    myMissions: { type: Array, default: () => [] },
    tenantLocation: Object,
});

const liveOpenRequests = ref([...props.openRequests]);
const liveMyMissions = ref([...props.myMissions]);
const newlyAcceptedBanner = ref('');
const lastSyncedLabel = ref('En direct');

watch(() => props.openRequests, (val) => { liveOpenRequests.value = [...(val || [])]; });
watch(() => props.myMissions, (val) => { liveMyMissions.value = [...(val || [])]; });

let feedTimer = null;
let feedAbort = null;

async function pollSosFeed() {
    if (typeof document !== 'undefined' && document.hidden) return;
    try {
        if (feedAbort) feedAbort.abort();
        feedAbort = new AbortController();
        const resp = await fetch('/depannage-sos/feed', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: feedAbort.signal,
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (!data?.ok) return;

        const prevMissionIds = new Set(liveMyMissions.value.map((m) => m.id));
        const nextMissions = data.myMissions || [];
        const newlyAccepted = nextMissions.find((m) => !prevMissionIds.has(m.id));
        if (newlyAccepted) {
            newlyAcceptedBanner.value = `Devis accepté par ${newlyAccepted.driver_name} (${newlyAccepted.driver_phone}) — ${fmt(newlyAccepted.accepted_price)} !`;
        }

        liveOpenRequests.value = data.openRequests || [];
        liveMyMissions.value = nextMissions;
        const now = new Date();
        lastSyncedLabel.value = `Synchro ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}`;
    } catch {
        // Silencieux : ne bloque jamais l'utilisateur
    }
}

onMounted(() => {
    feedTimer = setInterval(pollSosFeed, 6000);
});

onBeforeUnmount(() => {
    if (feedTimer) clearInterval(feedTimer);
    if (feedAbort) feedAbort.abort();
});

const offeringReq = ref(null);
const offerForm = useForm({
    price: 15000,
    eta_minutes: 25,
    message: 'Dépanneuse prête à partir immédiatement.',
});

function startOffer(req) {
    offeringReq.value = req;
    offerForm.price = req.my_offer?.price ?? 15000;
    offerForm.eta_minutes = req.my_offer?.eta_minutes ?? 25;
    offerForm.message = req.my_offer?.message ?? 'Dépanneuse prête à partir immédiatement.';
}

function submitOffer() {
    if (!offeringReq.value) return;
    offerForm.post(`/depannage-sos/${offeringReq.value.id}/offre`, {
        preserveScroll: true,
        onSuccess: () => {
            offeringReq.value = null;
            pollSosFeed();
        },
    });
}

function setMissionStatus(id, status) {
    router.patch(`/depannage-sos/${id}/statut`, { status }, {
        preserveScroll: true,
        onSuccess: () => pollSosFeed(),
    });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="SOS Dépannage Géolocalisé" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0"><i class="bi bi-geo-alt-fill text-primary me-2"></i>SOS Dépannage &amp; Remorquage</h1>
                <p class="text-muted small mb-0">
                    Alertes en direct autour de votre atelier ({{ tenantLocation?.formatted_location || 'Localisation à configurer dans Paramètres' }} — rayon {{ tenantLocation?.sos_radius_km }} km)
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2 small">
                    <i class="bi bi-broadcast me-1"></i>{{ lastSyncedLabel }}
                </span>
                <a href="/sos-depannage" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Page publique SOS
                </a>
            </div>
        </div>

        <div v-if="newlyAcceptedBanner" class="alert alert-success border-0 shadow-sm rounded-4 d-flex justify-content-between align-items-center mb-4">
            <div>
                <i class="bi bi-check-circle-fill me-2"></i>
                <strong>Nouvelle mission confirmée automatiquement :</strong> {{ newlyAcceptedBanner }}
            </div>
            <button type="button" class="btn-close btn-sm" @click="newlyAcceptedBanner = ''"></button>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-broadcast text-primary me-2"></i>Alertes SOS en attente ({{ liveOpenRequests.length }})</span>
                        <span class="small fw-normal text-muted">Mise à jour auto</span>
                    </div>
                    <div class="list-group list-group-flush">
                        <div v-if="!liveOpenRequests.length" class="p-4 text-center text-muted small">
                            Aucune panne SOS signalée dans votre zone pour le moment.
                        </div>
                        <div v-for="req in liveOpenRequests" :key="req.id" class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="badge bg-primary me-1">{{ req.breakdown_type }}</span>
                                    <span v-if="req.distance_km !== null" class="badge bg-success-subtle text-success">{{ req.distance_km }} km</span>
                                    <span class="small text-muted ms-1">{{ req.created_at }}</span>
                                    <div class="fw-bold mt-1">{{ req.driver_name }} — {{ req.driver_phone }}</div>
                                    <div class="small text-muted">{{ req.vehicle_info || 'Véhicule non précisé' }} • {{ req.location_label }}</div>
                                    <p v-if="req.description" class="small mt-1 mb-0">{{ req.description }}</p>
                                    <div v-if="req.my_offer" class="small text-primary fw-semibold mt-1">
                                        <i class="bi bi-clock-history me-1"></i>Votre offre envoyée : {{ fmt(req.my_offer.price) }} ({{ req.my_offer.eta_minutes }} min) — en attente de validation client
                                    </div>
                                </div>
                                <div class="d-flex flex-column gap-1 text-nowrap">
                                    <a
                                        :href="`https://www.openstreetmap.org/?mlat=${req.latitude}&mlon=${req.longitude}#map=16/${req.latitude}/${req.longitude}`"
                                        target="_blank"
                                        class="btn btn-outline-secondary btn-sm"
                                    >
                                        <i class="bi bi-map me-1"></i>Carte GPS
                                    </a>
                                    <button type="button" class="btn btn-primary btn-sm" @click="startOffer(req)">
                                        <i class="bi bi-send me-1"></i>{{ req.my_offer ? 'Modifier offre' : 'Proposer tarif' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="offeringReq" class="card border-0 shadow-sm border-start border-4 border-primary mt-3 rounded-4">
                    <div class="card-header bg-white fw-bold py-2">Envoyer une offre à {{ offeringReq.driver_name }}</div>
                    <div class="card-body">
                        <form class="row g-2 align-items-end" @submit.prevent="submitOffer">
                            <div class="col-md-4">
                                <label class="form-label small">Tarif estimé (FCFA)</label>
                                <input v-model.number="offerForm.price" type="number" min="0" class="form-control form-control-sm" required />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Arrivée (min)</label>
                                <input v-model.number="offerForm.eta_minutes" type="number" min="5" class="form-control form-control-sm" required />
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small">Message rapide</label>
                                <input v-model="offerForm.message" type="text" class="form-control form-control-sm" />
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="offeringReq = null">Annuler</button>
                                <button type="submit" class="btn btn-primary btn-sm px-4" :disabled="offerForm.processing">Envoyer l'offre</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white fw-bold py-3">
                        <i class="bi bi-truck text-success me-2"></i>Mes missions SOS acceptées ({{ liveMyMissions.length }})
                    </div>
                    <div class="list-group list-group-flush">
                        <div v-if="!liveMyMissions.length" class="p-4 text-center text-muted small">
                            Aucune mission SOS acceptée pour le moment.
                        </div>
                        <div v-for="m in liveMyMissions" :key="m.id" class="list-group-item p-3 small">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold">{{ m.driver_name }} ({{ m.driver_phone }})</span>
                                <span class="badge rounded-pill" :class="m.status === 'resolved' ? 'bg-success' : (m.status === 'on_the_way' ? 'bg-info text-dark' : 'bg-primary')">
                                    {{ m.status === 'resolved' ? 'Terminée' : (m.status === 'on_the_way' ? 'En route' : 'Acceptée') }}
                                </span>
                            </div>
                            <div class="text-muted mb-2">{{ m.breakdown_type }} • {{ fmt(m.accepted_price) }} • ETA {{ m.accepted_eta_minutes }} min</div>
                            <div v-if="m.status !== 'resolved'" class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" @click="setMissionStatus(m.id, 'on_the_way')">En route</button>
                                <button type="button" class="btn btn-success btn-sm" @click="setMissionStatus(m.id, 'resolved')">Terminer mission</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
