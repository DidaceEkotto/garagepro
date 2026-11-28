<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import LandingFooter from '@/Components/LandingFooter.vue';
import LocationAutocomplete from '@/Components/LocationAutocomplete.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    countries: { type: Array, default: () => [] },
});

const page = usePage();
const siteLogo = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

// Position utilisateur & filtres
const userLat = ref(null);
const userLon = ref(null);
const locationLabel = ref('');
const detectedDialCode = ref('+237');
const radiusKm = ref(50);
const filterType = ref('');
const showGeoModal = ref(false);
const showLocationEditor = ref(false);
const geoLoading = ref(false);
const geoError = ref('');

// Liste paginée (20 par lot) + marqueurs carte
const establishments = ref([]);
const mapPins = ref([]);
const totalCount = ref(0);
const currentPage = ref(1);
const hasMore = ref(false);
const loadingInitial = ref(false);
const loadingMore = ref(false);

// Interactions carte & carrousel
const hoveredId = ref(null);
const selectedPin = ref(null);
const selectedPinPhotoIdx = ref(0);
const cardPhotoIdx = reactive({});
const mobileView = ref('list'); // 'list' | 'map'

// Formulaire SOS dans le tiroir latéral droit statique
const sosForm = useForm({
    breakdown_type: 'batterie',
    driver_name: '',
    driver_first_name: '',
    driver_dial_code: '+237',
    driver_phone: '',
    vehicle_brand: '',
    vehicle_model: '',
    vehicle_plate: '',
    description: '',
    latitude: null,
    longitude: null,
    location_label: '',
    photos: [],
});

const breakdownTypes = [
    { value: 'batterie', label: 'Batterie à plat / Démarrage' },
    { value: 'crevaison', label: 'Crevaison / Roue' },
    { value: 'surchauffe', label: 'Surchauffe moteur / Fuite' },
    { value: 'moteur', label: 'Panne moteur / Calage' },
    { value: 'accident', label: 'Accident / Carrosserie' },
    { value: 'cle', label: 'Clé perdue / Véhicule verrouillé' },
    { value: 'remorquage', label: 'Remorquage / Dépanneuse plateau' },
];

const typeFilters = [
    { value: '', label: 'Tous', icon: 'bi-grid-fill' },
    { value: 'garage', label: 'Garages', icon: 'bi-wrench-adjustable' },
    { value: 'centre_technique', label: 'Centres techniques', icon: 'bi-shield-check' },
    { value: 'assurance', label: 'Assurances', icon: 'bi-file-earmark-check' },
    { value: 'boutique_pieces', label: 'Pièces auto', icon: 'bi-box-seam' },
];

// Helpers carrousel par carte
function getPhotoIdx(id) {
    return cardPhotoIdx[id] || 0;
}
function prevCardPhoto(e, item) {
    e.preventDefault();
    e.stopPropagation();
    const len = item.photos?.length || 0;
    if (len < 2) return;
    cardPhotoIdx[item.id] = (getPhotoIdx(item.id) - 1 + len) % len;
}
function nextCardPhoto(e, item) {
    e.preventDefault();
    e.stopPropagation();
    const len = item.photos?.length || 0;
    if (len < 2) return;
    cardPhotoIdx[item.id] = (getPhotoIdx(item.id) + 1) % len;
}

function prevPinPhoto() {
    const len = selectedPin.value?.photos?.length || 0;
    if (len < 2) return;
    selectedPinPhotoIdx.value = (selectedPinPhotoIdx.value - 1 + len) % len;
}
function nextPinPhoto() {
    const len = selectedPin.value?.photos?.length || 0;
    if (len < 2) return;
    selectedPinPhotoIdx.value = (selectedPinPhotoIdx.value + 1) % len;
}

function formatMoney(n) {
    return new Intl.NumberFormat('fr-FR').format(Number(n || 0));
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ============================================================
// Carte Leaflet interactive sticky
// ============================================================
const mapContainer = ref(null);
let mapInstance = null;
let markersLayer = null;
let userMarker = null;
const markerRefs = new Map();

function initMap() {
    if (!mapContainer.value || mapInstance) return;
    const centerLat = userLat.value ?? 3.848;
    const centerLon = userLon.value ?? 11.502;

    mapInstance = L.map(mapContainer.value, {
        center: [centerLat, centerLon],
        zoom: 12,
        zoomControl: false,
        scrollWheelZoom: true,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19,
    }).addTo(mapInstance);

    L.control.zoom({ position: 'topright' }).addTo(mapInstance);
    markersLayer = L.layerGroup().addTo(mapInstance);

    mapInstance.on('click', () => {
        selectedPin.value = null;
    });
}

function buildPillHtml(item, isActive) {
    const label = item.min_price
        ? `${formatMoney(item.min_price)} F`
        : `${item.distance_km} km`;
    const cls = isActive ? 'sos-map-pill sos-map-pill--active' : 'sos-map-pill';
    return `<div class="${cls}"><i class="bi bi-wrench-adjustable-circle-fill me-1"></i><span>${escapeHtml(label)}</span></div>`;
}

function renderMapMarkers() {
    if (!mapInstance || !markersLayer) return;
    markersLayer.clearLayers();
    markerRefs.clear();

    const bounds = [];

    if (userLat.value !== null && userLon.value !== null) {
        bounds.push([userLat.value, userLon.value]);
        const userIcon = L.divIcon({
            className: 'sos-user-dot-wrap',
            html: '<div class="sos-user-dot"></div>',
            iconSize: [18, 18],
            iconAnchor: [9, 9],
        });
        userMarker = L.marker([userLat.value, userLon.value], { icon: userIcon, zIndexOffset: 500 }).addTo(markersLayer);
    }

    for (const item of mapPins.value) {
        if (!item.latitude || !item.longitude) continue;
        bounds.push([item.latitude, item.longitude]);
        const isActive = hoveredId.value === item.id || selectedPin.value?.id === item.id;
        const icon = L.divIcon({
            className: 'sos-pill-marker-wrap',
            html: buildPillHtml(item, isActive),
            iconSize: [0, 0],
            iconAnchor: [38, 16],
        });

        const marker = L.marker([item.latitude, item.longitude], {
            icon,
            zIndexOffset: isActive ? 1000 : 100,
        }).addTo(markersLayer);

        marker.on('click', (ev) => {
            L.DomEvent.stopPropagation(ev);
            selectedPin.value = item;
            selectedPinPhotoIdx.value = 0;
            updatePinHighlight();
        });

        markerRefs.set(item.id, { marker, item });
    }

    if (bounds.length > 1) {
        mapInstance.fitBounds(bounds, { padding: [44, 44], maxZoom: 14 });
    } else if (bounds.length === 1) {
        mapInstance.setView(bounds[0], 13);
    }
}

function updatePinHighlight() {
    for (const [id, entry] of markerRefs.entries()) {
        const isActive = hoveredId.value === id || selectedPin.value?.id === id;
        entry.marker.setZIndexOffset(isActive ? 1000 : 100);
        entry.marker.setIcon(
            L.divIcon({
                className: 'sos-pill-marker-wrap',
                html: buildPillHtml(entry.item, isActive),
                iconSize: [0, 0],
                iconAnchor: [38, 16],
            })
        );
    }
}

watch([hoveredId, () => selectedPin.value?.id], () => {
    updatePinHighlight();
});

watch(mobileView, (val) => {
    if (val === 'map') {
        nextTick(() => {
            initMap();
            mapInstance?.invalidateSize();
            renderMapMarkers();
        });
    }
});

// ============================================================
// Chargement des établissements (20 par lot + Skeleton)
// ============================================================
async function fetchNearby(pageNum = 1) {
    if (userLat.value === null || userLon.value === null) return;

    if (pageNum === 1) {
        loadingInitial.value = true;
        selectedPin.value = null;
    } else {
        loadingMore.value = true;
    }

    try {
        const params = new URLSearchParams({
            lat: String(userLat.value),
            lon: String(userLon.value),
            radius_km: String(radiusKm.value),
            page: String(pageNum),
            per_page: '20',
        });
        if (filterType.value) params.set('type', filterType.value);

        const resp = await fetch(`/sos-depannage/proximite?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await resp.json();

        const batch = data.establishments || [];
        if (pageNum === 1) {
            establishments.value = batch;
            mapPins.value = data.map_pins || batch;
        } else {
            establishments.value = [...establishments.value, ...batch];
        }
        totalCount.value = data.total ?? establishments.value.length;
        currentPage.value = data.page ?? pageNum;
        hasMore.value = Boolean(data.has_more);

        await nextTick();
        initMap();
        if (pageNum === 1) {
            renderMapMarkers();
        }
    } catch {
        // non-blocking
    } finally {
        loadingInitial.value = false;
        loadingMore.value = false;
    }
}

function loadMore() {
    if (loadingMore.value || !hasMore.value) return;
    fetchNearby(currentPage.value + 1);
}

// ============================================================
// Géolocalisation GPS + Reverse Geocode (auto indicatif tel)
// ============================================================
async function setPosition(lat, lon, label = '') {
    userLat.value = Number(lat);
    userLon.value = Number(lon);
    sosForm.latitude = userLat.value;
    sosForm.longitude = userLon.value;
    showGeoModal.value = false;
    showLocationEditor.value = false;

    if (label) {
        locationLabel.value = label;
        sosForm.location_label = label;
    }

    // Reverse geocode pour récupérer la ville + indicatif téléphonique du pays
    try {
        const r = await fetch(`/api/geocode/reverse?lat=${lat}&lon=${lon}`, {
            headers: { Accept: 'application/json' },
        });
        const d = await r.json();
        if (d?.result) {
            if (!label) {
                locationLabel.value = d.result.short_label || d.result.city || `${Number(lat).toFixed(4)}, ${Number(lon).toFixed(4)}`;
                sosForm.location_label = locationLabel.value;
            }
            if (d.result.dial_code) {
                detectedDialCode.value = d.result.dial_code;
                sosForm.driver_dial_code = d.result.dial_code;
            }
        }
    } catch {
        if (!label) {
            locationLabel.value = `${Number(lat).toFixed(4)}, ${Number(lon).toFixed(4)}`;
            sosForm.location_label = locationLabel.value;
        }
    }

    await fetchNearby(1);
}

function requestGps() {
    geoError.value = '';
    if (!navigator.geolocation) {
        geoError.value = 'Votre navigateur ne supporte pas la géolocalisation. Saisissez votre quartier ou ville ci-dessous.';
        showGeoModal.value = true;
        return;
    }

    geoLoading.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            geoLoading.value = false;
            setPosition(pos.coords.latitude, pos.coords.longitude);
        },
        () => {
            geoLoading.value = false;
            geoError.value = 'Localisation non activée. Activez le GPS ou recherchez votre ville ci-dessous.';
            showGeoModal.value = true;
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function onManualLocationSelect(payload) {
    if (payload?.latitude && payload?.longitude) {
        setPosition(payload.latitude, payload.longitude, payload.formatted_location || payload.city || '');
    }
}

// Ouvrir le tiroir latéral droit SOS (statique)
function openSosDrawer() {
    if (userLat.value === null || userLon.value === null) {
        showGeoModal.value = true;
        return;
    }
    sosForm.latitude = userLat.value;
    sosForm.longitude = userLon.value;
    sosForm.location_label = locationLabel.value;
    sosForm.driver_dial_code = sosForm.driver_dial_code || detectedDialCode.value;
    const el = document.getElementById('sosRightDrawer');
    if (el && window.bootstrap?.Offcanvas) {
        window.bootstrap.Offcanvas.getOrCreateInstance(el, { backdrop: 'static', keyboard: false }).show();
    }
}

function submitSos() {
    sosForm.latitude = userLat.value;
    sosForm.longitude = userLon.value;
    sosForm.location_label = locationLabel.value;
    sosForm.post('/sos-depannage', {
        forceFormData: true,
    });
}

onMounted(() => {
    nextTick(() => initMap());

    if (navigator.permissions?.query) {
        navigator.permissions
            .query({ name: 'geolocation' })
            .then((status) => {
                if (status.state === 'granted') {
                    requestGps();
                } else {
                    showGeoModal.value = true;
                }
            })
            .catch(() => {
                showGeoModal.value = true;
            });
    } else {
        showGeoModal.value = true;
    }
});

onBeforeUnmount(() => {
    if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
    }
});
</script>

<template>
    <Head title="SOS Dépannage & Garages à proximité | MboaCar" />

    <div class="min-vh-100 d-flex flex-column bg-white">
        <!-- Navbar principale épurée (Logo MboaCar, zéro rouge) -->
        <header class="sticky-top bg-white border-bottom" style="z-index: 1030;">
            <div class="container-fluid px-3 px-lg-4 py-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <Link href="/" class="d-flex align-items-center gap-2 text-decoration-none">
                        <img :src="siteLogo" alt="MboaCar" style="height: 36px; width: auto; object-fit: contain;" />
                    </Link>

                    <!-- Bandeau position actuelle façon barre pilule Airbnb -->
                    <div class="sos-position-pill d-flex align-items-center gap-2 px-3 py-1 rounded-pill border shadow-sm bg-white">
                        <button
                            type="button"
                            class="btn btn-link text-decoration-none text-dark p-0 d-flex align-items-center gap-2 small fw-semibold"
                            @click="showLocationEditor = !showLocationEditor"
                        >
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width: 26px; height: 26px;">
                                <i class="bi bi-geo-alt-fill" style="font-size: 0.78rem;"></i>
                            </span>
                            <span class="text-truncate" style="max-width: 210px;">
                                {{ locationLabel || 'Choisir ma position' }}
                            </span>
                        </button>
                        <span class="text-muted opacity-25">|</span>
                        <select
                            v-model.number="radiusKm"
                            class="form-select form-select-sm border-0 bg-transparent fw-semibold text-secondary py-0 pe-4"
                            style="width: auto; box-shadow: none;"
                            @change="fetchNearby(1)"
                            aria-label="Rayon de recherche"
                        >
                            <option :value="15">15 km</option>
                            <option :value="30">30 km</option>
                            <option :value="50">50 km</option>
                            <option :value="100">100 km</option>
                            <option :value="200">200 km</option>
                        </select>
                        <button
                            type="button"
                            class="sos-gps-circle-btn btn btn-light border flex-shrink-0"
                            title="Actualiser ma position GPS"
                            :disabled="geoLoading"
                            @click="requestGps"
                        >
                            <span v-if="geoLoading" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="bi bi-crosshair text-primary" style="font-size: 0.85rem; line-height: 1;"></i>
                        </button>
                    </div>

                    <!-- Actions droite : Lancer un SOS + Connexion -->
                    <div class="d-flex align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm rounded-pill px-3 py-2 fw-bold shadow-sm d-flex align-items-center gap-2"
                            @click="openSosDrawer"
                        >
                            <i class="bi bi-broadcast-pin"></i>
                            <span>Lancer un SOS Dépannage</span>
                        </button>
                        <Link href="/connexion" class="btn btn-outline-dark btn-sm rounded-pill px-3 py-2 fw-semibold d-none d-sm-inline-flex align-items-center gap-1">
                            <i class="bi bi-person-circle"></i>Connexion
                        </Link>
                    </div>
                </div>

                <!-- Panneau déroulant de modification manuelle de la position -->
                <div v-if="showLocationEditor" class="mt-2 p-3 bg-light rounded-4 border shadow-sm" style="max-width: 560px; margin-inline: auto;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-dark"><i class="bi bi-search text-primary me-1"></i>Changer de localisation (OpenStreetMap)</span>
                        <button type="button" class="btn-close btn-sm" @click="showLocationEditor = false"></button>
                    </div>
                    <LocationAutocomplete
                        placeholder="Ex: Akwa, Douala ou Bastos, Yaoundé..."
                        @select="onManualLocationSelect"
                    />
                </div>
            </div>

            <!-- Barre de filtres pilules façon Airbnb -->
            <div class="border-top bg-white">
                <div class="container-fluid px-3 px-lg-4 py-2 d-flex align-items-center justify-content-between gap-2 overflow-auto">
                    <div class="d-flex align-items-center gap-2 flex-nowrap">
                        <button
                            v-for="f in typeFilters"
                            :key="f.value"
                            type="button"
                            class="btn btn-sm rounded-pill px-3 py-1 fw-semibold flex-shrink-0 d-inline-flex align-items-center gap-1"
                            :class="filterType === f.value ? 'btn-dark text-white' : 'btn-outline-secondary bg-white text-dark border-secondary-subtle'"
                            @click="filterType = f.value; fetchNearby(1)"
                        >
                            <i class="bi" :class="f.icon"></i>
                            <span>{{ f.label }}</span>
                        </button>
                    </div>

                    <div class="small text-muted fw-semibold flex-shrink-0 d-none d-md-block">
                        <template v-if="userLat !== null">
                            {{ totalCount }} établissement(s) dans un rayon de {{ radiusKm }} km
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <!-- Contenu principal Split View Airbnb : Gauche Grille 2 colonnes | Droite Carte Sticky -->
        <main class="container-fluid px-3 px-lg-4 py-4 flex-grow-1">
            <div class="row g-4 align-items-start">
                <!-- COLONNE GAUCHE : Liste des établissements (20 par lot + Skeleton) -->
                <div class="col-12 col-lg-7 col-xl-7" :class="{ 'd-none d-lg-block': mobileView === 'map' }">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h1 class="h6 fw-bold text-dark mb-0">
                                <template v-if="loadingInitial">Recherche des établissements à proximité...</template>
                                <template v-else-if="totalCount > 0">
                                    {{ totalCount }} établissement(s) à proximité
                                    <span v-if="locationLabel" class="text-muted fw-normal">• {{ locationLabel }}</span>
                                </template>
                                <template v-else>Garages & Centres Techniques à proximité</template>
                            </h1>
                        </div>
                    </div>

                    <!-- Skeleton initial (système mboacar-skeleton-box) -->
                    <div v-if="loadingInitial" class="row row-cols-1 row-cols-sm-2 g-4">
                        <div v-for="n in 6" :key="`skel-${n}`" class="col">
                            <div class="mboacar-skeleton-box rounded-4 mb-2" style="aspect-ratio: 4/3; width: 100%;"></div>
                            <div class="d-flex justify-content-between mb-1">
                                <div class="mboacar-skeleton-box" style="height: 16px; width: 65%;"></div>
                                <div class="mboacar-skeleton-box" style="height: 16px; width: 22%;"></div>
                            </div>
                            <div class="mboacar-skeleton-box mb-1" style="height: 13px; width: 50%;"></div>
                            <div class="mboacar-skeleton-box" style="height: 15px; width: 40%;"></div>
                        </div>
                    </div>

                    <!-- État vide -->
                    <div v-else-if="establishments.length === 0" class="text-center py-5 px-3 bg-light rounded-4 border">
                        <i class="bi bi-geo-alt fs-1 text-primary opacity-75 d-block mb-2"></i>
                        <h2 class="h6 fw-bold mb-1">Aucun établissement trouvé dans cette zone</h2>
                        <p class="small text-muted mb-3">
                            Élargissez le rayon de recherche ou choisissez une autre ville, ou lancez directement une alerte SOS Dépannage.
                        </p>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" @click="radiusKm = 200; fetchNearby(1)">
                                Élargir à 200 km
                            </button>
                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" @click="openSosDrawer">
                                <i class="bi bi-broadcast-pin me-1"></i>Lancer un SOS Dépannage
                            </button>
                        </div>
                    </div>

                    <!-- Grille 2 colonnes façon Airbnb -->
                    <div v-else class="row row-cols-1 row-cols-sm-2 g-4">
                        <div
                            v-for="item in establishments"
                            :key="item.id"
                            class="col"
                            @mouseenter="hoveredId = item.id"
                            @mouseleave="hoveredId = null"
                        >
                            <Link :href="item.public_url" class="sos-card text-decoration-none text-dark d-block h-100">
                                <!-- Conteneur photo avec carrousel + flèches arrondies -->
                                <div class="sos-card-media position-relative rounded-4 overflow-hidden bg-light mb-2">
                                    <img
                                        v-if="item.photos && item.photos.length"
                                        :src="item.photos[getPhotoIdx(item.id)]"
                                        :alt="item.name"
                                        class="w-100 h-100 sos-card-img"
                                        loading="lazy"
                                    />
                                    <div
                                        v-else
                                        class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-white p-3"
                                        style="background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 65%, #0ea5e9 100%);"
                                    >
                                        <i class="bi bi-building-gear fs-1 opacity-75 mb-1"></i>
                                        <span class="small fw-semibold text-center opacity-90 text-truncate w-100 px-2">{{ item.name }}</span>
                                    </div>

                                    <!-- Badge type / SOS en haut à gauche -->
                                    <span class="badge bg-white text-dark shadow-sm rounded-pill position-absolute top-0 start-0 m-2 px-2 py-1 fw-semibold" style="font-size: 0.72rem;">
                                        <i class="bi bi-wrench-adjustable text-primary me-1"></i>{{ item.type_label }}
                                    </span>

                                    <span
                                        v-if="item.sos_enabled"
                                        class="badge bg-success text-white shadow-sm rounded-pill position-absolute top-0 end-0 m-2 px-2 py-1"
                                        style="font-size: 0.7rem;"
                                    >
                                        SOS 24/7
                                    </span>

                                    <!-- Flèches arrondies Précédent / Suivant (au survol sur desktop, toujours visibles sur mobile/responsive) -->
                                    <template v-if="item.photos && item.photos.length > 1">
                                        <button
                                            type="button"
                                            class="sos-carousel-btn sos-carousel-btn--prev"
                                            aria-label="Photo précédente"
                                            @click="prevCardPhoto($event, item)"
                                        >
                                            <i class="bi bi-chevron-left"></i>
                                        </button>
                                        <button
                                            type="button"
                                            class="sos-carousel-btn sos-carousel-btn--next"
                                            aria-label="Photo suivante"
                                            @click="nextCardPhoto($event, item)"
                                        >
                                            <i class="bi bi-chevron-right"></i>
                                        </button>

                                        <!-- Points indicateurs -->
                                        <div class="sos-carousel-dots">
                                            <span
                                                v-for="(_, idx) in item.photos.slice(0, 5)"
                                                :key="idx"
                                                class="sos-carousel-dot"
                                                :class="{ 'is-active': idx === (getPhotoIdx(item.id) % 5) }"
                                            ></span>
                                        </div>
                                    </template>
                                </div>

                                <!-- Infos de la carte -->
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <h2 class="h6 fw-bold mb-0 text-truncate">{{ item.name }}</h2>
                                    <span class="small fw-semibold flex-shrink-0 text-dark">
                                        <template v-if="item.rating">
                                            <i class="bi bi-star-fill text-dark me-1" style="font-size: 0.75rem;"></i>{{ item.rating }} ({{ item.reviews_count }})
                                        </template>
                                        <template v-else>
                                            <span class="text-primary fw-bold">{{ item.distance_km }} km</span>
                                        </template>
                                    </span>
                                </div>

                                <div class="text-muted small text-truncate">
                                    {{ item.formatted_location || item.city }}
                                    <span v-if="item.rating"> • {{ item.distance_km }} km</span>
                                </div>

                                <div class="mt-1 d-flex flex-wrap align-items-center gap-2">
                                    <span v-if="item.min_price" class="small fw-bold text-dark text-decoration-underline">
                                        À partir de {{ formatMoney(item.min_price) }} FCFA
                                    </span>
                                    <span v-else class="small fw-semibold text-primary">
                                        Voir les prestations & prendre RDV
                                    </span>
                                </div>

                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    <span v-if="item.services_count > 0" class="badge bg-primary-subtle text-primary rounded-pill fw-normal" style="font-size: 0.7rem;">
                                        {{ item.services_count }} prestation(s)
                                    </span>
                                    <span class="badge bg-light text-secondary border rounded-pill fw-normal" style="font-size: 0.7rem;">
                                        RDV instantané WhatsApp
                                    </span>
                                </div>
                            </Link>
                        </div>

                        <!-- Skeletons additionnels pendant "Afficher plus" -->
                        <template v-if="loadingMore">
                            <div v-for="n in 4" :key="`more-skel-${n}`" class="col">
                                <div class="mboacar-skeleton-box rounded-4 mb-2" style="aspect-ratio: 4/3; width: 100%;"></div>
                                <div class="mboacar-skeleton-box mb-1" style="height: 16px; width: 65%;"></div>
                                <div class="mboacar-skeleton-box" style="height: 13px; width: 45%;"></div>
                            </div>
                        </template>
                    </div>

                    <!-- Bouton Afficher plus (à la place de la pagination numérotée) -->
                    <div v-if="hasMore && !loadingInitial" class="text-center mt-4 pt-2">
                        <button
                            type="button"
                            class="btn btn-dark rounded-pill px-4 py-2 fw-semibold shadow-sm"
                            :disabled="loadingMore"
                            @click="loadMore"
                        >
                            <span v-if="loadingMore" class="spinner-border spinner-border-sm me-2"></span>
                            Afficher plus d'établissements
                        </button>
                    </div>
                </div>

                <!-- COLONNE DROITE : Carte OpenStreetMap STICKY (reste fixe jusqu'à la fin de la liste, puis défile normalement) -->
                <div class="col-12 col-lg-5 col-xl-5" :class="{ 'd-none d-lg-block': mobileView === 'list' }">
                    <div class="sos-sticky-map-shell">
                        <div ref="mapContainer" class="w-100 h-100"></div>

                        <!-- Modal Card flottante façon Airbnb au clic sur un garage de la carte -->
                        <div v-if="selectedPin" class="sos-map-popup-card shadow-lg">
                            <div class="position-relative" style="height: 170px; background: #0f172a;">
                                <img
                                    v-if="selectedPin.photos && selectedPin.photos.length"
                                    :src="selectedPin.photos[selectedPinPhotoIdx]"
                                    :alt="selectedPin.name"
                                    class="w-100 h-100"
                                    style="object-fit: cover;"
                                />
                                <div
                                    v-else
                                    class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-white"
                                    style="background: linear-gradient(135deg, #0f172a, #1d4ed8);"
                                >
                                    <i class="bi bi-building-gear fs-2 opacity-75"></i>
                                </div>

                                <!-- Bouton Fermer X -->
                                <button
                                    type="button"
                                    class="btn btn-light rounded-circle shadow-sm position-absolute top-0 end-0 m-2 p-0 d-flex align-items-center justify-content-center"
                                    style="width: 30px; height: 30px;"
                                    @click.stop="selectedPin = null"
                                    aria-label="Fermer l'aperçu"
                                >
                                    <i class="bi bi-x-lg" style="font-size: 0.78rem;"></i>
                                </button>

                                <!-- Flèches carrousel dans le modal carte -->
                                <template v-if="selectedPin.photos && selectedPin.photos.length > 1">
                                    <button
                                        type="button"
                                        class="sos-carousel-btn sos-carousel-btn--prev sos-carousel-btn--always"
                                        @click.stop="prevPinPhoto"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="sos-carousel-btn sos-carousel-btn--next sos-carousel-btn--always"
                                        @click.stop="nextPinPhoto"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                    <div class="sos-carousel-dots">
                                        <span
                                            v-for="(_, i) in selectedPin.photos.slice(0, 5)"
                                            :key="i"
                                            class="sos-carousel-dot"
                                            :class="{ 'is-active': i === (selectedPinPhotoIdx % 5) }"
                                        ></span>
                                    </div>
                                </template>
                            </div>

                            <Link :href="selectedPin.public_url" class="d-block p-3 text-decoration-none text-dark bg-white">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div class="fw-bold text-truncate">{{ selectedPin.name }}</div>
                                    <span class="small fw-semibold flex-shrink-0">
                                        <template v-if="selectedPin.rating">
                                            <i class="bi bi-star-fill text-dark me-1" style="font-size: 0.72rem;"></i>{{ selectedPin.rating }} ({{ selectedPin.reviews_count }})
                                        </template>
                                        <template v-else>{{ selectedPin.distance_km }} km</template>
                                    </span>
                                </div>
                                <div class="small text-muted text-truncate mb-2">
                                    {{ selectedPin.type_label }} • {{ selectedPin.formatted_location || selectedPin.city }}
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-primary small">
                                        {{ selectedPin.min_price ? `Dès ${formatMoney(selectedPin.min_price)} FCFA` : 'Prendre rendez-vous' }}
                                    </span>
                                    <span class="btn btn-primary btn-sm rounded-pill px-3 py-1 fw-semibold" style="font-size: 0.78rem;">
                                        Ouvrir la page <i class="bi bi-arrow-right ms-1"></i>
                                    </span>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Bouton flottant mobile : Basculer Liste / Carte -->
        <div class="d-lg-none position-fixed bottom-0 start-50 translate-middle-x mb-3" style="z-index: 1025;">
            <button
                type="button"
                class="btn btn-dark rounded-pill px-4 py-2 shadow-lg fw-semibold d-flex align-items-center gap-2"
                @click="mobileView = mobileView === 'list' ? 'map' : 'list'"
            >
                <i class="bi" :class="mobileView === 'list' ? 'bi-map-fill' : 'bi-list-ul'"></i>
                <span>{{ mobileView === 'list' ? 'Afficher la carte' : 'Afficher la liste' }}</span>
            </button>
        </div>

        <LandingFooter />
    </div>

    <!-- ================================================================ -->
    <!-- POPUP CENTRÉE : Activation géolocalisation OpenStreetMap          -->
    <!-- ================================================================ -->
    <div
        v-if="showGeoModal"
        class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center p-3"
        style="background: rgba(15, 23, 42, 0.65); z-index: 1060; backdrop-filter: blur(4px);"
    >
        <div class="bg-white rounded-4 shadow-lg p-4 w-100" style="max-width: 470px;">
            <div class="text-center mb-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary mb-2" style="width: 58px; height: 58px;">
                    <i class="bi bi-geo-alt-fill fs-3"></i>
                </div>
                <h2 class="h5 fw-bold mb-1">Activez votre localisation</h2>
                <p class="small text-muted mb-0">
                    Pour afficher automatiquement les garages, centres techniques et dépanneurs les plus proches sur la carte (et pré-remplir votre indicatif téléphonique en cas de SOS), autorisez l'accès à votre position.
                </p>
            </div>

            <div v-if="geoError" class="alert alert-warning small py-2 mb-3">{{ geoError }}</div>

            <button
                type="button"
                class="btn btn-primary w-100 rounded-pill py-2 fw-bold mb-3"
                :disabled="geoLoading"
                @click="requestGps"
            >
                <span v-if="geoLoading" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="bi bi-crosshair me-2"></i>Activer ma localisation GPS
            </button>

            <div class="text-center small text-muted mb-2">— ou recherchez votre ville / quartier —</div>
            <LocationAutocomplete
                placeholder="Ex: Douala, Yaoundé, Abidjan, Dakar..."
                @select="onManualLocationSelect"
            />
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- TIROIR LATÉRAL DROIT STATIQUE : Formulaire SOS Dépannage          -->
    <!-- ================================================================ -->
    <div
        class="offcanvas offcanvas-end shadow-lg"
        tabindex="-1"
        id="sosRightDrawer"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        aria-labelledby="sosRightDrawerLabel"
        style="width: 480px; max-width: 100vw;"
    >
        <div class="offcanvas-header border-bottom bg-light">
            <div>
                <h5 class="offcanvas-title fw-bold mb-0" id="sosRightDrawerLabel">
                    <i class="bi bi-broadcast-pin text-primary me-2"></i>Demande SOS Dépannage
                </h5>
                <div class="small text-muted">
                    <i class="bi bi-geo-alt-fill text-primary me-1"></i>{{ locationLabel || 'Position GPS prête' }}
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer"></button>
        </div>

        <div class="offcanvas-body">
            <form @submit.prevent="submitSos" class="row g-3">
                <div class="col-12">
                    <label class="form-label small fw-semibold mb-1">Type de panne *</label>
                    <select v-model="sosForm.breakdown_type" class="form-select" required>
                        <option v-for="b in breakdownTypes" :key="b.value" :value="b.value">{{ b.label }}</option>
                    </select>
                </div>

                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Nom *</label>
                    <input v-model="sosForm.driver_name" type="text" class="form-control" required placeholder="Votre nom" />
                    <div v-if="sosForm.errors.driver_name" class="text-danger small mt-1">{{ sosForm.errors.driver_name }}</div>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold mb-1">Prénom (facultatif)</label>
                    <input v-model="sosForm.driver_first_name" type="text" class="form-control" placeholder="Votre prénom" />
                </div>

                <div class="col-5">
                    <label class="form-label small fw-semibold mb-1">Indicatif *</label>
                    <select v-model="sosForm.driver_dial_code" class="form-select" required>
                        <option v-for="c in countries" :key="c.code" :value="c.dial_code">
                            {{ c.dial_code }} ({{ c.code }})
                        </option>
                    </select>
                </div>
                <div class="col-7">
                    <label class="form-label small fw-semibold mb-1">Numéro WhatsApp *</label>
                    <input v-model="sosForm.driver_phone" type="tel" class="form-control" required placeholder="6XX XXX XXX" />
                    <div v-if="sosForm.errors.driver_phone" class="text-danger small mt-1">{{ sosForm.errors.driver_phone }}</div>
                </div>

                <div class="col-4">
                    <label class="form-label small fw-semibold mb-1">Marque *</label>
                    <input v-model="sosForm.vehicle_brand" type="text" class="form-control" required placeholder="Toyota" />
                </div>
                <div class="col-4">
                    <label class="form-label small fw-semibold mb-1">Modèle *</label>
                    <input v-model="sosForm.vehicle_model" type="text" class="form-control" required placeholder="Corolla" />
                </div>
                <div class="col-4">
                    <label class="form-label small fw-semibold mb-1">Immatriculation *</label>
                    <input v-model="sosForm.vehicle_plate" type="text" class="form-control text-uppercase" required placeholder="CE 765 TY" />
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold mb-1">Précisions (facultatif)</label>
                    <textarea
                        v-model="sosForm.description"
                        rows="2"
                        class="form-control"
                        placeholder="Décrivez brièvement la situation ou un point de repère..."
                    ></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label small fw-semibold mb-1">Photos de la panne (facultatif — plusieurs images)</label>
                    <FileDropzone
                        v-model="sosForm.photos"
                        :multiple="true"
                        accept="image/jpeg,image/png,image/webp"
                        label="Glissez-déposez une ou plusieurs photos de la panne"
                    />
                    <div v-if="sosForm.errors.photos" class="text-danger small mt-1">{{ sosForm.errors.photos }}</div>
                </div>

                <div class="col-12 pt-2">
                    <button
                        type="submit"
                        class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm"
                        :disabled="sosForm.processing"
                    >
                        <span v-if="sosForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                        <i v-else class="bi bi-send-fill me-2"></i>Envoyer mon alerte SOS aux garages proches
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<style scoped>
/* Bouton GPS parfaitement rond dans le bandeau pilule */
.sos-gps-circle-btn {
    width: 30px !important;
    height: 30px !important;
    min-width: 30px !important;
    min-height: 30px !important;
    padding: 0 !important;
    border-radius: 50% !important;
    aspect-ratio: 1 / 1;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    flex: 0 0 30px;
}

/* Carte sticky droite : reste fixe tant que la grille gauche scrolle, puis défile normalement */
.sos-sticky-map-shell {
    position: sticky;
    top: 122px;
    height: calc(100vh - 142px);
    min-height: 480px;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    background: #eef2f6;
}

@media (max-width: 991.98px) {
    .sos-sticky-map-shell {
        position: relative;
        top: 0;
        height: 72vh;
        min-height: 420px;
    }
}

/* Cartes établissements façon Airbnb */
.sos-card-media {
    aspect-ratio: 4 / 3;
}

.sos-card-img {
    object-fit: cover;
    transition: transform 0.35s ease;
}

.sos-card:hover .sos-card-img {
    transform: scale(1.03);
}

/* Flèches arrondies Précédent / Suivant : au survol sur desktop, toujours visibles sur mobile/responsive */
.sos-carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255, 255, 255, 0.94);
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.22);
    opacity: 0;
    transition: opacity 0.2s ease, transform 0.2s ease;
    z-index: 4;
    cursor: pointer;
}

.sos-carousel-btn--prev {
    left: 10px;
}

.sos-carousel-btn--next {
    right: 10px;
}

.sos-card-media:hover .sos-carousel-btn,
.sos-carousel-btn--always {
    opacity: 1;
}

.sos-carousel-btn:hover {
    background: #ffffff;
    transform: translateY(-50%) scale(1.08);
}

@media (max-width: 991.98px) {
    .sos-carousel-btn {
        opacity: 1 !important;
    }
}

/* Indicateurs points du carrousel */
.sos-carousel-dots {
    position: absolute;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 5px;
    z-index: 3;
    background: rgba(15, 23, 42, 0.35);
    padding: 4px 8px;
    border-radius: 999px;
}

.sos-carousel-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.55);
    transition: all 0.2s ease;
}

.sos-carousel-dot.is-active {
    background: #ffffff;
    transform: scale(1.25);
}

/* Modal Card flottante dans la carte (façon Airbnb) */
.sos-map-popup-card {
    position: absolute;
    bottom: 18px;
    left: 50%;
    transform: translateX(-50%);
    width: 310px;
    max-width: calc(100% - 28px);
    border-radius: 16px;
    overflow: hidden;
    z-index: 650;
    border: 1px solid rgba(15, 23, 42, 0.08);
    animation: sosPopupIn 0.2s ease;
}

@keyframes sosPopupIn {
    from {
        opacity: 0;
        transform: translate(-50%, 10px);
    }
    to {
        opacity: 1;
        transform: translate(-50%, 0);
    }
}

/* Marqueurs pilules Leaflet */
:deep(.sos-pill-marker-wrap) {
    background: transparent !important;
    border: none !important;
}

:deep(.sos-map-pill) {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    background: #ffffff;
    color: #0f172a;
    font-weight: 700;
    font-size: 0.78rem;
    padding: 5px 11px;
    border-radius: 999px;
    border: 1px solid rgba(15, 23, 42, 0.14);
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.22);
    transition: transform 0.15s ease, background-color 0.15s ease, color 0.15s ease;
    cursor: pointer;
}

:deep(.sos-map-pill i) {
    color: #1d4ed8;
}

:deep(.sos-map-pill:hover),
:deep(.sos-map-pill--active) {
    background: #0f172a;
    color: #ffffff;
    transform: scale(1.1);
}

:deep(.sos-map-pill:hover i),
:deep(.sos-map-pill--active i) {
    color: #38bdf8;
}

:deep(.sos-user-dot) {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #2563eb;
    border: 3px solid #ffffff;
    box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.28);
}
</style>
