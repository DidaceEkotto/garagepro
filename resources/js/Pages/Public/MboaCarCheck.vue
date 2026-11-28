<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    query: { type: String, default: '' },
    report: { type: Object, default: null },
});

const searchInput = ref(props.query || '');

function runSearch() {
    if (!searchInput.value.trim()) return;
    router.get('/verifier-vehicule', { q: searchInput.value.trim() });
}
</script>

<template>
    <Head title="MboaCar Check — Rapport Historique Anti-Fraude Occasion" />
    <div class="min-vh-100 bg-light">
        <nav class="navbar navbar-dark bg-dark px-3 py-2 shadow-sm">
            <div class="container d-flex justify-content-between align-items-center">
                <Link href="/" class="navbar-brand fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-shield-check text-success"></i>MboaCar Check
                </Link>
                <div class="d-flex gap-2">
                    <Link href="/sos-depannage" class="btn btn-outline-danger btn-sm">SOS Dépannage</Link>
                    <Link href="/connexion" class="btn btn-primary btn-sm">Espace Pro</Link>
                </div>
            </div>
        </nav>

        <div class="container py-5" style="max-width: 860px;">
            <div class="text-center mb-4">
                <span class="badge bg-success-subtle text-success mb-2 px-3 py-2">Vérification Anti-Fraude Occasion</span>
                <h1 class="h3 fw-bold mb-2">Vérifiez l'historique réel d'un véhicule avant d'acheter</h1>
                <p class="text-muted small">
                    Détection automatique des compteurs trafiqués, historique des passages en atelier, visites techniques et sinistres sur tout le réseau MboaCar.
                </p>
            </div>

            <form class="input-group input-group-lg shadow-sm mb-4" @submit.prevent="runSearch">
                <input
                    v-model="searchInput"
                    type="text"
                    class="form-control text-uppercase"
                    placeholder="Entrez la plaque (ex: LT-100-AA) ou le numéro VIN..."
                    required
                />
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    <i class="bi bi-search me-1"></i>Vérifier
                </button>
            </form>

            <div v-if="report && !report.found" class="alert alert-warning text-center">
                Aucun passage vérifié trouvé sur le réseau MboaCar pour <strong>{{ report.query }}</strong>.
            </div>

            <div v-if="report && report.found">
                <!-- Alerte fraude compteur ou OK -->
                <div v-if="report.odometer_fraud_detected" class="alert alert-danger border-0 shadow-sm mb-4">
                    <h2 class="h6 fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill me-2"></i>ALERTE FRAUDE COMPTEUR DÉTECTÉE</h2>
                    <div v-for="(a, idx) in report.fraud_alerts" :key="idx" class="small">{{ a }}</div>
                </div>
                <div v-else class="alert alert-success border-0 shadow-sm mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <strong>Kilométrage cohérent :</strong> aucun recul suspect détecté dans l'historique vérifié.
                </div>

                <!-- Carte synthèse & Score de confiance -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <div class="small text-muted">Véhicule vérifié</div>
                                <h2 class="h4 fw-bold mb-1">
                                    {{ report.vehicle.brand }} {{ report.vehicle.model }}
                                    <span v-if="report.vehicle.year">({{ report.vehicle.year }})</span>
                                </h2>
                                <div class="small text-muted">
                                    Plaque : <strong>{{ report.vehicle.license_plate }}</strong>
                                    <span v-if="report.vehicle.vin"> • VIN : <strong>{{ report.vehicle.vin }}</strong></span>
                                    • Dernier km : <strong>{{ Number(report.vehicle.latest_mileage || 0).toLocaleString('fr-FR') }} km</strong>
                                </div>
                            </div>
                            <div class="text-center">
                                <div class="small text-muted">Score de confiance</div>
                                <div
                                    class="display-6 fw-bold"
                                    :class="report.trust_score >= 75 ? 'text-success' : (report.trust_score >= 45 ? 'text-warning' : 'text-danger')"
                                >
                                    {{ report.trust_score }}/100
                                </div>
                                <a :href="`/verifier-vehicule/pdf?q=${encodeURIComponent(query)}`" class="btn btn-outline-dark btn-sm mt-1">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>Certificat PDF
                                </a>
                            </div>
                        </div>

                        <div class="row g-2 mt-3 pt-3 border-top text-center small">
                            <div class="col-3">
                                <div class="fw-bold fs-5">{{ report.stats.garages_visited }}</div>
                                <div class="text-muted">Établissements</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5">{{ report.stats.receptions_count }}</div>
                                <div class="text-muted">Passages atelier</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5">{{ report.stats.tech_inspections_count }}</div>
                                <div class="text-muted">Visites tech.</div>
                            </div>
                            <div class="col-3">
                                <div class="fw-bold fs-5" :class="report.stats.claims_count > 0 ? 'text-danger' : 'text-success'">
                                    {{ report.stats.claims_count }}
                                </div>
                                <div class="text-muted">Sinistres</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chronologie anonymisée -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Chronologie vérifiée (anonymisée)</div>
                    <div class="list-group list-group-flush small">
                        <div v-for="(ev, idx) in report.timeline" :key="idx" class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-light text-dark border me-2">{{ ev.date }}</span>
                                <span class="fw-semibold">{{ ev.label }}</span>
                            </div>
                            <span v-if="ev.mileage" class="text-muted fw-semibold">{{ Number(ev.mileage).toLocaleString('fr-FR') }} km</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
