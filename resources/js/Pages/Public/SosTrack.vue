<script setup>
import LandingFooter from '@/Components/LandingFooter.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    sos: Object,
});

const acceptingId = ref(null);
const refreshing = ref(false);
const copiedLink = ref(false);
let pollInterval = null;

const breakdownMeta = computed(() => {
    const map = {
        batterie: { label: 'Batterie à plat / Démarrage', icon: 'bi-battery-charging', color: '#f59e0b' },
        crevaison: { label: 'Crevaison / Roue', icon: 'bi-record-circle', color: '#0ea5e9' },
        surchauffe: { label: 'Surchauffe moteur / Radiateur', icon: 'bi-thermometer-high', color: '#ef4444' },
        moteur: { label: 'Panne moteur / Calage', icon: 'bi-gear-wide-connected', color: '#dc2626' },
        accident: { label: 'Accident / Carrosserie', icon: 'bi-exclamation-triangle-fill', color: '#b91c1c' },
        cle: { label: 'Clé bloquée / Serrure', icon: 'bi-key-fill', color: '#6366f1' },
        remorquage: { label: 'Remorquage plateau', icon: 'bi-truck-flatbed', color: '#2563eb' },
    };
    return map[props.sos?.breakdown_type] || { label: props.sos?.breakdown_type || 'Dépannage', icon: 'bi-wrench-adjustable', color: '#dc2626' };
});

const statusMeta = computed(() => {
    const map = {
        open: { label: 'En recherche de dépanneur', badge: 'bg-danger', step: props.sos?.offers?.length ? 2 : 1 },
        accepted: { label: 'Dépanneur confirmé', badge: 'bg-primary', step: 3 },
        on_the_way: { label: 'Dépanneur en route', badge: 'bg-info text-dark', step: 3 },
        resolved: { label: 'Dépannage terminé', badge: 'bg-success', step: 4 },
        cancelled: { label: 'Demande annulée', badge: 'bg-secondary', step: 1 },
    };
    return map[props.sos?.status] || { label: props.sos?.status, badge: 'bg-secondary', step: 1 };
});

const steps = [
    { num: 1, label: 'Alerte diffusée', icon: 'bi-broadcast' },
    { num: 2, label: 'Offres reçues', icon: 'bi-tags-fill' },
    { num: 3, label: 'Dépanneur en route', icon: 'bi-truck' },
    { num: 4, label: 'Terminé', icon: 'bi-check-circle-fill' },
];

const osmEmbedUrl = computed(() => {
    const lat = Number(props.sos?.latitude);
    const lon = Number(props.sos?.longitude);
    if (!lat && !lon) return '';
    const d = 0.018;
    const bbox = `${(lon - d).toFixed(5)},${(lat - d).toFixed(5)},${(lon + d).toFixed(5)},${(lat + d).toFixed(5)}`;
    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lon}`;
});

function acceptOffer(offerId) {
    acceptingId.value = offerId;
    router.post(`/sos-depannage/${props.sos.token}/offres/${offerId}/accepter`, {}, {
        preserveScroll: true,
        onFinish: () => {
            acceptingId.value = null;
        },
    });
}

function manualRefresh() {
    refreshing.value = true;
    router.reload({
        only: ['sos'],
        preserveScroll: true,
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

async function copyTrackingLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        copiedLink.value = true;
        setTimeout(() => {
            copiedLink.value = false;
        }, 2500);
    } catch {
        // ignore clipboard error
    }
}

function whatsappHref(phone) {
    const digits = String(phone || '').replace(/\D+/g, '');
    return digits ? `https://wa.me/${digits}` : '#';
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

onMounted(() => {
    if (props.sos?.status !== 'resolved' && props.sos?.status !== 'cancelled') {
        pollInterval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['sos'], preserveScroll: true });
            }
        }, 15000);
    }
});

onBeforeUnmount(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <Head :title="`Suivi SOS — ${breakdownMeta.label}`" />
    <div class="min-vh-100 d-flex flex-column sos-track-bg">
        <!-- Navbar supérieure -->
        <nav class="navbar navbar-dark shadow-sm py-2" style="background: #0f172a;">
            <div class="container d-flex justify-content-between align-items-center gap-2">
                <Link href="/" class="navbar-brand fw-bold d-flex align-items-center gap-2 mb-0">
                    <span class="rounded-circle bg-danger d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="bi bi-geo-alt-fill text-white small"></i>
                    </span>
                    <span>MboaCar <span class="text-danger">SOS</span></span>
                </Link>
                <div class="d-flex align-items-center gap-2">
                    <Link href="/sos-depannage" class="btn btn-outline-light btn-sm rounded-pill px-3">
                        <i class="bi bi-arrow-left me-1"></i>Retour carte
                    </Link>
                    <button type="button" class="btn btn-light btn-sm rounded-pill px-3 fw-semibold" :disabled="refreshing" @click="manualRefresh">
                        <i class="bi bi-arrow-clockwise me-1" :class="{ 'sos-spin': refreshing }"></i>Actualiser
                    </button>
                </div>
            </div>
        </nav>

        <!-- Hero sombre avec statut en direct & Stepper 4 étapes -->
        <section class="sos-hero text-white py-4">
            <div class="container">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div class="d-flex align-items-start gap-3">
                        <div
                            class="rounded-4 d-flex align-items-center justify-content-center flex-shrink-0 shadow"
                            style="width: 56px; height: 56px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2);"
                        >
                            <i class="bi fs-3" :class="breakdownMeta.icon" :style="{ color: breakdownMeta.color }"></i>
                        </div>
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge rounded-pill px-3 py-1 fw-semibold" :class="statusMeta.badge">
                                    <span v-if="sos.status === 'open'" class="sos-live-dot me-1"></span>
                                    {{ statusMeta.label }}
                                </span>
                                <span class="small text-white-50">
                                    <i class="bi bi-clock me-1"></i>{{ sos.created_at }}
                                </span>
                            </div>
                            <h1 class="h4 fw-bold mb-1">{{ breakdownMeta.label }}</h1>
                            <div class="small text-white-75 d-flex flex-wrap gap-3">
                                <span><i class="bi bi-car-front-fill me-1 text-info"></i>{{ sos.vehicle_info || 'Véhicule non précisé' }}</span>
                                <span><i class="bi bi-pin-map-fill me-1 text-warning"></i>{{ sos.location_label || 'Position GPS enregistrée' }}</span>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-3" @click="copyTrackingLink">
                        <i class="bi me-1" :class="copiedLink ? 'bi-check2 text-success' : 'bi-share-fill'"></i>
                        {{ copiedLink ? 'Lien de suivi copié !' : 'Partager le suivi' }}
                    </button>
                </div>

                <!-- Stepper visuel 4 étapes -->
                <div class="sos-stepper bg-white bg-opacity-10 rounded-4 p-3 border border-white border-opacity-10">
                    <div class="row g-2 text-center">
                        <div v-for="st in steps" :key="st.num" class="col-6 col-md-3">
                            <div
                                class="d-flex align-items-center justify-content-center gap-2 py-2 px-2 rounded-3 small fw-semibold"
                                :class="statusMeta.step >= st.num ? 'bg-white text-dark shadow-sm' : 'text-white-50'"
                            >
                                <i
                                    class="bi"
                                    :class="statusMeta.step > st.num ? 'bi-check-circle-fill text-success' : st.icon"
                                ></i>
                                <span class="text-truncate">{{ st.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contenu principal 2 colonnes -->
        <main class="container py-4 flex-grow-1">
            <div class="row g-4">
                <!-- Colonne gauche : Description + Dépanneur confirmé / Offres des garages -->
                <div class="col-lg-7">
                    <!-- Note du conducteur -->
                    <div v-if="sos.description" class="card border-0 shadow-sm rounded-4 mb-4">
                        <div class="card-body p-3 px-4 d-flex align-items-start gap-3">
                            <span class="rounded-circle bg-light border d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                                <i class="bi bi-chat-left-quote-fill text-primary"></i>
                            </span>
                            <div>
                                <div class="small fw-bold text-uppercase text-muted" style="font-size: 0.7rem; letter-spacing: 0.04em;">Votre signalement</div>
                                <div class="text-dark mt-1">{{ sos.description }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Carte Dépanneur confirmé (après acceptation) -->
                    <div v-if="sos.accepted_tenant" class="card border-0 shadow rounded-4 mb-4 overflow-hidden sos-confirmed-card">
                        <div class="card-header text-white py-3 px-4 border-0" style="background: linear-gradient(135deg, #059669, #10b981);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="fw-bold d-flex align-items-center gap-2">
                                    <i class="bi bi-patch-check-fill fs-5"></i>
                                    <span>Dépanneur confirmé &amp; mobilisé</span>
                                </div>
                                <span class="badge bg-white text-success rounded-pill px-3 py-1 fw-bold">
                                    <i class="bi bi-stopwatch me-1"></i>~{{ sos.accepted_eta_minutes }} min
                                </span>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <h2 class="h5 fw-bold mb-1">{{ sos.accepted_tenant.name }}</h2>
                                    <div class="small text-muted">
                                        <i class="bi bi-geo-alt me-1"></i>{{ sos.accepted_tenant.formatted_location || sos.accepted_tenant.city }}
                                    </div>
                                </div>
                                <div class="text-md-end">
                                    <div class="small text-muted">Tarif convenu</div>
                                    <div class="h4 fw-bold text-success mb-0">{{ fmt(sos.accepted_price) }}</div>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a
                                    v-if="sos.accepted_tenant.phone"
                                    :href="`tel:${sos.accepted_tenant.phone}`"
                                    class="btn btn-success rounded-pill px-4 fw-semibold"
                                >
                                    <i class="bi bi-telephone-fill me-2"></i>Appeler ({{ sos.accepted_tenant.phone }})
                                </a>
                                <a
                                    v-if="sos.accepted_tenant.phone"
                                    :href="whatsappHref(sos.accepted_tenant.phone)"
                                    target="_blank"
                                    class="btn btn-outline-success rounded-pill px-3 fw-semibold"
                                >
                                    <i class="bi bi-whatsapp me-1"></i>WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Liste des offres des garages partenaires -->
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                            <div>
                                <h2 class="h6 fw-bold mb-0">
                                    <i class="bi bi-tools text-primary me-2"></i>Offres des garages partenaires
                                    <span class="badge bg-primary-subtle text-primary rounded-pill ms-1">{{ sos.offers?.length || 0 }}</span>
                                </h2>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    Actualisation automatique toutes les 15 secondes
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" :disabled="refreshing" @click="manualRefresh">
                                <i class="bi bi-arrow-clockwise me-1" :class="{ 'sos-spin': refreshing }"></i>Rafraîchir
                            </button>
                        </div>

                        <div class="card-body p-3 p-md-4">
                            <!-- État d'attente animé -->
                            <div v-if="!sos.offers?.length" class="text-center py-5">
                                <div class="sos-radar mx-auto mb-3">
                                    <i class="bi bi-broadcast fs-2 text-danger"></i>
                                </div>
                                <h3 class="h6 fw-bold mb-1">Alerte diffusée aux dépanneurs proches...</h3>
                                <p class="small text-muted mb-0 mx-auto" style="max-width: 380px;">
                                    Les garages partenaires autour de <strong>{{ sos.location_label || 'votre position' }}</strong> viennent d'être notifiés. Leurs offres (tarif + délai) apparaîtront ici automatiquement.
                                </p>
                            </div>

                            <!-- Cartes d'offres -->
                            <div v-else class="vstack gap-3">
                                <div
                                    v-for="o in sos.offers"
                                    :key="o.id"
                                    class="sos-offer-card rounded-4 p-3 p-md-4 border"
                                    :class="o.status === 'accepted' ? 'border-success bg-success bg-opacity-10' : 'bg-white'"
                                >
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="rounded-3 bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px;">
                                                <i class="bi bi-building-fill-gear fs-4"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                    <h3 class="h6 fw-bold mb-0">{{ o.garage_name }}</h3>
                                                    <span v-if="o.distance_km !== null" class="badge bg-light text-dark border rounded-pill">
                                                        <i class="bi bi-geo-alt-fill text-danger me-1"></i>{{ o.distance_km }} km
                                                    </span>
                                                    <span v-if="o.garage_city" class="small text-muted">{{ o.garage_city }}</span>
                                                </div>

                                                <div class="d-flex flex-wrap align-items-center gap-3 my-2">
                                                    <span class="h5 fw-bold text-success mb-0">{{ fmt(o.price) }}</span>
                                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle rounded-pill px-3 py-1">
                                                        <i class="bi bi-stopwatch-fill text-warning me-1"></i>Arrivée estimée : {{ o.eta_minutes }} min
                                                    </span>
                                                </div>

                                                <div v-if="o.message" class="small text-muted bg-light rounded-3 px-3 py-2 mt-2">
                                                    <i class="bi bi-chat-dots me-1 text-primary"></i>{{ o.message }}
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-column align-items-stretch gap-2 ms-auto">
                                            <button
                                                v-if="sos.status === 'open'"
                                                type="button"
                                                class="btn btn-success rounded-pill px-4 py-2 fw-semibold shadow-sm"
                                                :disabled="acceptingId === o.id"
                                                @click="acceptOffer(o.id)"
                                            >
                                                <span v-if="acceptingId === o.id" class="spinner-border spinner-border-sm me-1"></span>
                                                <i v-else class="bi bi-check-circle-fill me-1"></i>Accepter cette offre
                                            </button>
                                            <span
                                                v-else
                                                class="badge rounded-pill px-3 py-2"
                                                :class="o.status === 'accepted' ? 'bg-success' : 'bg-secondary'"
                                            >
                                                {{ o.status === 'accepted' ? 'Offre retenue' : 'Non retenue' }}
                                            </span>
                                            <a
                                                v-if="o.garage_phone"
                                                :href="`tel:${o.garage_phone}`"
                                                class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                                            >
                                                <i class="bi bi-telephone me-1"></i>Appeler
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : Carte OpenStreetMap + Consignes de sécurité -->
                <div class="col-lg-5">
                    <div v-if="osmEmbedUrl" class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                        <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small"><i class="bi bi-map-fill text-primary me-2"></i>Position de la panne (GPS)</span>
                            <a
                                :href="`https://www.openstreetmap.org/?mlat=${sos.latitude}&mlon=${sos.longitude}#map=16/${sos.latitude}/${sos.longitude}`"
                                target="_blank"
                                class="btn btn-outline-primary btn-sm rounded-pill py-0 px-2"
                                style="font-size: 0.74rem;"
                            >
                                Ouvrir OSM <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                        <iframe
                            :src="osmEmbedUrl"
                            style="width: 100%; height: 270px; border: 0;"
                            loading="lazy"
                            title="Carte GPS de la panne"
                        ></iframe>
                        <div class="card-footer bg-white small text-muted py-2 px-3 d-flex justify-content-between">
                            <span><i class="bi bi-crosshair me-1"></i>{{ Number(sos.latitude).toFixed(5) }}, {{ Number(sos.longitude).toFixed(5) }}</span>
                            <span>OpenStreetMap</span>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 bg-white">
                        <div class="card-body p-4">
                            <h3 class="h6 fw-bold mb-3">
                                <i class="bi bi-shield-exclamation text-warning me-2"></i>Consignes de sécurité en attendant
                            </h3>
                            <ul class="list-unstyled small text-muted vstack gap-2 mb-0">
                                <li><i class="bi bi-check2-circle text-success me-2"></i>Allumez vos <strong>feux de détresse</strong> et garez-vous sur le bas-côté.</li>
                                <li><i class="bi bi-check2-circle text-success me-2"></i>Placez le <strong>triangle de signalisation</strong> à 30 mètres minimum.</li>
                                <li><i class="bi bi-check2-circle text-success me-2"></i>Gardez votre téléphone chargé pour l'appel du dépanneur.</li>
                                <li><i class="bi bi-check2-circle text-success me-2"></i>Ne réglez qu'après validation du tarif affiché ci-dessus.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <LandingFooter />
    </div>
</template>

<style scoped>
.sos-track-bg {
    background: #f1f5f9;
}

.sos-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #1e3a8a 100%);
}

.sos-offer-card {
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.sos-offer-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    border-color: #93c5fd !important;
}

.sos-live-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: #ffffff;
    animation: sosPulse 1.2s infinite;
}

.sos-radar {
    width: 72px;
    height: 72px;
    border-radius: 999px;
    background: rgba(220, 38, 38, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    animation: sosRadarPulse 1.8s infinite;
}

.sos-spin {
    display: inline-block;
    animation: sosSpin 0.8s linear infinite;
}

@keyframes sosPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.25; }
}

@keyframes sosRadarPulse {
    0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35); }
    70% { box-shadow: 0 0 0 18px rgba(220, 38, 38, 0); }
    100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
}

@keyframes sosSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
