<script setup>
import LocationAutocomplete from '@/Components/LocationAutocomplete.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const showGeoModal = ref(false);
const locating = ref(false);
const geoError = ref('');
const hasLocation = ref(false);

const userLat = ref(null);
const userLon = ref(null);
const locationLabel = ref('');
const typeFilter = ref('');
const radiusKm = ref(50);

const nearbyLoading = ref(false);
const establishments = ref([]);

const sosForm = useForm({
    driver_name: '',
    driver_phone: '',
    vehicle_info: '',
    breakdown_type: 'moteur',
    description: '',
    latitude: null,
    longitude: null,
    location_label: '',
    photo: null,
});

const osmEmbedUrl = computed(() => {
    if (userLat.value === null || userLon.value === null) return '';
    const d = 0.035;
    const bbox = `${(userLon.value - d).toFixed(5)},${(userLat.value - d).toFixed(5)},${(userLon.value + d).toFixed(5)},${(userLat.value + d).toFixed(5)}`;
    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${userLat.value},${userLon.value}`;
});

async function loadNearby() {
    if (userLat.value === null || userLon.value === null) return;
    nearbyLoading.value = true;
    try {
        const params = new URLSearchParams({
            lat: String(userLat.value),
            lon: String(userLon.value),
            radius_km: String(radiusKm.value),
        });
        if (typeFilter.value) params.set('type', typeFilter.value);

        const res = await fetch(`/sos-depannage/proximite?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        establishments.value = data.establishments || [];
    } finally {
        nearbyLoading.value = false;
    }
}

async function setCoordinates(lat, lon, label = '') {
    userLat.value = Number(lat);
    userLon.value = Number(lon);
    sosForm.latitude = userLat.value;
    sosForm.longitude = userLon.value;
    hasLocation.value = true;
    showGeoModal.value = false;

    if (label) {
        locationLabel.value = label;
        sosForm.location_label = label;
    } else {
        try {
            const res = await fetch(`/api/geocode/reverse?lat=${lat}&lon=${lon}`, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            const resolved = data.result?.short_label || data.result?.display_name || `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
            locationLabel.value = resolved;
            sosForm.location_label = resolved;
        } catch {
            locationLabel.value = `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
            sosForm.location_label = locationLabel.value;
        }
    }

    await loadNearby();
}

function requestGps() {
    geoError.value = '';
    if (!navigator.geolocation) {
        geoError.value = 'Votre navigateur ne supporte pas la géolocalisation GPS. Saisissez votre quartier ci-dessous.';
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        async (pos) => {
            locating.value = false;
            await setCoordinates(pos.coords.latitude, pos.coords.longitude);
        },
        () => {
            locating.value = false;
            geoError.value = 'Localisation refusée ou indisponible. Vous pouvez autoriser le GPS dans votre navigateur ou saisir votre quartier ci-dessous.';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function onManualLocationSelect(loc) {
    setCoordinates(loc.latitude, loc.longitude, loc.formatted_location || loc.city);
}

function submitSos() {
    if (!hasLocation.value) {
        showGeoModal.value = true;
        return;
    }
    sosForm.post('/sos-depannage', { forceFormData: true });
}

watch([typeFilter, radiusKm], () => {
    if (hasLocation.value) loadNearby();
});

onMounted(async () => {
    if (navigator.permissions?.query) {
        try {
            const perm = await navigator.permissions.query({ name: 'geolocation' });
            if (perm.state === 'granted') {
                requestGps();
                return;
            }
        } catch {
            // fallback to popup
        }
    }
    showGeoModal.value = true;
});
</script>

<template>
    <Head title="SOS Dépannage &amp; Garages à proximité" />
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-dark bg-dark px-3 py-2 shadow-sm">
            <div class="container d-flex justify-content-between align-items-center">
                <Link href="/" class="navbar-brand fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-geo-alt-fill text-danger"></i>MboaCar SOS Dépannage
                </Link>
                <div class="d-flex gap-2">
                    <Link href="/verifier-vehicule" class="btn btn-outline-light btn-sm">MboaCar Check</Link>
                    <Link href="/connexion" class="btn btn-primary btn-sm">Espace Pro</Link>
                </div>
            </div>
        </nav>

        <!-- POPUP CENTRÉE : Activation de la localisation -->
        <div
            v-if="showGeoModal"
            class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
            style="background: rgba(15, 23, 42, 0.72); z-index: 2000; backdrop-filter: blur(4px);"
        >
            <div class="card border-0 shadow-lg rounded-4" style="max-width: 480px; width: 100%;">
                <div class="card-body p-4 text-center">
                    <div class="mx-auto mb-3 rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                        <i class="bi bi-geo-alt-fill fs-2 text-primary"></i>
                    </div>
                    <h2 class="h5 fw-bold mb-2">Activez votre localisation GPS</h2>
                    <p class="small text-muted mb-3">
                        Pour vous afficher automatiquement les <strong>garages, dépanneurs, centres techniques et boutiques de pièces</strong> les plus proches de vous (distance exacte en km) et guider la dépanneuse jusqu'à votre véhicule, nous avons besoin de votre position.
                    </p>

                    <div v-if="geoError" class="alert alert-warning small py-2 mb-3 text-start">{{ geoError }}</div>

                    <button type="button" class="btn btn-success w-100 py-2 fw-semibold mb-3" :disabled="locating" @click="requestGps">
                        <span v-if="locating" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-crosshair me-2"></i>Activer ma localisation GPS
                    </button>

                    <div class="small text-muted mb-2">— ou recherchez votre quartier sur OpenStreetMap —</div>
                    <LocationAutocomplete
                        v-model="locationLabel"
                        placeholder="Ex: Akwa Douala, Bastos Yaoundé, Plateau Abidjan..."
                        @select="onManualLocationSelect"
                    />
                </div>
            </div>
        </div>

        <div class="container py-4">
            <!-- Bandeau position actuelle -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="small text-muted">Votre position actuelle (OpenStreetMap)</div>
                        <div class="fw-bold">
                            <i class="bi bi-pin-map-fill text-danger me-1"></i>
                            {{ locationLabel || 'Position non définie' }}
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <select v-model="typeFilter" class="form-select form-select-sm no-select2" style="width: auto;">
                            <option value="">Tous établissements</option>
                            <option value="garage">Garages &amp; Dépannage</option>
                            <option value="centre_technique">Centres Techniques</option>
                            <option value="assurance">Assurances</option>
                            <option value="boutique_pieces">Boutiques Pièces</option>
                        </select>
                        <select v-model.number="radiusKm" class="form-select form-select-sm no-select2" style="width: auto;">
                            <option :value="15">Rayon 15 km</option>
                            <option :value="35">Rayon 35 km</option>
                            <option :value="50">Rayon 50 km</option>
                            <option :value="150">Rayon 150 km</option>
                        </select>
                        <button type="button" class="btn btn-outline-primary btn-sm" @click="showGeoModal = true">
                            <i class="bi bi-crosshair me-1"></i>Changer position
                        </button>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Colonne gauche : Carte OSM + Établissements à proximité -->
                <div class="col-lg-7">
                    <div v-if="osmEmbedUrl" class="card border-0 shadow-sm mb-3 overflow-hidden">
                        <iframe
                            :src="osmEmbedUrl"
                            style="width: 100%; height: 280px; border: 0;"
                            loading="lazy"
                            title="Carte OpenStreetMap"
                        ></iframe>
                    </div>

                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h2 class="h6 fw-bold mb-0">
                                <i class="bi bi-building-check text-primary me-2"></i>Établissements à proximité ({{ establishments.length }})
                            </h2>
                            <span v-if="nearbyLoading" class="spinner-border spinner-border-sm text-primary"></span>
                        </div>
                        <div class="list-group list-group-flush">
                            <div v-if="!establishments.length && !nearbyLoading" class="p-4 text-center text-muted small">
                                Aucun établissement géolocalisé trouvé dans ce rayon. Essayez d'élargir le rayon ou lancez une alerte SOS ci-contre.
                            </div>
                            <div v-for="est in establishments" :key="est.id" class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <span class="badge bg-primary-subtle text-primary me-1">{{ est.type_label }}</span>
                                        <span class="badge bg-success-subtle text-success fw-semibold">{{ est.distance_km }} km</span>
                                        <h3 class="h6 fw-bold mt-1 mb-0">{{ est.name }}</h3>
                                        <div class="small text-muted">{{ est.formatted_location }}</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a
                                            v-if="est.phone"
                                            :href="`tel:${est.phone}`"
                                            class="btn btn-outline-primary btn-sm"
                                        >
                                            <i class="bi bi-telephone-fill"></i>
                                        </a>
                                        <a
                                            :href="`https://www.openstreetmap.org/directions?from=${userLat},${userLon}&to=${est.latitude},${est.longitude}`"
                                            target="_blank"
                                            class="btn btn-outline-secondary btn-sm"
                                        >
                                            <i class="bi bi-sign-turn-right-fill me-1"></i>Itinéraire
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : Formulaire Alerte SOS Dépannage Express -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm border-top border-4 border-danger">
                        <div class="card-header bg-white py-3">
                            <h2 class="h6 fw-bold text-danger mb-0">
                                <i class="bi bi-exclamation-octagon-fill me-2"></i>En panne ? Lancer un SOS Dépannage
                            </h2>
                            <p class="text-muted small mb-0">Les garages proches reçoivent votre position GPS et vous envoient leur tarif + délai d'arrivée</p>
                        </div>
                        <div class="card-body">
                            <form @submit.prevent="submitSos">
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Type de panne <span class="text-danger">*</span></label>
                                    <select v-model="sosForm.breakdown_type" class="form-select no-select2" required>
                                        <option value="batterie">Batterie à plat / Démarrage</option>
                                        <option value="crevaison">Crevaison / Roue</option>
                                        <option value="surchauffe">Surchauffe moteur / Radiateur</option>
                                        <option value="moteur">Panne moteur / Calage</option>
                                        <option value="accident">Accident / Carrosserie</option>
                                        <option value="cle">Clé bloquée / Serrure</option>
                                        <option value="remorquage">Remorquage plateau</option>
                                    </select>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold">Votre nom <span class="text-danger">*</span></label>
                                        <input v-model="sosForm.driver_name" type="text" class="form-control" required />
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold">Téléphone / WhatsApp <span class="text-danger">*</span></label>
                                        <input v-model="sosForm.driver_phone" type="tel" class="form-control" placeholder="+2376..." required />
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Véhicule (Marque, modèle, plaque)</label>
                                    <input v-model="sosForm.vehicle_info" type="text" class="form-control" placeholder="Ex: Toyota RAV4 grise — LT 452 AB" />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Précisions (facultatif)</label>
                                    <textarea v-model="sosForm.description" rows="2" class="form-control" placeholder="Ex: Voyant rouge allumé, fumée blanche sous le capot..."></textarea>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-semibold">Photo de la panne (facultatif)</label>
                                    <input type="file" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" @change="(e) => (sosForm.photo = e.target.files?.[0] || null)" />
                                </div>
                                <button type="submit" class="btn btn-danger w-100 py-2 fw-bold" :disabled="sosForm.processing">
                                    <span v-if="sosForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                    <i v-else class="bi bi-broadcast me-2"></i>Envoyer mon SOS Dépannage
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
