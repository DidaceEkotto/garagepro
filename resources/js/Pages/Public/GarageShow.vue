<script setup>
import LandingFooter from '@/Components/LandingFooter.vue';
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
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

// Carrousel galerie établissement
const activePhotoIdx = ref(0);
const galleryPhotos = computed(() => props.garage.photos || []);

function prevPhoto() {
    if (!galleryPhotos.value.length) return;
    activePhotoIdx.value = (activePhotoIdx.value - 1 + galleryPhotos.value.length) % galleryPhotos.value.length;
}
function nextPhoto() {
    if (!galleryPhotos.value.length) return;
    activePhotoIdx.value = (activePhotoIdx.value + 1) % galleryPhotos.value.length;
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

const selectedServices = computed(() =>
    props.services.filter((s) => selectedIds.value.includes(s.id))
);
const totalEstimatedPrice = computed(() =>
    selectedServices.value.reduce((acc, s) => acc + Number(s.price || 0), 0)
);
const totalEstimatedDuration = computed(() =>
    selectedServices.value.reduce((acc, s) => acc + Number(s.duration_minutes || 0), 0)
);

// Date/heure min (dans 1 heure)
const defaultDateTime = (() => {
    const d = new Date(Date.now() + 2 * 3600 * 1000);
    d.setMinutes(0, 0, 0);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:00`;
})();

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
    scheduled_at: defaultDateTime,
    notes: '',
});

const bookingDone = ref(false);

function submitBooking() {
    bookingForm.service_ids = [...selectedIds.value];
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
</script>

<template>
    <Head :title="`${garage.name} — Prendre rendez-vous | MboaCar`" />

    <div class="min-vh-100 d-flex flex-column bg-white">
        <!-- Navbar MboaCar -->
        <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top py-2" style="z-index: 1030;">
            <div class="container">
                <Link href="/" class="navbar-brand d-flex align-items-center gap-2 fw-bold">
                    <img :src="siteLogo" alt="MboaCar" style="height: 36px; width: auto; object-fit: contain;" />
                </Link>
                <div class="d-flex align-items-center gap-2">
                    <Link href="/sos-depannage" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="bi bi-arrow-left me-1"></i>Retour aux garages
                    </Link>
                    <Link href="/connexion" class="btn btn-primary btn-sm rounded-pill px-3">
                        <i class="bi bi-person-circle me-1"></i>Connexion
                    </Link>
                </div>
            </div>
        </nav>

        <main class="container py-4 flex-grow-1">
            <!-- Flash succès / erreur -->
            <div v-if="flashSuccess || bookingDone" class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-start gap-3 mb-4">
                <i class="bi bi-check-circle-fill fs-4 text-success mt-1"></i>
                <div>
                    <div class="fw-bold">Rendez-vous confirmé et transmis !</div>
                    <div class="small">{{ flashSuccess || 'Votre rendez-vous a bien été enregistré. Le garage vient de recevoir une notification instantanée sur WhatsApp et dans son espace MboaCar.' }}</div>
                </div>
            </div>
            <div v-if="flashError" class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
                {{ flashError }}
            </div>

            <!-- En-tête établissement -->
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1">{{ garage.type_label }}</span>
                        <span v-if="garage.sos_enabled" class="badge bg-success-subtle text-success rounded-pill px-3 py-1">
                            <i class="bi bi-lightning-charge-fill me-1"></i>Dépannage SOS actif
                        </span>
                        <span v-if="garage.rating" class="badge bg-light text-dark border rounded-pill px-3 py-1">
                            <i class="bi bi-star-fill text-warning me-1"></i>{{ garage.rating }} ({{ garage.reviews_count }} avis)
                        </span>
                    </div>
                    <h1 class="h3 fw-bold text-dark mb-1">{{ garage.name }}</h1>
                    <div class="text-muted small d-flex flex-wrap align-items-center gap-3">
                        <span v-if="garage.formatted_location"><i class="bi bi-geo-alt-fill text-primary me-1"></i>{{ garage.formatted_location }}</span>
                        <a v-if="garage.phone" :href="`tel:${garage.phone}`" class="text-decoration-none text-dark fw-semibold">
                            <i class="bi bi-telephone-fill text-primary me-1"></i>{{ garage.phone }}
                        </a>
                    </div>
                </div>
            </div>

            <!-- Galerie Photos façon vitrine moderne -->
            <div class="mb-4">
                <div v-if="galleryPhotos.length" class="position-relative rounded-4 overflow-hidden bg-dark" style="height: 360px;">
                    <img
                        :src="galleryPhotos[activePhotoIdx]"
                        :alt="garage.name"
                        class="w-100 h-100"
                        style="object-fit: cover;"
                    />
                    <template v-if="galleryPhotos.length > 1">
                        <button
                            type="button"
                            class="btn btn-light rounded-circle shadow position-absolute top-50 start-0 translate-middle-y ms-3 d-flex align-items-center justify-content-center"
                            style="width: 40px; height: 40px;"
                            @click="prevPhoto"
                            aria-label="Image précédente"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-light rounded-circle shadow position-absolute top-50 end-0 translate-middle-y me-3 d-flex align-items-center justify-content-center"
                            style="width: 40px; height: 40px;"
                            @click="nextPhoto"
                            aria-label="Image suivante"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <div class="position-absolute bottom-0 start-50 translate-middle-x mb-3 d-flex gap-2 bg-dark bg-opacity-50 px-3 py-1 rounded-pill">
                            <button
                                v-for="(_, i) in galleryPhotos"
                                :key="i"
                                type="button"
                                class="border-0 rounded-circle p-0"
                                :style="{
                                    width: i === activePhotoIdx ? '10px' : '7px',
                                    height: i === activePhotoIdx ? '10px' : '7px',
                                    backgroundColor: i === activePhotoIdx ? '#ffffff' : 'rgba(255,255,255,0.55)',
                                }"
                                @click="activePhotoIdx = i"
                            ></button>
                        </div>
                    </template>
                </div>

                <!-- Miniatures si plusieurs photos -->
                <div v-if="galleryPhotos.length > 1" class="d-flex gap-2 mt-2 overflow-auto pb-1">
                    <button
                        v-for="(url, i) in galleryPhotos"
                        :key="url"
                        type="button"
                        class="p-0 border-2 rounded-3 overflow-hidden flex-shrink-0"
                        :class="i === activePhotoIdx ? 'border-primary' : 'border-transparent opacity-75'"
                        style="width: 84px; height: 60px; border-style: solid;"
                        @click="activePhotoIdx = i"
                    >
                        <img :src="url" alt="" class="w-100 h-100" style="object-fit: cover;" />
                    </button>
                </div>

                <div
                    v-if="!galleryPhotos.length"
                    class="rounded-4 d-flex flex-column align-items-center justify-content-center text-white p-5"
                    style="height: 240px; background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #0284c7 100%);"
                >
                    <i class="bi bi-building-gear fs-1 mb-2 opacity-75"></i>
                    <div class="fw-bold fs-5">{{ garage.name }}</div>
                    <div class="small opacity-75">{{ garage.formatted_location || garage.city }}</div>
                </div>
            </div>

            <!-- Contenu principal : Gauche Prestations + Carte + Avis | Droite Formulaire RDV sticky -->
            <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                    <!-- Section Prestations -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h2 class="h5 fw-bold mb-0">Choisissez vos prestations</h2>
                                <div class="text-muted small">Sélectionnez une ou plusieurs prestations puis validez votre rendez-vous à droite.</div>
                            </div>
                            <span v-if="selectedIds.length" class="badge bg-primary rounded-pill px-3 py-2">
                                {{ selectedIds.length }} sélectionnée(s)
                            </span>
                        </div>

                        <div v-if="services.length === 0" class="p-4 bg-light rounded-4 text-muted small">
                            Cet établissement n'a pas encore listé de prestations tarifées. Vous pouvez tout de même décrire votre besoin dans le formulaire de rendez-vous ci-contre.
                        </div>

                        <div v-else class="row g-3">
                            <div v-for="svc in services" :key="svc.id" class="col-md-6">
                                <div
                                    class="card h-100 rounded-4 overflow-hidden transition-all"
                                    :class="selectedIds.includes(svc.id) ? 'border-primary border-2 shadow-sm bg-primary-subtle bg-opacity-10' : 'border shadow-sm'"
                                    style="cursor: pointer;"
                                    @click="toggleService(svc.id)"
                                >
                                    <div v-if="svc.image_url" style="height: 130px; overflow: hidden;">
                                        <img :src="svc.image_url" :alt="svc.name" class="w-100 h-100" style="object-fit: cover;" />
                                    </div>
                                    <div class="card-body d-flex flex-column p-3">
                                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                            <h3 class="h6 fw-bold mb-0">{{ svc.name }}</h3>
                                            <div class="form-check m-0">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="selectedIds.includes(svc.id)"
                                                    @click.stop="toggleService(svc.id)"
                                                />
                                            </div>
                                        </div>
                                        <p v-if="svc.description" class="text-muted small mb-2 flex-grow-1">{{ svc.description }}</p>
                                        <div v-else class="flex-grow-1"></div>
                                        <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
                                            <span class="fw-bold text-primary">
                                                {{ svc.price > 0 ? `${formatMoney(svc.price)} FCFA` : 'Sur devis' }}
                                            </span>
                                            <span class="text-muted small">
                                                <i class="bi bi-clock me-1"></i>~{{ svc.duration_minutes }} min
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Localisation OpenStreetMap -->
                    <div v-if="garage.latitude && garage.longitude" class="card border rounded-4 shadow-sm overflow-hidden mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small"><i class="bi bi-map-fill text-primary me-2"></i>Localisation sur la carte</span>
                            <a
                                :href="`https://www.openstreetmap.org/?mlat=${garage.latitude}&mlon=${garage.longitude}#map=16/${garage.latitude}/${garage.longitude}`"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-outline-primary btn-sm rounded-pill px-3"
                            >
                                Ouvrir OSM <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                        <OsmRouteMap
                            :breakdown-lat="garage.latitude"
                            :breakdown-lon="garage.longitude"
                            :breakdown-label="garage.name"
                            height="280px"
                        />
                    </div>

                    <!-- Avis clients vérifiés -->
                    <div v-if="reviews.length" class="mb-4">
                        <h2 class="h5 fw-bold mb-3">Avis clients vérifiés</h2>
                        <div class="row g-3">
                            <div v-for="rev in reviews" :key="rev.id" class="col-md-6">
                                <div class="p-3 border rounded-4 h-100 bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="text-warning small">
                                            <i v-for="n in 5" :key="n" class="bi" :class="n <= rev.rating ? 'bi-star-fill' : 'bi-star'"></i>
                                        </div>
                                        <span class="text-muted" style="font-size: 0.75rem;">{{ rev.date }}</span>
                                    </div>
                                    <p class="small mb-0 text-dark">{{ rev.comment || 'Intervention satisfaisante.' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite sticky : Formulaire de prise de rendez-vous -->
                <div class="col-lg-5">
                    <div class="card border shadow rounded-4 sticky-top" style="top: 84px; z-index: 10;">
                        <div class="card-body p-4">
                            <h2 class="h5 fw-bold mb-1">
                                <i class="bi bi-calendar2-check-fill text-primary me-2"></i>Prendre rendez-vous
                            </h2>
                            <p class="text-muted small mb-3">
                                Notification WhatsApp immédiate au garage & suivi direct dans leur planning.
                            </p>

                            <!-- Récap prestations sélectionnées -->
                            <div v-if="selectedServices.length" class="p-3 rounded-3 bg-primary-subtle bg-opacity-25 border border-primary-subtle mb-3">
                                <div class="small fw-bold text-primary mb-1">Prestation(s) sélectionnée(s) :</div>
                                <ul class="list-unstyled small mb-2">
                                    <li v-for="s in selectedServices" :key="s.id" class="d-flex justify-content-between">
                                        <span>• {{ s.name }}</span>
                                        <span class="fw-semibold">{{ s.price > 0 ? `${formatMoney(s.price)} F` : 'Devis' }}</span>
                                    </li>
                                </ul>
                                <div class="d-flex justify-content-between small fw-bold border-top border-primary-subtle pt-1">
                                    <span>Estimation ({{ totalEstimatedDuration }} min)</span>
                                    <span class="text-primary">{{ totalEstimatedPrice > 0 ? `${formatMoney(totalEstimatedPrice)} FCFA` : 'Sur devis' }}</span>
                                </div>
                            </div>

                            <form @submit.prevent="submitBooking" class="row g-2">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Autre prestation / motif personnalisé (facultatif)</label>
                                    <input
                                        v-model="bookingForm.custom_service"
                                        type="text"
                                        class="form-control form-control-sm"
                                        placeholder="Ex: Bruit anormal au freinage, révision générale..."
                                    />
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Nom *</label>
                                    <input v-model="bookingForm.customer_name" type="text" class="form-control form-control-sm" required placeholder="Votre nom" />
                                    <div v-if="bookingForm.errors.customer_name" class="text-danger small">{{ bookingForm.errors.customer_name }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Prénom (facultatif)</label>
                                    <input v-model="bookingForm.customer_first_name" type="text" class="form-control form-control-sm" placeholder="Votre prénom" />
                                </div>

                                <div class="col-5">
                                    <label class="form-label small fw-semibold mb-1">Indicatif *</label>
                                    <select v-model="bookingForm.dial_code" class="form-select form-select-sm">
                                        <option v-for="c in countries" :key="c.code" :value="c.dial_code">
                                            {{ c.dial_code }} ({{ c.code }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label small fw-semibold mb-1">Numéro WhatsApp *</label>
                                    <input v-model="bookingForm.customer_phone" type="tel" class="form-control form-control-sm" required placeholder="6XX XXX XXX" />
                                    <div v-if="bookingForm.errors.customer_phone" class="text-danger small">{{ bookingForm.errors.customer_phone }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Marque *</label>
                                    <input v-model="bookingForm.vehicle_brand" type="text" class="form-control form-control-sm" required placeholder="Toyota" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Modèle *</label>
                                    <input v-model="bookingForm.vehicle_model" type="text" class="form-control form-control-sm" required placeholder="Yaris" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Immatriculation *</label>
                                    <input v-model="bookingForm.vehicle_plate" type="text" class="form-control form-control-sm text-uppercase" required placeholder="LT 123 AB" />
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Date & heure souhaitées *</label>
                                    <input v-model="bookingForm.scheduled_at" type="datetime-local" class="form-control form-control-sm" required />
                                    <div v-if="bookingForm.errors.scheduled_at" class="text-danger small">{{ bookingForm.errors.scheduled_at }}</div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">Précisions (facultatif)</label>
                                    <textarea v-model="bookingForm.notes" rows="2" class="form-control form-control-sm" placeholder="Informations complémentaires pour le mécanicien..."></textarea>
                                </div>

                                <div class="col-12 mt-3">
                                    <button
                                        type="submit"
                                        class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm"
                                        :disabled="bookingForm.processing"
                                    >
                                        <span v-if="bookingForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                        <i v-else class="bi bi-whatsapp me-2"></i>Confirmer mon rendez-vous
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <LandingFooter />
    </div>
</template>
