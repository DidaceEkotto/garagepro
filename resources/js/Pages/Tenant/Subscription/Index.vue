<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    monthlyPrice: Number,
    referralDiscount: { type: Number, default: 0 },
    effectivePrice: { type: Number, default: null },
    gracePeriodDays: { type: Number, default: 5 },
    payments: Array,
    affiliateLink: { type: Object, default: null },
});

const tenant = computed(() => usePage().props.tenant);
const selectedPayment = ref(null);

function payNow() {
    router.post('/abonnement/payer');
}

function payWithAffiliate() {
    router.post('/affiliation/payer-abonnement');
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0);
}
</script>

<template>
    <Head title="Abonnement" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Abonnement GaragePro</h1>
            <p class="text-muted small mb-0">Gestion de votre formule mensuelle, paiements SaSPay et historique des souscriptions</p>
        </div>

        <div v-if="tenant?.isReadOnly" class="alert alert-danger border-0 shadow-sm mb-4">
            <i class="bi bi-lock-fill me-2"></i>
            <strong>Compte en mode lecture seule :</strong>
            Votre période d'essai ou abonnement a expiré. Vous pouvez consulter vos données mais aucune modification n'est possible tant que vous n'avez pas souscrit ci-dessous.
        </div>
        <div v-else-if="tenant?.isInTrialGrace" class="alert alert-warning border-0 shadow-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Période d'essai terminée (Mode grâce 2 jours) :</strong>
            Passez au plan payant avant la fin des 2 jours de grâce pour éviter le passage en lecture seule.
        </div>
        <div v-else-if="tenant?.isInSubscriptionGrace" class="alert alert-warning border-0 shadow-sm mb-4">
            <i class="bi bi-hourglass-split me-2"></i>
            <strong>Mode grâce actif ({{ gracePeriodDays }} jours) :</strong>
            Votre date de renouvellement est dépassée. Renouvelez votre abonnement rapidement pour éviter le blocage en lecture seule.
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3 p-4">
                <div>
                    <div class="fw-bold mb-1">
                        Statut actuel :
                        <span class="badge" :class="tenant?.isReadOnly ? 'bg-danger' : 'bg-primary'">{{ tenant?.status }}</span>
                    </div>
                    <div class="text-muted small">Tarif mensuel tout inclus : {{ fmt(monthlyPrice) }} FCFA / mois</div>
                    <div v-if="referralDiscount > 0" class="text-success small fw-semibold mt-1">
                        <i class="bi bi-gift me-1"></i>Réduction parrainage (1<sup>re</sup> facture) : -{{ fmt(referralDiscount) }} FCFA → Total à payer : {{ fmt(effectivePrice ?? Math.max(0, monthlyPrice - referralDiscount)) }} FCFA
                    </div>
                    <div v-if="tenant?.subscriptionEndsAt" class="text-muted small mt-1">
                        Échéance actuelle : <strong>{{ new Date(tenant.subscriptionEndsAt).toLocaleDateString('fr-FR') }}</strong>
                    </div>
                    <div v-if="tenant?.status === 'trialing' && !tenant?.isInTrialGrace" class="text-success small mt-1">
                        <i class="bi bi-info-circle me-1"></i>Vous pouvez souscrire dès maintenant pendant votre essai : votre mois payant démarrera à la suite de votre période d'essai.
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-primary px-4 py-2 fw-semibold" @click="payNow">
                        <i class="bi bi-credit-card me-2"></i>Payer via SaSPay (Mobile Money / Carte)
                    </button>
                    <button
                        v-if="affiliateLink"
                        type="button"
                        class="btn btn-success px-4 py-2 fw-semibold"
                        :disabled="affiliateLink.is_blocked || affiliateLink.balance < (effectivePrice ?? monthlyPrice)"
                        @click="payWithAffiliate"
                    >
                        <i class="bi bi-wallet2 me-2"></i>Payer avec mon solde d'affiliation ({{ fmt(affiliateLink.balance) }} FCFA)
                    </button>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <span class="fw-bold">Historique des souscriptions ({{ payments?.length ?? 0 }})</span>
                <div class="d-flex gap-2">
                    <a href="/abonnement/export/pdf" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Télécharger PDF
                    </a>
                    <a href="/abonnement/export/excel" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-file-earmark-excel me-1"></i>Télécharger Excel
                    </a>
                </div>
            </div>
            <div v-if="!payments?.length" class="p-4 text-center text-muted small">
                Aucune souscription enregistrée pour le moment.
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Référence</th>
                            <th>Méthode</th>
                            <th>Montant</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in payments" :key="p.id">
                            <td><code>{{ p.reference_code }}</code></td>
                            <td class="small">{{ p.payment_method || p.provider }}</td>
                            <td class="fw-semibold">{{ fmt(p.amount) }} {{ p.currency }}</td>
                            <td>
                                <span class="badge" :class="p.status === 'success' ? 'bg-success' : (p.status === 'failed' ? 'bg-danger' : 'bg-warning text-dark')">
                                    {{ p.status }}
                                </span>
                            </td>
                            <td class="small text-muted">{{ new Date(p.created_at).toLocaleString('fr-FR') }}</td>
                            <td class="text-end text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-outline-dark btn-sm me-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalSubscriptionDetail"
                                    @click="selectedPayment = p"
                                >
                                    <i class="bi bi-eye me-1"></i>Détails
                                </button>
                                <a v-if="p.status === 'success'" :href="`/paiements/${p.id}/recu-pdf`" class="btn btn-outline-primary btn-sm me-1">Reçu PDF</a>
                                <a v-if="p.status === 'success'" :href="`/paiements/${p.id}/imprimer`" target="_blank" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-printer me-1"></i>Imprimer
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Détails Souscription -->
        <div id="modalSubscriptionDetail" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div v-if="selectedPayment" class="modal-content border-0 shadow rounded-4 overflow-hidden">
                    <div class="modal-header bg-dark text-white border-0">
                        <h5 class="modal-title fw-bold">Détails de la souscription</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body p-4 vstack gap-2 small">
                        <div class="d-flex justify-content-between border-bottom pb-2">
                            <span class="text-muted">Référence</span>
                            <code class="fw-bold">{{ selectedPayment.reference_code }}</code>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-2">
                            <span class="text-muted">Méthode / Fournisseur</span>
                            <span class="fw-semibold">{{ selectedPayment.payment_method || selectedPayment.provider }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-2">
                            <span class="text-muted">Montant</span>
                            <span class="fw-bold text-primary">{{ fmt(selectedPayment.amount) }} {{ selectedPayment.currency }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-2">
                            <span class="text-muted">Statut</span>
                            <span class="badge" :class="selectedPayment.status === 'success' ? 'bg-success' : 'bg-warning text-dark'">{{ selectedPayment.status }}</span>
                        </div>
                        <div class="d-flex justify-content-between border-bottom pb-2">
                            <span class="text-muted">Date de création</span>
                            <span>{{ new Date(selectedPayment.created_at).toLocaleString('fr-FR') }}</span>
                        </div>
                        <div v-if="selectedPayment.paid_at" class="d-flex justify-content-between">
                            <span class="text-muted">Payé le</span>
                            <span>{{ new Date(selectedPayment.paid_at).toLocaleString('fr-FR') }}</span>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
