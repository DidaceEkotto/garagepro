<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import LocationAutocomplete from '@/Components/LocationAutocomplete.vue';
import PhoneInput from '@/Components/PhoneInput.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useLightbox } from '@/composables/useLightbox';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

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
    formatted_location: props.space?.formatted_location ?? props.space?.city ?? '',
    latitude: props.space?.latitude ?? null,
    longitude: props.space?.longitude ?? null,
    sos_enabled: props.space?.sos_enabled ?? true,
    sos_radius_km: props.space?.sos_radius_km ?? 35,
    whatsapp_bot_enabled: props.space?.whatsapp_bot_enabled ?? false,
    whatsapp_bot_catalog: props.space?.whatsapp_bot_catalog ?? '',
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

const botTestMsg = ref('Bonjour, combien coûte une vidange complète sur Toyota RAV4 ? Avez-vous de la place demain ?');
const botTestReply = ref('');
const botTesting = ref(false);

function onLocationSelected(loc) {
    form.city = loc.city || form.city;
    form.formatted_location = loc.formatted_location || loc.city;
    form.latitude = loc.latitude;
    form.longitude = loc.longitude;
}

async function testWhatsappBot() {
    if (!botTestMsg.value.trim() || botTesting.value) return;
    botTesting.value = true;
    botTestReply.value = '';
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch('/parametres-espace/bot-whatsapp/tester', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/json',
            },
            body: JSON.stringify({ message: botTestMsg.value }),
        });
        const data = await res.json();
        botTestReply.value = data.reply || 'Aucune réponse.';
    } finally {
        botTesting.value = false;
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
                                    <PhoneInput v-model="form.phone" :is-invalid="!!form.errors.phone" />
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

        <!-- Localisation OpenStreetMap (SOS Dépannage) & Réceptionniste IA WhatsApp 24/7 -->
        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 fw-bold">
                        <i class="bi bi-geo-alt-fill text-danger me-2"></i>Localisation GPS OpenStreetMap &amp; SOS Dépannage
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Localisation précise de l'établissement</label>
                            <LocationAutocomplete
                                v-model="form.formatted_location"
                                :country="space?.country || ''"
                                placeholder="Tapez votre quartier, rue ou ville..."
                                @select="onLocationSelected"
                            />
                            <div v-if="form.latitude && form.longitude" class="form-text text-success small">
                                <i class="bi bi-check-circle-fill me-1"></i>Coordonnées GPS : {{ Number(form.latitude).toFixed(5) }}, {{ Number(form.longitude).toFixed(5) }}
                            </div>
                        </div>
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input id="sosSwitch" v-model="form.sos_enabled" type="checkbox" class="form-check-input" />
                                    <label class="form-check-label small fw-semibold" for="sosSwitch">Recevoir les alertes SOS Dépannage</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1">Rayon d'intervention (km)</label>
                                <input v-model.number="form.sos_radius_km" type="number" min="5" max="200" class="form-control form-control-sm" />
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm px-4" :disabled="form.processing" @click="saveSettings">
                            Enregistrer la localisation &amp; SOS
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3 fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-robot text-success me-2"></i>Réceptionniste IA WhatsApp 24h/24</span>
                        <div class="form-check form-switch mb-0">
                            <input id="botSwitch" v-model="form.whatsapp_bot_enabled" type="checkbox" class="form-check-input" />
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Grille de tarifs &amp; horaires communiqués aux clients par l'IA</label>
                            <textarea
                                v-model="form.whatsapp_bot_catalog"
                                rows="3"
                                class="form-control form-control-sm"
                                placeholder="Ex: Vidange simple 25 000 FCFA, Diagnostic valise 10 000 FCFA, Plaquettes dès 18 000 FCFA. Ouvert Lun-Sam 8h-18h."
                            ></textarea>
                        </div>
                        <div v-if="space?.whatsapp_bot_secret" class="alert alert-light border small py-1 px-2 mb-2">
                            <div><strong>Webhook :</strong> <code>{{ space.whatsapp_bot_webhook_url }}</code></div>
                            <div><strong>Header <code>X-Bot-Secret</code> :</strong> <code>{{ space.whatsapp_bot_secret }}</code></div>
                        </div>
                        <button type="button" class="btn btn-success btn-sm px-4 mb-3" :disabled="form.processing" @click="saveSettings">
                            Enregistrer le Bot WhatsApp
                        </button>

                        <div class="border-top pt-2">
                            <label class="form-label small fw-semibold mb-1">Simulateur en direct (tester une question client)</label>
                            <div class="input-group input-group-sm mb-2">
                                <input v-model="botTestMsg" type="text" class="form-control" />
                                <button type="button" class="btn btn-outline-success" :disabled="botTesting" @click="testWhatsappBot">
                                    {{ botTesting ? '...' : 'Tester' }}
                                </button>
                            </div>
                            <div v-if="botTestReply" class="p-2 rounded bg-light border small" style="white-space: pre-wrap;">{{ botTestReply }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
