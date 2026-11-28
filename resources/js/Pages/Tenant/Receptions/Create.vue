<script setup>
import CarDamageDiagram from '@/Components/CarDamageDiagram.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

defineOptions({ layout: TenantLayout });

const props = defineProps({
    agencies: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    courtesyVehicles: { type: Array, default: () => [] },
});

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: '',
    vehicle_id: '',
    appointment_id: '',
    courtesy_vehicle_id: '',
    courtesy_deposit_amount: 0,
    courtesy_departure_mileage: 0,
    courtesy_departure_fuel: 100,
    visit_reason: 'entretien',
    mileage_in: 0,
    fuel_level: 50,
    belongings_left: '',
    damage_markers: [],
    client_signature_data: '',
    notes: '',
    photos: [],
});

const visitReasons = [
    { value: 'entretien', label: 'Entretien', icon: 'bi-droplet-half', desc: 'Vidange, révision' },
    { value: 'panne', label: 'Panne', icon: 'bi-wrench-adjustable', desc: 'Diagnostic, réparation' },
    { value: 'controle', label: 'Contrôle', icon: 'bi-shield-check', desc: 'Pré-visite, bilan' },
    { value: 'accident', label: 'Accident', icon: 'bi-car-front-fill', desc: 'Carrosserie, sinistre' },
];

const quickBelongings = [
    'Roue de secours',
    'Cric',
    'Triangle',
    'Gilet jaune',
    'Carte grise',
    'Clé antivol',
    'Extincteur',
];

function toggleBelongingChip(label) {
    const items = (form.belongings_left || '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    const idx = items.findIndex((i) => i.toLowerCase() === label.toLowerCase());
    if (idx >= 0) {
        items.splice(idx, 1);
    } else {
        items.push(label);
    }
    form.belongings_left = items.join(', ');
}

function hasBelongingChip(label) {
    return (form.belongings_left || '').toLowerCase().includes(label.toLowerCase());
}

const filteredVehicles = computed(() => {
    if (!form.customer_id) return props.vehicles ?? [];
    return (props.vehicles ?? []).filter((v) => Number(v.customer_id) === Number(form.customer_id));
});

const selectedVehicle = computed(() =>
    (props.vehicles ?? []).find((v) => Number(v.id) === Number(form.vehicle_id)) || null
);

function syncMileageFromVehicle(vehicleId) {
    const v = (props.vehicles ?? []).find((item) => Number(item.id) === Number(vehicleId));
    form.mileage_in = v ? Number(v.mileage ?? 0) : 0;
}

watch(
    () => form.appointment_id,
    (apptId) => {
        if (!apptId) return;
        const appt = (props.appointments ?? []).find((a) => Number(a.id) === Number(apptId));
        if (!appt) return;
        if (appt.customer_id) form.customer_id = appt.customer_id;
        nextTick(() => {
            if (appt.vehicle_id) {
                form.vehicle_id = appt.vehicle_id;
                syncMileageFromVehicle(appt.vehicle_id);
            }
            if (['entretien', 'panne', 'controle', 'accident'].includes(appt.reason)) {
                form.visit_reason = appt.reason;
            }
        });
    }
);

watch(
    () => form.customer_id,
    () => {
        const list = filteredVehicles.value;
        if (list.length === 1) {
            form.vehicle_id = list[0].id;
            syncMileageFromVehicle(list[0].id);
        } else if (!list.some((v) => Number(v.id) === Number(form.vehicle_id))) {
            form.vehicle_id = '';
            form.mileage_in = 0;
        }
        nextTick(() => {
            if (window.$ && window.$.fn?.select2) {
                window.$('#createReceptionVehicleSelect').trigger('change.select2');
            }
        });
    }
);

watch(
    () => form.vehicle_id,
    (newId) => {
        syncMileageFromVehicle(newId);
    }
);

watch(
    () => form.courtesy_vehicle_id,
    (newId) => {
        const cv = (props.courtesyVehicles ?? []).find((item) => Number(item.id) === Number(newId));
        form.courtesy_deposit_amount = cv ? Number(cv.deposit_amount ?? 0) : 0;
        form.courtesy_departure_mileage = cv ? Number(cv.current_mileage ?? 0) : 0;
    }
);

const fuelColor = computed(() => {
    const lvl = Number(form.fuel_level || 0);
    if (lvl <= 20) return '#dc2626';
    if (lvl <= 50) return '#f59e0b';
    return '#16a34a';
});

// Pad signature numérique tactile + souris
const sigCanvasRef = ref(null);
let isDrawingSig = false;
const hasSignature = ref(false);

function getSigCoords(e, canvas) {
    const rect = canvas.getBoundingClientRect();
    const pt = e.touches ? e.touches[0] : e;
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (pt.clientX - rect.left) * scaleX,
        y: (pt.clientY - rect.top) * scaleY,
    };
}

function startSig(e) {
    const canvas = sigCanvasRef.value;
    if (!canvas) return;
    isDrawingSig = true;
    const ctx = canvas.getContext('2d');
    const { x, y } = getSigCoords(e, canvas);
    ctx.beginPath();
    ctx.moveTo(x, y);
}

function drawSig(e) {
    if (!isDrawingSig || !sigCanvasRef.value) return;
    const canvas = sigCanvasRef.value;
    const ctx = canvas.getContext('2d');
    const { x, y } = getSigCoords(e, canvas);
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';
    ctx.lineTo(x, y);
    ctx.stroke();
    hasSignature.value = true;
    form.client_signature_data = canvas.toDataURL('image/png');
}

function stopSig() {
    isDrawingSig = false;
}

function clearSig() {
    const canvas = sigCanvasRef.value;
    if (canvas) {
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    }
    hasSignature.value = false;
    form.client_signature_data = '';
}

function submitReception() {
    form.post('/receptions', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Nouvelle fiche de réception" />

    <div class="container-fluid py-3">
        <!-- En-tête de page -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="mb-1">
                    <Link href="/receptions" class="text-decoration-none small fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i>Retour aux réceptions
                    </Link>
                </div>
                <h1 class="h4 fw-bold text-dark mb-1">
                    <i class="bi bi-clipboard2-check-fill text-primary me-2"></i>Nouvelle fiche de réception véhicule
                </h1>
                <p class="text-muted small mb-0">
                    État des lieux complet à l'entrée atelier : kilométrage, niveau carburant, tour de caisse interactif, photos et signature client.
                </p>
            </div>
            <div class="d-flex gap-2">
                <Link href="/receptions" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">
                    Annuler
                </Link>
                <button
                    type="button"
                    class="btn btn-primary rounded-pill px-4 btn-sm fw-bold shadow-sm"
                    :disabled="form.processing"
                    @click="submitReception"
                >
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="bi bi-check2-circle me-1"></i>Valider la réception
                </button>
            </div>
        </div>

        <form @submit.prevent="submitReception">
            <div class="row g-4 align-items-start">
                <!-- COLONNE GAUCHE (7 cols) : Identification + Carburant + Tour de caisse réaliste -->
                <div class="col-lg-7 d-flex flex-column gap-4">
                    <!-- Carte 1 : Client, Véhicule & Entrée atelier -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">1</span>Identification &amp; Entrée atelier
                            </h2>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Agence *</label>
                                    <select v-model="form.agency_id" class="form-select" required>
                                        <option v-for="a in agencies" :key="a.id" :value="a.id">{{ a.name }}</option>
                                    </select>
                                    <div v-if="form.errors.agency_id" class="text-danger small mt-1">{{ form.errors.agency_id }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Client *</label>
                                    <select v-model="form.customer_id" class="form-select" required>
                                        <option value="">-- Choisir un client --</option>
                                        <option v-for="c in customers" :key="c.id" :value="c.id">
                                            {{ c.name }} {{ c.first_name || '' }} {{ c.phone ? `(${c.phone})` : '' }}
                                        </option>
                                    </select>
                                    <div v-if="form.errors.customer_id" class="text-danger small mt-1">{{ form.errors.customer_id }}</div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Véhicule *</label>
                                    <select id="createReceptionVehicleSelect" v-model="form.vehicle_id" class="form-select" required>
                                        <option value="">-- Choisir un véhicule --</option>
                                        <option v-for="v in filteredVehicles" :key="v.id" :value="v.id">
                                            {{ v.license_plate }} — {{ v.brand }} {{ v.model }}
                                        </option>
                                    </select>
                                    <div v-if="form.errors.vehicle_id" class="text-danger small mt-1">{{ form.errors.vehicle_id }}</div>
                                </div>

                                <!-- Lien rapide avec un RDV existant -->
                                <div v-if="appointments.length" class="col-12">
                                    <label class="form-label small fw-semibold">Lier à un rendez-vous planifié (facultatif)</label>
                                    <select v-model="form.appointment_id" class="form-select form-select-sm">
                                        <option value="">Aucun rendez-vous lié (passage direct)</option>
                                        <option v-for="a in appointments" :key="a.id" :value="a.id">
                                            RDV #{{ a.id }} — {{ a.customer?.name }} ({{ a.vehicle?.license_plate }})
                                        </option>
                                    </select>
                                </div>

                                <!-- Résumé véhicule sélectionné -->
                                <div v-if="selectedVehicle" class="col-12">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 rounded-3 bg-primary-subtle bg-opacity-25 border border-primary-subtle small">
                                        <div class="fw-bold text-primary">
                                            <i class="bi bi-car-front-fill me-2"></i>
                                            {{ selectedVehicle.brand }} {{ selectedVehicle.model }}
                                            <span v-if="selectedVehicle.year" class="fw-normal text-muted">({{ selectedVehicle.year }})</span>
                                        </div>
                                        <span class="badge bg-dark rounded-pill px-3">{{ selectedVehicle.license_plate }}</span>
                                    </div>
                                </div>

                                <!-- Motif de visite en cartes interactives -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Motif de la visite *</label>
                                    <div class="row g-2">
                                        <div v-for="r in visitReasons" :key="r.value" class="col-6 col-md-3">
                                            <button
                                                type="button"
                                                class="w-100 text-start p-2 px-3 rounded-3 border transition-all d-flex flex-column gap-1"
                                                :class="form.visit_reason === r.value ? 'border-primary bg-primary-subtle bg-opacity-25 shadow-sm' : 'bg-white border-secondary-subtle'"
                                                @click="form.visit_reason = r.value"
                                            >
                                                <div class="d-flex align-items-center gap-2">
                                                    <i class="bi" :class="[r.icon, form.visit_reason === r.value ? 'text-primary' : 'text-muted']"></i>
                                                    <span class="small fw-bold text-dark">{{ r.label }}</span>
                                                </div>
                                                <span class="text-muted" style="font-size: 0.7rem;">{{ r.desc }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Kilométrage & Jauge Carburant visuelle -->
                                <div class="col-md-5">
                                    <label class="form-label small fw-semibold">Kilométrage à l'entrée (km) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="bi bi-speedometer2 text-primary"></i></span>
                                        <input v-model.number="form.mileage_in" type="number" min="0" class="form-control fw-bold" required />
                                        <span class="input-group-text bg-light small">km</span>
                                    </div>
                                    <div v-if="form.errors.mileage_in" class="text-danger small mt-1">{{ form.errors.mileage_in }}</div>
                                </div>

                                <div class="col-md-7">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label small fw-semibold mb-0">
                                            <i class="bi bi-fuel-pump-fill me-1" :style="{ color: fuelColor }"></i>Niveau carburant
                                        </label>
                                        <span class="badge rounded-pill fw-bold" :style="{ background: fuelColor }">{{ form.fuel_level }}%</span>
                                    </div>
                                    <div class="progress rounded-pill mb-2" style="height: 8px; background: #e2e8f0;">
                                        <div
                                            class="progress-bar rounded-pill"
                                            :style="{ width: `${form.fuel_level}%`, backgroundColor: fuelColor, transition: 'width 0.15s ease' }"
                                        ></div>
                                    </div>
                                    <input v-model.number="form.fuel_level" type="range" min="0" max="100" step="5" class="form-range mb-1" />
                                    <div class="d-flex justify-content-between gap-1">
                                        <button
                                            v-for="preset in [{ l: 'Vide', v: 0 }, { l: '1/4', v: 25 }, { l: '1/2', v: 50 }, { l: '3/4', v: 75 }, { l: 'Plein', v: 100 }]"
                                            :key="preset.v"
                                            type="button"
                                            class="btn btn-xs py-0 px-2 rounded-pill border"
                                            style="font-size: 0.7rem;"
                                            :class="form.fuel_level === preset.v ? 'btn-dark text-white' : 'btn-light text-muted'"
                                            @click="form.fuel_level = preset.v"
                                        >
                                            {{ preset.l }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Objets laissés dans le véhicule + chips rapides -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Objets &amp; accessoires laissés dans le véhicule</label>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <button
                                            v-for="chip in quickBelongings"
                                            :key="chip"
                                            type="button"
                                            class="btn btn-sm rounded-pill px-2 py-0"
                                            style="font-size: 0.75rem;"
                                            :class="hasBelongingChip(chip) ? 'btn-primary text-white' : 'btn-outline-secondary'"
                                            @click="toggleBelongingChip(chip)"
                                        >
                                            <i class="bi me-1" :class="hasBelongingChip(chip) ? 'bi-check2' : 'bi-plus'"></i>{{ chip }}
                                        </button>
                                    </div>
                                    <input
                                        v-model="form.belongings_left"
                                        type="text"
                                        class="form-control"
                                        placeholder="Cric, roue de secours, carte grise, effets personnels..."
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Carte 2 : Tour de caisse visuel réaliste -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">2</span>Tour de caisse visuel (Rayures / Bosses / Vitres)
                            </h2>
                            <span v-if="form.damage_markers.length" class="badge bg-warning text-dark rounded-pill">
                                {{ form.damage_markers.length }} point(s)
                            </span>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <CarDamageDiagram v-model="form.damage_markers" />
                        </div>
                    </div>

                    <!-- Carte 3 : Notes complémentaires -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <label class="form-label small fw-semibold">Observations / demandes particulières du client (facultatif)</label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                class="form-control"
                                placeholder="Bruits signalés, voyants allumés au tableau de bord, consignes de restitution..."
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- COLONNE DROITE (5 cols) : Photos + Courtoisie + Signature client -->
                <div class="col-lg-5 d-flex flex-column gap-4">
                    <!-- Carte 4 : Photos du véhicule -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">3</span>Photos du véhicule (illimitées)
                            </h2>
                            <span v-if="form.photos?.length" class="badge bg-success-subtle text-success rounded-pill">
                                {{ form.photos.length }} photo(s)
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <FileDropzone
                                v-model="form.photos"
                                :multiple="true"
                                accept="image/jpeg,image/png,image/webp"
                                label="Glissez-déposez ou cliquez pour ajouter des photos (avant, arrière, côtés, compteur)"
                            />
                            <div v-if="form.errors.photos" class="text-danger small mt-1">{{ form.errors.photos }}</div>
                        </div>
                    </div>

                    <!-- Carte 5 : Véhicule de courtoisie -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">4</span>Véhicule de courtoisie (facultatif)
                            </h2>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Véhicule de remplacement prêté</label>
                                    <select v-model="form.courtesy_vehicle_id" class="form-select">
                                        <option value="">Aucun véhicule prêté</option>
                                        <option v-for="cv in courtesyVehicles" :key="cv.id" :value="cv.id">
                                            {{ cv.name }} ({{ cv.license_plate }})
                                        </option>
                                    </select>
                                    <div class="form-text">Véhicule mis à disposition du client pendant l'immobilisation.</div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Caution encaissée (FCFA)</label>
                                    <input v-model.number="form.courtesy_deposit_amount" type="number" min="0" step="1000" class="form-control" />
                                </div>
                                <template v-if="form.courtesy_vehicle_id">
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold">Km départ prêt</label>
                                        <input v-model.number="form.courtesy_departure_mileage" type="number" min="0" class="form-control form-control-sm" />
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-semibold">Carburant départ (%)</label>
                                        <input v-model.number="form.courtesy_departure_fuel" type="number" min="0" max="100" class="form-control form-control-sm" />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Carte 6 : Signature numérique client (facultative) -->
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">5</span>Signature client (facultative)
                            </h2>
                            <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-danger" @click="clearSig">
                                <i class="bi bi-eraser me-1"></i>Effacer
                            </button>
                        </div>
                        <div class="card-body p-4">
                            <div class="position-relative rounded-3 border bg-light overflow-hidden">
                                <canvas
                                    ref="sigCanvasRef"
                                    width="460"
                                    height="160"
                                    class="w-100 d-block bg-white"
                                    style="height: 160px; touch-action: none; cursor: crosshair;"
                                    @mousedown="startSig"
                                    @mousemove="drawSig"
                                    @mouseup="stopSig"
                                    @mouseleave="stopSig"
                                    @touchstart.prevent="startSig"
                                    @touchmove.prevent="drawSig"
                                    @touchend.prevent="stopSig"
                                ></canvas>
                                <div
                                    v-if="!hasSignature"
                                    class="position-absolute top-50 start-50 translate-middle text-muted small pe-none opacity-50"
                                >
                                    <i class="bi bi-pen me-1"></i>Signez dans ce cadre (souris ou tactile)
                                </div>
                                <div
                                    class="position-absolute bottom-0 start-0 end-0 mx-4 mb-3 border-bottom border-secondary-subtle pe-none"
                                    style="border-bottom-style: dashed !important;"
                                ></div>
                            </div>
                            <div v-if="hasSignature" class="small text-success fw-semibold mt-2">
                                <i class="bi bi-check-circle-fill me-1"></i>Signature capturée
                            </div>
                        </div>
                    </div>

                    <!-- Bouton de validation principal -->
                    <div class="d-grid gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary btn-lg rounded-pill fw-bold shadow"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                            <i v-else class="bi bi-check2-circle me-2"></i>Valider et enregistrer la réception
                        </button>
                        <Link href="/receptions" class="btn btn-light border rounded-pill text-muted">
                            Annuler et revenir à la liste
                        </Link>
                    </div>
                </div>
            </div>
        </form>
    </div>
</template>
