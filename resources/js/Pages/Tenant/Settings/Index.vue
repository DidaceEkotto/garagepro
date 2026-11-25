<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useLightbox } from '@/composables/useLightbox';
import { Head, useForm } from '@inertiajs/vue3';

const { openLightbox } = useLightbox();

const props = defineProps({
    space: Object,
});

const dayOptions = [
    { key: 'mon', label: 'Lun' },
    { key: 'tue', label: 'Mar' },
    { key: 'wed', label: 'Mer' },
    { key: 'thu', label: 'Jeu' },
    { key: 'fri', label: 'Ven' },
    { key: 'sat', label: 'Sam' },
    { key: 'sun', label: 'Dim' },
];

const form = useForm({
    name: props.space?.name ?? '',
    phone: props.space?.phone ?? '',
    address: props.space?.address ?? '',
    city: props.space?.city ?? '',
    vat_rate: props.space?.vat_rate ?? 19.25,
    hourly_rate: props.space?.hourly_rate ?? 5000,
    legal_mentions: props.space?.legal_mentions ?? '',
    work_start_time: props.space?.work_start_time ?? '08:00',
    work_end_time: props.space?.work_end_time ?? '17:30',
    work_days: props.space?.work_days ?? ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
    late_penalty_per_hour: props.space?.late_penalty_per_hour ?? 1000,
    payroll_day_of_month: props.space?.payroll_day_of_month ?? 28,
    logo: null,
});

function toggleDay(dayKey) {
    const idx = form.work_days.indexOf(dayKey);
    if (idx === -1) {
        form.work_days.push(dayKey);
    } else if (form.work_days.length > 1) {
        form.work_days.splice(idx, 1);
    }
}

function saveSettings() {
    form.post('/parametres-espace', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Paramètres Espace" />

    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Paramètres de l'espace garage</h1>
            <p class="text-muted small mb-0">Identité, logo, fiscalité, horaires de travail et retenues sur retard</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <form @submit.prevent="saveSettings">
                            <h2 class="h6 fw-bold text-primary mb-3">Informations générales &amp; Facturation</h2>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Nom du garage</label>
                                    <input v-model="form.name" type="text" class="form-control" required />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Téléphone</label>
                                    <input v-model="form.phone" type="text" class="form-control" />
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Adresse</label>
                                    <input v-model="form.address" type="text" class="form-control" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Ville</label>
                                    <input v-model="form.city" type="text" class="form-control" />
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Taux de TVA (%)</label>
                                    <input v-model="form.vat_rate" type="number" step="0.01" class="form-control" required />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Taux horaire main-d'œuvre (FCFA)</label>
                                    <input v-model.number="form.hourly_rate" type="number" class="form-control" required />
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small">Mentions légales (devis et factures PDF)</label>
                                <textarea v-model="form.legal_mentions" rows="2" class="form-control" placeholder="NIU, RCCM, conditions de garantie..."></textarea>
                            </div>

                            <hr class="my-4" />
                            <h2 class="h6 fw-bold text-primary mb-3">Horaires de travail &amp; Retenues de pointage</h2>

                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label small">Heure d'arrivée</label>
                                    <TimePickerInput v-model="form.work_start_time" required />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Heure de départ</label>
                                    <TimePickerInput v-model="form.work_end_time" required />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Retenue retard (FCFA/h)</label>
                                    <input v-model.number="form.late_penalty_per_hour" type="number" min="0" class="form-control" required />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Jour de paie (1-31)</label>
                                    <input v-model.number="form.payroll_day_of_month" type="number" min="1" max="31" class="form-control" title="Alerte automatique de J-7 à J-1" />
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small d-block">Jours de travail (génération auto des fiches de pointage)</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        v-for="d in dayOptions"
                                        :key="d.key"
                                        type="button"
                                        class="btn btn-sm"
                                        :class="form.work_days.includes(d.key) ? 'btn-primary' : 'btn-outline-secondary'"
                                        @click="toggleDay(d.key)"
                                    >
                                        {{ d.label }}
                                    </button>
                                </div>
                            </div>

                            <hr class="my-4" />
                            <div class="mb-4">
                                <label class="form-label small">Logo du garage (JPEG, PNG, WebP — max 5 Mo)</label>
                                <FileDropzone v-model="form.logo" label="Glissez-déposez le logo du garage ici ou cliquez" />
                            </div>

                            <button type="submit" class="btn btn-primary px-4 py-2" :disabled="form.processing">
                                <i class="bi bi-check-lg me-1"></i>Enregistrer les paramètres
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center p-4">
                        <h2 class="h6 fw-bold mb-3">Logo actuel</h2>
                        <img
                            v-if="space?.logo_url"
                            :src="space.logo_url"
                            alt="Logo du garage"
                            class="img-fluid rounded border p-2 bg-white"
                            style="max-height: 140px; cursor: zoom-in;"
                            title="Cliquer pour agrandir"
                            @click="openLightbox([space.logo_url], 0, space?.name || 'Logo')"
                        />
                        <div v-else class="text-muted small py-4 border rounded bg-light">
                            Aucun logo téléversé pour le moment.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
