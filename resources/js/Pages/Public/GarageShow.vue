<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import LandingFooter from '@/Components/LandingFooter.vue';
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    garage: { type: Object, required: true },
    services: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
    defaultDialCode: { type: String, default: '+237' },
});

const page = usePage();
const siteLogo = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashError = computed(() => page.props.flash?.error || '');

const galleryPhotos = computed(() => props.garage.photos || []);
const showLightbox = ref(false);
const lightboxIdx = ref(0);
const copiedShare = ref(false);
const showAllServices = ref(false);

function openLightbox(idx = 0) {
    lightboxIdx.value = idx;
    showLightbox.value = true;
}
function prevLightbox() {
    if (!galleryPhotos.value.length) return;
    lightboxIdx.value = (lightboxIdx.value - 1 + galleryPhotos.value.length) % galleryPhotos.value.length;
}
function nextLightbox() {
    if (!galleryPhotos.value.length) return;
    lightboxIdx.value = (lightboxIdx.value + 1) % galleryPhotos.value.length;
}

async function copyShareLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        copiedShare.value = true;
        setTimeout(() => {
            copiedShare.value = false;
        }, 2200);
    } catch {
        // fallback silencieux
    }
}

// Sélection des prestations
const selectedIds = ref([]);
function toggleService(id) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) {
        selectedIds.value.push(id);
    } else {
        selectedIds.value.splice(idx, 1);
    }
}

const visibleServices = computed(() =>
    showAllServices.value ? props.services : props.services.slice(0, 4)
);

const selectedServices = computed(() =>
    props.services.filter((s) => selectedIds.value.includes(s.id))
);
const totalEstimatedPrice = computed(() =>
    selectedServices.value.reduce((acc, s) => acc + Number(s.price || 0), 0)
);
const totalEstimatedDuration = computed(() =>
    selectedServices.value.reduce((acc, s) => acc + Number(s.duration_minutes || 0), 0)
);
const startingPrice = computed(() => {
    const priced = props.services.map((s) => Number(s.price || 0)).filter((p) => p > 0);
    return priced.length ? Math.min(...priced) : null;
});

const categoryLabels = {
    entretien: 'Entretien & Vidange',
    mecanique: 'Mécanique générale',
    diagnostic: 'Diagnostic OBD',
    carrosserie: 'Carrosserie & Peinture',
    pneumatique: 'Pneumatiques',
    climatisation: 'Climatisation',
    visite_technique: 'Visite technique',
    depannage: 'Dépannage & Remorquage',
    autre: 'Autre prestation',
};

// Date & heure par défaut (jQuery UI DatePickerInput + TimePickerInput)
const defaultSlot = (() => {
    const d = new Date(Date.now() + 2 * 3600 * 1000);
    const pad = (n) => String(n).padStart(2, '0');
    let h = d.getHours();
    if (h < 6) h = 8;
    if (h > 22) {
        d.setDate(d.getDate() + 1);
        h = 9;
    }
    return {
        date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`,
        time: `${pad(h)}:00`,
    };
})();

const scheduledDate = ref(defaultSlot.date);
const scheduledTime = ref(defaultSlot.time);

const bookingForm = useForm({
    service_ids: [],
    custom_service: '',
    customer_name: '',
    customer_first_name: '',
    dial_code: props.defaultDialCode || '+237',
    customer_phone: '',
    vehicle_brand: '',
    vehicle_model: '',
    vehicle_plate: '',
    scheduled_at: `${defaultSlot.date} ${defaultSlot.time}:00`,
    notes: '',
});

const bookingDone = ref(false);

function submitBooking() {
    bookingForm.service_ids = [...selectedIds.value];
    bookingForm.scheduled_at = scheduledDate.value && scheduledTime.value
        ? `${scheduledDate.value} ${scheduledTime.value}:00`
        : '';
    bookingForm.post(`/garage/${props.garage.slug}/rendez-vous`, {
        preserveScroll: true,
        onSuccess: () => {
            bookingDone.value = true;
            selectedIds.value = [];
            bookingForm.reset('custom_service', 'notes');
        },
    });
}

function formatMoney(n) {
    return new Intl.NumberFormat('fr-FR').format(Number(n || 0));
}

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <Head :title="`${garage.name} — Prendre rendez-vous | MboaCar`" />

    <div class="min-vh-100 d-flex flex-column bg-white">
        <!-- Navbar épurée MboaCar -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top py-2" style="z-index: 1030;">
            <div class="container" style="max-width: 1140px;">
                <Link href="/" class="navbar-brand d-flex align-items-center gap-2 fw-bold">
                    <img :src="siteLogo" alt="MboaCar" style="height: 36px; width: auto; object-fit: contain;" />
                </Link>
                <div class="d-flex align-items-center gap-2">
                    <Link href="/sos-depannage" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-arrow-left me-1"></i>Garages à proximité
                    </Link>
                    <Link href="/connexion" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-person-circle me-1"></i>Connexion
                    </Link>
                </div>
            </div>
        </nav>

        <main class="container py-4 flex-grow-1" style="max-width: 1140px;">
            <!-- Flash succès / erreur -->
            <div v-if="flashSuccess || bookingDone" class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-start gap-3 mb-4">
                <i class="bi bi-check-circle-fill fs-4 text-success mt-1"></i>
                <div>
                    <div class="fw-bold">Rendez-vous enregistré et transmis !</div>
                    <div class="small">{{ flashSuccess || 'Votre rendez-vous est bien enregistré. L\'établissement vient de recevoir votre demande sur WhatsApp et dans son espace MboaCar.' }}</div>
                </div>
            </div>
            <div v-if="flashError" class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
                {{ flashError }}
            </div>

            <!-- En-tête titre + Partager (façon Airbnb) -->
            <div id="section-photos" class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h1 class="h3 fw-bold text-dark mb-0">{{ garage.name }}</h1>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-link text-dark text-decoration-none btn-sm fw-semibold d-inline-flex align-items-center gap-1" @click="copyShareLink">
                        <i class="bi" :class="copiedShare ? 'bi-check2 text-success' : 'bi-upload'"></i>
                        <span>{{ copiedShare ? 'Lien copié !' : 'Partager' }}</span>
                    </button>
                    <a v-if="garage.phone" :href="`tel:${garage.phone}`" class="btn btn-outline-dark btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-telephone me-1"></i>Appeler
                    </a>
                </div>
            </div>

            <!-- Mosaïque Photos Airbnb (1 grande à gauche + 4 à droite + bouton "Afficher toutes les photos") -->
            <div class="position-relative mb-4">
                <div v-if="galleryPhotos.length >= 5" class="airbnb-mosaic airbnb-mosaic--5">
                    <div class="airbnb-mosaic-main" @click="openLightbox(0)">
                        <img :src="galleryPhotos[0]" :alt="garage.name" />
                    </div>
                    <div class="airbnb-mosaic-grid">
                        <div v-for="(url, i) in galleryPhotos.slice(1, 5)" :key="url" class="airbnb-mosaic-tile" @click="openLightbox(i + 1)">
                            <img :src="url" :alt="garage.name" />
                        </div>
                    </div>
                </div>

                <div v-else-if="galleryPhotos.length >= 2" class="airbnb-mosaic airbnb-mosaic--few">
                    <div class="airbnb-mosaic-main" @click="openLightbox(0)">
                        <img :src="galleryPhotos[0]" :alt="garage.name" />
                    </div>
                    <div class="airbnb-mosaic-side" :class="`tiles-${galleryPhotos.length - 1}`">
                        <div v-for="(url, i) in galleryPhotos.slice(1)" :key="url" class="airbnb-mosaic-tile" @click="openLightbox(i + 1)">
                            <img :src="url" :alt="garage.name" />
                        </div>
                    </div>
                </div>

                <div v-else-if="galleryPhotos.length === 1" class="airbnb-mosaic-single" @click="openLightbox(0)">
                    <img :src="galleryPhotos[0]" :alt="garage.name" />
                </div>

                <div
                    v-else
                    class="rounded-4 d-flex flex-column align-items-center justify-content-center text-white p-5"
                    style="height: 300px; background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 60%, #0284c7 100%);"
                >
                    <i class="bi bi-building-gear fs-1 mb-2 opacity-75"></i>
                    <div class="fw-bold fs-4">{{ garage.name }}</div>
                    <div class="small opacity-75">{{ garage.formatted_location || garage.city }}</div>
                </div>

                <button
                    v-if="galleryPhotos.length > 0"
                    type="button"
                    class="btn btn-white bg-white text-dark border border-dark-subtle shadow-sm btn-sm rounded-3 fw-semibold position-absolute bottom-0 end-0 m-3 px-3 py-2 d-inline-flex align-items-center gap-2"
                    @click="openLightbox(0)"
                >
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    <span>Afficher toutes les photos ({{ galleryPhotos.length }})</span>
                </button>
            </div>

            <!-- Sous-titre + Note + Badges -->
            <div class="mb-4 pb-4 border-bottom">
                <h2 class="h5 fw-bold text-dark mb-1">
                    {{ garage.type_label }} — {{ garage.formatted_location || garage.city || 'Afrique' }}
                </h2>
                <div class="d-flex flex-wrap align-items-center gap-2 small text-dark mb-2">
                    <span v-if="garage.rating" class="fw-semibold">
                        <i class="bi bi-star-fill text-dark me-1" style="font-size: 0.78rem;"></i>{{ garage.rating }} · {{ garage.reviews_count }} avis vérifiés
                    </span>
                    <span v-else class="text-muted">Établissement partenaire vérifié MboaCar</span>
                    <span v-if="garage.phone" class="text-muted">·</span>
                    <span v-if="garage.phone" class="fw-semibold"><i class="bi bi-telephone text-primary me-1"></i>{{ garage.phone }}</span>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <span v-if="garage.sos_enabled" class="badge bg-success-subtle text-success rounded-pill px-3 py-1 fw-semibold">
                        <i class="bi bi-lightning-charge-fill me-1"></i>SOS Dépannage 24/7 actif
                    </span>
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold">
                        <i class="bi bi-whatsapp me-1"></i>Confirmation WhatsApp instantanée
                    </span>
                    <span class="badge bg-light text-secondary border rounded-pill px-3 py-1 fw-normal">
                        Aucun paiement requis en ligne
                    </span>
                </div>
            </div>

            <!-- Barre d'ancres façon Airbnb -->
            <div class="d-flex align-items-center gap-4 border-bottom pb-3 mb-4 overflow-auto flex-nowrap small fw-semibold">
                <button type="button" class="btn btn-link text-dark text-decoration-none p-0 flex-shrink-0" @click="scrollToSection('section-photos')">Photos</button>
                <button type="button" class="btn btn-link text-dark text-decoration-none p-0 flex-shrink-0" @click="scrollToSection('section-prestations')">Prestations</button>
                <button type="button" class="btn btn-link text-dark text-decoration-none p-0 flex-shrink-0" @click="scrollToSection('section-apropos')">À propos</button>
                <button v-if="reviews.length" type="button" class="btn btn-link text-dark text-decoration-none p-0 flex-shrink-0" @click="scrollToSection('section-avis')">Avis</button>
                <button v-if="garage.latitude && garage.longitude" type="button" class="btn btn-link text-dark text-decoration-none p-0 flex-shrink-0" @click="scrollToSection('section-emplacement')">Emplacement</button>
            </div>

            <!-- Split 2 colonnes façon Airbnb : Gauche infos + Prestations horizontales | Droite Carte RDV sticky -->
            <div class="row g-5 align-items-start">
                <!-- COLONNE GAUCHE -->
                <div class="col-lg-7">
                    <!-- 3 Points forts avec icônes (façon Airbnb highlights) -->
                    <div class="pb-4 mb-4 border-bottom d-flex flex-column gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="bi bi-calendar2-check fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold text-dark">Prise de rendez-vous en ligne directe</div>
                                <div class="small text-muted">Votre demande arrive instantanément dans le planning atelier et sur le WhatsApp du gérant.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="bi bi-shield-check fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold text-dark">Carnet d'entretien numérique MboaCar</div>
                                <div class="small text-muted">Chaque intervention réalisée dans cet établissement est traçable et transparente.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-circle bg-light border text-dark d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="bi bi-geo-alt fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold text-dark">Localisation GPS vérifiée</div>
                                <div class="small text-muted">{{ garage.formatted_location || garage.city }} — itinéraire direct sur OpenStreetMap.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Section Prestations (Cartes horizontales façon "Choisissez votre chambre" Airbnb) -->
                    <section id="section-prestations" class="pb-4 mb-4 border-bottom">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 fw-bold text-dark mb-0">Choisissez votre prestation</h2>
                            <span v-if="selectedIds.length" class="badge bg-primary rounded-pill px-3 py-1">
                                {{ selectedIds.length }} sélectionnée(s)
                            </span>
                        </div>

                        <div v-if="services.length === 0" class="p-4 bg-light rounded-4 text-muted small">
                            Cet établissement n'a pas encore publié de prestations tarifées. Indiquez votre besoin dans le formulaire de rendez-vous à droite.
                        </div>

                        <div v-else class="d-flex flex-column gap-3">
                            <div
                                v-for="svc in visibleServices"
                                :key="svc.id"
                                class="airbnb-service-card d-flex flex-column flex-sm-row align-items-stretch gap-3 p-3 rounded-4 border"
                                :class="selectedIds.includes(svc.id) ? 'is-selected' : ''"
                                @click="toggleService(svc.id)"
                            >
                                <!-- Miniature gauche -->
                                <div class="airbnb-service-thumb rounded-3 overflow-hidden flex-shrink-0">
                                    <img v-if="svc.image_url" :src="svc.image_url" :alt="svc.name" class="w-100 h-100" style="object-fit: cover;" />
                                    <div
                                        v-else
                                        class="w-100 h-100 d-flex align-items-center justify-content-center text-primary bg-primary-subtle"
                                    >
                                        <i class="bi bi-wrench-adjustable fs-2"></i>
                                    </div>
                                </div>

                                <!-- Détails droite -->
                                <div class="flex-grow-1 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <h3 class="h6 fw-bold text-dark mb-1">{{ svc.name }}</h3>
                                            <span
                                                class="rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 border"
                                                :class="selectedIds.includes(svc.id) ? 'bg-primary border-primary text-white' : 'bg-white text-muted'"
                                                style="width: 24px; height: 24px;"
                                            >
                                                <i class="bi" :class="selectedIds.includes(svc.id) ? 'bi-check-lg' : 'bi-plus'"></i>
                                            </span>
                                        </div>
                                        <div class="small text-muted mb-1">
                                            {{ categoryLabels[svc.category] || svc.category }} · ~{{ svc.duration_minutes }} min
                                        </div>
                                        <p v-if="svc.description" class="small text-muted mb-2 line-clamp-2">{{ svc.description }}</p>
                                    </div>
                                    <div class="fw-bold text-dark">
                                        {{ svc.price > 0 ? `${formatMoney(svc.price)} FCFA` : 'Sur devis' }}
                                        <span v-if="svc.price > 0" class="fw-normal text-muted small"> au total</span>
                                    </div>
                                </div>
                            </div>

                            <button
                                v-if="services.length > 4"
                                type="button"
                                class="btn btn-light border rounded-3 py-2 fw-semibold text-dark mt-1"
                                @click="showAllServices = !showAllServices"
                            >
                                {{ showAllServices ? 'Réduire la liste' : `Afficher les ${services.length} prestations` }}
                            </button>
                        </div>
                    </section>

                    <!-- Section À propos & Services proposés -->
                    <section id="section-apropos" class="pb-4 mb-4 border-bottom">
                        <h2 class="h5 fw-bold text-dark mb-2">À propos de {{ garage.name }}</h2>
                        <p class="text-muted small mb-4">
                            {{ garage.name }} est un établissement référencé sur le réseau MboaCar ({{ garage.type_label }}) situé à {{ garage.formatted_location || garage.city }}.
                            Réservez directement votre créneau en ligne pour bénéficier d'une prise en charge rapide et d'un suivi transparent.
                        </p>

                        <h3 class="h6 fw-bold text-dark mb-3">Ce que propose cet établissement</h3>
                        <div class="row g-3 small text-dark">
                            <div class="col-6 d-flex align-items-center gap-2"><i class="bi bi-whatsapp text-primary fs-5"></i><span>Notification WhatsApp directe</span></div>
                            <div class="col-6 d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text text-primary fs-5"></i><span>Devis & facturation clairs</span></div>
                            <div class="col-6 d-flex align-items-center gap-2"><i class="bi bi-qr-code-scan text-primary fs-5"></i><span>Suivi véhicule en ligne</span></div>
                            <div class="col-6 d-flex align-items-center gap-2"><i class="bi bi-clock-history text-primary fs-5"></i><span>Rendez-vous sans attente</span></div>
                            <div v-if="garage.sos_enabled" class="col-6 d-flex align-items-center gap-2"><i class="bi bi-truck text-primary fs-5"></i><span>Intervention SOS Dépannage</span></div>
                            <div class="col-6 d-flex align-items-center gap-2"><i class="bi bi-geo-alt text-primary fs-5"></i><span>Position GPS vérifiée</span></div>
                        </div>
                    </section>

                    <!-- Section Avis clients -->
                    <section v-if="reviews.length" id="section-avis" class="pb-4 mb-4 border-bottom">
                        <h2 class="h5 fw-bold text-dark mb-3">
                            <i class="bi bi-star-fill me-1"></i>{{ garage.rating }} · {{ garage.reviews_count }} avis clients
                        </h2>
                        <div class="row g-3">
                            <div v-for="rev in reviews" :key="rev.id" class="col-md-6">
                                <div class="p-3 border rounded-4 h-100 bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="text-warning small">
                                            <i v-for="n in 5" :key="n" class="bi" :class="n <= rev.rating ? 'bi-star-fill' : 'bi-star'"></i>
                                        </div>
                                        <span class="text-muted" style="font-size: 0.75rem;">{{ rev.date }}</span>
                                    </div>
                                    <p class="small mb-0 text-dark">{{ rev.comment || 'Très bonne prise en charge.' }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Section Emplacement OpenStreetMap -->
                    <section v-if="garage.latitude && garage.longitude" id="section-emplacement" class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h2 class="h5 fw-bold text-dark mb-0">Emplacement</h2>
                            <a
                                :href="`https://www.openstreetmap.org/?mlat=${garage.latitude}&mlon=${garage.longitude}#map=16/${garage.latitude}/${garage.longitude}`"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-outline-dark btn-sm rounded-pill px-3"
                            >
                                Ouvrir dans OpenStreetMap <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                        <p class="small text-muted mb-3">{{ garage.formatted_location || garage.city }}</p>
                        <div class="rounded-4 overflow-hidden border shadow-sm">
                            <OsmRouteMap
                                :breakdown-lat="garage.latitude"
                                :breakdown-lon="garage.longitude"
                                :breakdown-label="garage.name"
                                height="300px"
                            />
                        </div>
                    </section>
                </div>

                <!-- COLONNE DROITE STICKY : Carte Réservation façon Airbnb -->
                <div class="col-lg-5">
                    <div class="garage-booking-sticky">
                        <!-- Bannière pilule au-dessus de la carte (façon Airbnb) -->
                        <div class="border rounded-4 shadow-sm bg-white px-3 py-2 mb-3 d-flex align-items-center justify-content-center gap-2 small fw-semibold text-dark">
                            <i class="bi bi-whatsapp text-success fs-6"></i>
                            <span>Confirmation instantanée envoyée au garage</span>
                        </div>

                        <div class="card border rounded-4 shadow overflow-hidden">
                            <!-- En-tête "Sur devis / Tarif" fixe en haut de la carte au scroll -->
                            <div class="garage-booking-price-header bg-white px-4 pt-4 pb-3 border-bottom">
                                <div v-if="selectedServices.length" class="d-flex justify-content-between align-items-baseline">
                                    <span class="fs-5 fw-bold text-dark">
                                        {{ totalEstimatedPrice > 0 ? `${formatMoney(totalEstimatedPrice)} FCFA` : 'Sur devis' }}
                                    </span>
                                    <span class="small text-muted">{{ selectedServices.length }} prestation(s) · ~{{ totalEstimatedDuration }} min</span>
                                </div>
                                <div v-else class="d-flex justify-content-between align-items-baseline">
                                    <span class="fs-5 fw-bold text-dark">
                                        {{ startingPrice ? `À partir de ${formatMoney(startingPrice)} FCFA` : 'Sur devis' }}
                                    </span>
                                </div>

                                <div v-if="selectedServices.length" class="mt-2 d-flex flex-wrap gap-1">
                                    <span
                                        v-for="s in selectedServices"
                                        :key="s.id"
                                        class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1 d-inline-flex align-items-center gap-1"
                                        style="font-size: 0.72rem;"
                                    >
                                        <span>{{ s.name }}</span>
                                        <i class="bi bi-x" style="cursor: pointer;" @click="toggleService(s.id)"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="garage-booking-body p-4 pt-3">
                            <form @submit.prevent="submitBooking" class="row g-2">
                                <div class="col-7">
                                    <label class="form-label small fw-semibold mb-1">Date souhaitée *</label>
                                    <DatePickerInput
                                        v-model="scheduledDate"
                                        input-class="form-control form-control-sm"
                                        placeholder="AAAA-MM-JJ"
                                        :required="true"
                                    />
                                </div>
                                <div class="col-5">
                                    <label class="form-label small fw-semibold mb-1">Heure *</label>
                                    <TimePickerInput
                                        v-model="scheduledTime"
                                        input-class="form-select form-select-sm"
                                        :required="true"
                                    />
                                </div>
                                <div v-if="bookingForm.errors.scheduled_at" class="col-12 text-danger small">
                                    {{ bookingForm.errors.scheduled_at }}
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Autre prestation / motif (facultatif)</label>
                                    <input
                                        v-model="bookingForm.custom_service"
                                        type="text"
                                        class="form-control form-control-sm rounded-3"
                                        placeholder="Ex: Révision complète, bruit freinage..."
                                    />
                                </div>

                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">Nom *</label>
                                    <input v-model="bookingForm.customer_name" type="text" class="form-control form-control-sm rounded-3" required placeholder="Votre nom" />
                                    <div v-if="bookingForm.errors.customer_name" class="text-danger small">{{ bookingForm.errors.customer_name }}</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">Prénom (facultatif)</label>
                                    <input v-model="bookingForm.customer_first_name" type="text" class="form-control form-control-sm rounded-3" placeholder="Votre prénom" />
                                </div>

                                <div class="col-5">
                                    <label class="form-label small fw-semibold mb-1">Indicatif *</label>
                                    <select v-model="bookingForm.dial_code" class="form-select form-select-sm rounded-3">
                                        <option v-for="c in countries" :key="c.code" :value="c.dial_code">
                                            {{ c.dial_code }} ({{ c.code }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label small fw-semibold mb-1">WhatsApp *</label>
                                    <input v-model="bookingForm.customer_phone" type="tel" class="form-control form-control-sm rounded-3" required placeholder="6XX XXX XXX" />
                                    <div v-if="bookingForm.errors.customer_phone" class="text-danger small">{{ bookingForm.errors.customer_phone }}</div>
                                </div>

                                <div class="col-4">
                                    <label class="form-label small fw-semibold mb-1">Marque *</label>
                                    <input v-model="bookingForm.vehicle_brand" type="text" class="form-control form-control-sm rounded-3" required placeholder="Toyota" />
                                </div>
                                <div class="col-4">
                                    <label class="form-label small fw-semibold mb-1">Modèle *</label>
                                    <input v-model="bookingForm.vehicle_model" type="text" class="form-control form-control-sm rounded-3" required placeholder="Yaris" />
                                </div>
                                <div class="col-4">
                                    <label class="form-label small fw-semibold mb-1">Plaque *</label>
                                    <input v-model="bookingForm.vehicle_plate" type="text" class="form-control form-control-sm rounded-3 text-uppercase" required placeholder="LT 123 AB" />
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Précisions (facultatif)</label>
                                    <textarea v-model="bookingForm.notes" rows="2" class="form-control form-control-sm rounded-3" placeholder="Détails utiles pour l'atelier..."></textarea>
                                </div>

                                <div class="col-12 mt-3">
                                    <button
                                        type="submit"
                                        class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm"
                                        :disabled="bookingForm.processing"
                                    >
                                        <span v-if="bookingForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                        Confirmer mon rendez-vous
                                    </button>
                                    <div class="text-center text-muted mt-2" style="font-size: 0.75rem;">
                                        Aucun montant ne vous sera débité en ligne.
                                    </div>
                                </div>
                            </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <LandingFooter />
    </div>

    <!-- Lightbox Galerie Plein Écran -->
    <div
        v-if="showLightbox && galleryPhotos.length"
        class="position-fixed top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3"
        style="background: rgba(15, 23, 42, 0.92); z-index: 1080;"
        @click.self="showLightbox = false"
    >
        <div class="w-100 d-flex justify-content-between align-items-center px-3 mb-3 text-white" style="max-width: 980px;">
            <span class="small fw-semibold">{{ lightboxIdx + 1 }} / {{ galleryPhotos.length }} — {{ garage.name }}</span>
            <button type="button" class="btn btn-light btn-sm rounded-circle" style="width: 34px; height: 34px;" @click="showLightbox = false">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="position-relative d-flex align-items-center justify-content-center w-100" style="max-width: 980px; height: 74vh;">
            <img :src="galleryPhotos[lightboxIdx]" :alt="garage.name" class="rounded-4 shadow-lg" style="max-width: 100%; max-height: 100%; object-fit: contain;" />
            <template v-if="galleryPhotos.length > 1">
                <button
                    type="button"
                    class="btn btn-light rounded-circle position-absolute top-50 start-0 translate-middle-y ms-2 shadow"
                    style="width: 42px; height: 42px;"
                    @click="prevLightbox"
                >
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button
                    type="button"
                    class="btn btn-light rounded-circle position-absolute top-50 end-0 translate-middle-y me-2 shadow"
                    style="width: 42px; height: 42px;"
                    @click="nextLightbox"
                >
                    <i class="bi bi-chevron-right"></i>
                </button>
            </template>
        </div>
    </div>
</template>

<style scoped>
/* Colonne droite sticky + en-tête "Sur devis" toujours fixe au scroll */
.garage-booking-sticky {
    position: sticky;
    top: 76px;
    z-index: 20;
}

.garage-booking-price-header {
    position: sticky;
    top: 0;
    z-index: 5;
}

.garage-booking-body {
    max-height: calc(100vh - 230px);
    overflow-y: auto;
}

@media (max-width: 991.98px) {
    .garage-booking-sticky {
        position: static;
    }
    .garage-booking-body {
        max-height: none;
        overflow-y: visible;
    }
}

/* Mosaïque Photos façon Airbnb */
.airbnb-mosaic {
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 8px;
    height: 410px;
    border-radius: 18px;
    overflow: hidden;
}

.airbnb-mosaic-main,
.airbnb-mosaic-tile,
.airbnb-mosaic-single {
    position: relative;
    overflow: hidden;
    cursor: pointer;
    background: #0f172a;
}

.airbnb-mosaic-main img,
.airbnb-mosaic-tile img,
.airbnb-mosaic-single img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease, filter 0.25s ease;
}

.airbnb-mosaic-main:hover img,
.airbnb-mosaic-tile:hover img,
.airbnb-mosaic-single:hover img {
    filter: brightness(0.92);
    transform: scale(1.02);
}

.airbnb-mosaic-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: 8px;
    height: 100%;
}

.airbnb-mosaic-side {
    display: grid;
    gap: 8px;
    height: 100%;
}

.airbnb-mosaic-side.tiles-1 {
    grid-template-columns: 1fr;
}

.airbnb-mosaic-side.tiles-2 {
    grid-template-rows: 1fr 1fr;
}

.airbnb-mosaic-side.tiles-3 {
    grid-template-columns: 1fr 1fr;
    grid-template-rows: 1fr 1fr;
}

.airbnb-mosaic-side.tiles-3 .airbnb-mosaic-tile:first-child {
    grid-column: span 2;
}

.airbnb-mosaic-single {
    height: 380px;
    border-radius: 18px;
}

@media (max-width: 767.98px) {
    .airbnb-mosaic {
        grid-template-columns: 1fr;
        height: 280px;
    }
    .airbnb-mosaic-grid,
    .airbnb-mosaic-side {
        display: none;
    }
}

/* Cartes Prestations horizontales façon Airbnb */
.airbnb-service-card {
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    background: #ffffff;
}

.airbnb-service-card:hover {
    border-color: #94a3b8 !important;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
}

.airbnb-service-card.is-selected {
    border-color: #1d4ed8 !important;
    box-shadow: 0 0 0 2px rgba(29, 78, 216, 0.18);
    background: #f8fbff;
}

.airbnb-service-thumb {
    width: 148px;
    height: 106px;
}

@media (max-width: 575.98px) {
    .airbnb-service-thumb {
        width: 100%;
        height: 150px;
    }
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
