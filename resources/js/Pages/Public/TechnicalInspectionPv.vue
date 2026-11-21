<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    pv: { type: Object, required: true },
    token: { type: String, default: '' },
    garages: { type: Array, default: () => [] },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const quoteForm = useForm({
    garage_tenant_id: props.garages?.[0]?.id ?? '',
    client_phone: '',
});

function submitQuoteRequest() {
    quoteForm.post(`/pv-visite/${props.token}/demander-devis`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`PV Visite Technique ${pv.pv_number}`" />

    <div class="min-vh-100 bg-light py-4 py-md-5">
        <div class="container" style="max-width: 780px;">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1">Certificat Officiel MboaCar</span>
                        <h1 class="h4 fw-bold mb-0">Procès-Verbal N° {{ pv.pv_number }}</h1>
                        <div class="small text-muted">{{ pv.center_name }} {{ pv.center_city ? `• ${pv.center_city}` : '' }}</div>
                    </div>
                    <span
                        class="badge fs-6"
                        :class="pv.verdict === 'favorable' ? 'bg-success' : (pv.verdict === 'contre_visite' ? 'bg-warning text-dark' : 'bg-danger')"
                    >
                        {{ pv.verdict === 'favorable' ? 'APTE (FAVORABLE)' : (pv.verdict === 'contre_visite' ? 'CONTRE-VISITE' : 'IMMOBILISATION') }}
                    </span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 small mb-4 pb-3 border-bottom">
                        <div class="col-md-6"><strong>Véhicule :</strong> {{ pv.vehicle_plate }} ({{ pv.vehicle_brand }} {{ pv.vehicle_model }})</div>
                        <div class="col-md-6"><strong>Propriétaire :</strong> {{ pv.customer_name || '-' }}</div>
                        <div class="col-md-6"><strong>Date du contrôle :</strong> {{ pv.inspected_at }}</div>
                        <div class="col-md-6"><strong>Kilométrage :</strong> {{ pv.mileage ? `${pv.mileage} km` : '-' }}</div>
                        <div v-if="pv.expires_at" class="col-md-6 text-success"><strong>Valable jusqu'au :</strong> {{ pv.expires_at }}</div>
                        <div v-if="pv.counter_visit_deadline" class="col-md-6 text-warning-emphasis"><strong>Date limite contre-visite :</strong> {{ pv.counter_visit_deadline }}</div>
                    </div>

                    <h6 class="fw-bold mb-2">Résultats par point de contrôle</h6>
                    <ul class="list-group list-group-flush small mb-3">
                        <li v-for="(d, idx) in pv.defects" :key="idx" class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <span>
                                <strong>{{ d.category }} :</strong> {{ d.item }}
                                <span v-if="d.note" class="text-muted"> — {{ d.note }}</span>
                            </span>
                            <span
                                class="badge"
                                :class="d.severity === 'ok' ? 'bg-success' : (d.severity === 'mineur' ? 'bg-info text-dark' : (d.severity === 'majeur' ? 'bg-warning text-dark' : 'bg-danger'))"
                            >
                                {{ d.severity }}
                            </span>
                        </li>
                    </ul>

                    <p v-if="pv.notes" class="small text-muted mb-0"><strong>Observations :</strong> {{ pv.notes }}</p>
                </div>
            </div>

            <!-- Passerelle Contre-visite -> Garage MboaCar -->
            <div v-if="pv.verdict !== 'favorable' && garages.length" class="card border-warning shadow-sm mt-3">
                <div class="card-header bg-warning-subtle fw-bold small text-dark py-2">
                    <i class="bi bi-wrench-adjustable me-1"></i>Corriger ces défauts dans un Garage MboaCar
                </div>
                <form class="card-body p-3" @submit.prevent="submitQuoteRequest">
                    <div v-if="flashSuccess" class="alert alert-success py-2 small mb-3">{{ flashSuccess }}</div>
                    <p class="small text-muted mb-2">Transmettez ce PV et la liste des défauts directement à un garage partenaire pour obtenir un devis de remise en état :</p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Garage choisi</label>
                            <select v-model="quoteForm.garage_tenant_id" class="form-select form-select-sm" required>
                                <option v-for="g in garages" :key="g.id" :value="g.id">{{ g.name }} {{ g.city ? `(${g.city})` : '' }}</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Votre WhatsApp (facultatif)</label>
                            <input v-model="quoteForm.client_phone" type="tel" class="form-control form-control-sm" placeholder="6XX XXX XXX" />
                        </div>
                        <div class="col-md-3 d-grid">
                            <button type="submit" class="btn btn-warning btn-sm fw-semibold text-dark" :disabled="quoteForm.processing">
                                Demander un devis
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
