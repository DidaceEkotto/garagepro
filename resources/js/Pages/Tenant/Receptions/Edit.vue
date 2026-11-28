<script setup>
import CarDamageDiagram from '@/Components/CarDamageDiagram.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import { useLightbox } from '@/composables/useLightbox';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: TenantLayout });

const { openLightbox } = useLightbox();

const props = defineProps({
    reception: { type: Object, required: true },
    agencies: { type: Array, default: () => [] },
});

const form = useForm({
    agency_id: props.reception.agency_id,
    visit_reason: props.reception.visit_reason || 'entretien',
    mileage_in: props.reception.mileage_in ?? 0,
    fuel_level: props.reception.fuel_level ?? 50,
    belongings_left: props.reception.belongings_left ?? '',
    damage_markers: Array.isArray(props.reception.damage_markers) ? [...props.reception.damage_markers] : [],
    courtesy_deposit_amount: props.reception.courtesy_deposit_amount ?? 0,
    notes: props.reception.notes ?? '',
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

const fuelColor = computed(() => {
    const lvl = Number(form.fuel_level || 0);
    if (lvl <= 20) return '#dc2626';
    if (lvl <= 50) return '#f59e0b';
    return '#16a34a';
});

function submitEdit() {
    form.post(`/receptions/${props.reception.id}`, {
        forceFormData: true,
    });
}
</script>

<template>
    <Head :title="`Modifier réception #${reception.id}`" />

    <div class="container-fluid py-3">
        <!-- En-tête -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="mb-1">
                    <Link href="/receptions" class="text-decoration-none small fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i>Retour aux réceptions
                    </Link>
                </div>
                <h1 class="h4 fw-bold text-dark mb-1">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Modifier la fiche de réception #{{ reception.id }}
                </h1>
                <p class="text-muted small mb-0">
                    {{ reception.vehicle?.license_plate }} — {{ reception.vehicle?.brand }} {{ reception.vehicle?.model }}
                    • Client : <strong>{{ reception.customer?.name }} {{ reception.customer?.first_name || '' }}</strong>
                    <span v-if="reception.received_at_formatted"> • Reçu le {{ reception.received_at_formatted }}</span>
                </p>
            </div>
            <div class="d-flex gap-2">
                <Link href="/receptions" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">Annuler</Link>
                <button
                    type="button"
                    class="btn btn-primary rounded-pill px-4 btn-sm fw-bold shadow-sm"
                    :disabled="form.processing"
                    @click="submitEdit"
                >
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                    <i v-else class="bi bi-check2-circle me-1"></i>Enregistrer les modifications
                </button>
            </div>
        </div>

        <form @submit.prevent="submitEdit">
            <div class="row g-4 align-items-start">
                <!-- COLONNE GAUCHE -->
                <div class="col-lg-7 d-flex flex-column gap-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">1</span>Informations d'entrée atelier
                            </h2>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Agence *</label>
                                    <select v-model="form.agency_id" class="form-select" required>
                                        <option v-for="a in agencies" :key="a.id" :value="a.id">{{ a.name }}</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Kilométrage à l'entrée (km) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="bi bi-speedometer2 text-primary"></i></span>
                                        <input v-model.number="form.mileage_in" type="number" min="0" class="form-control fw-bold" required />
                                        <span class="input-group-text bg-light small">km</span>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Motif de la visite *</label>
                                    <div class="row g-2">
                                        <div v-for="r in visitReasons" :key="r.value" class="col-6 col-md-3">
                                            <button
                                                type="button"
                                                class="w-100 text-start p-2 px-3 rounded-3 border d-flex flex-column gap-1"
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

                                <div class="col-12">
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
                                    <input v-model="form.belongings_left" type="text" class="form-control" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tour de caisse visuel pré-rempli avec tous les points existants -->
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

                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <label class="form-label small fw-semibold">Observations / Notes</label>
                            <textarea v-model="form.notes" rows="3" class="form-control"></textarea>
                        </div>
                    </div>
                </div>

                <!-- COLONNE DROITE -->
                <div class="col-lg-5 d-flex flex-column gap-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">3</span>Photos du véhicule
                            </h2>
                            <span class="badge bg-primary-subtle text-primary rounded-pill">
                                {{ (reception.photo_urls?.length || 0) + (form.photos?.length || 0) }} photo(s)
                            </span>
                        </div>
                        <div class="card-body p-4">
                            <div v-if="reception.photo_urls?.length" class="mb-3">
                                <div class="small fw-semibold text-muted mb-2">Photos déjà enregistrées :</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <img
                                        v-for="(url, i) in reception.photo_urls"
                                        :key="url"
                                        :src="url"
                                        alt="Photo réception"
                                        class="rounded-3 border shadow-sm"
                                        style="width: 78px; height: 78px; object-fit: cover; cursor: zoom-in;"
                                        @click="openLightbox(reception.photo_urls, i)"
                                    />
                                </div>
                            </div>
                            <FileDropzone
                                v-model="form.photos"
                                :multiple="true"
                                accept="image/jpeg,image/png,image/webp"
                                label="Ajouter de nouvelles photos à cette fiche"
                            />
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h2 class="h6 fw-bold mb-0">
                                <span class="badge bg-primary rounded-circle me-2">4</span>Véhicule de courtoisie &amp; Caution
                            </h2>
                        </div>
                        <div class="card-body p-4">
                            <div v-if="reception.courtesy_vehicle" class="alert alert-light border small py-2 mb-3">
                                Véhicule prêté : <strong>{{ reception.courtesy_vehicle.name }} ({{ reception.courtesy_vehicle.license_plate }})</strong>
                            </div>
                            <label class="form-label small fw-semibold">Caution encaissée (FCFA)</label>
                            <input v-model.number="form.courtesy_deposit_amount" type="number" min="0" step="1000" class="form-control" />
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow" :disabled="form.processing">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2"></span>
                            <i v-else class="bi bi-check2-circle me-2"></i>Enregistrer les modifications
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
