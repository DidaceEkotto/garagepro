<script setup>
import { useConfirm } from '@/composables/useConfirm';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { askConfirm } = useConfirm();

const props = defineProps({
    affiliateLink: { type: Object, default: null },
    referrals: { type: Array, default: () => [] },
    monthlyPrice: { type: Number, default: 10000 },
    commissionPerSignup: { type: Number, default: 2500 },
});

const copied = ref(false);
const createForm = useForm({
    code: '',
});

const progressPercent = computed(() => {
    if (!props.affiliateLink || !props.monthlyPrice) return 0;
    return Math.min(100, Math.round((props.affiliateLink.balance / props.monthlyPrice) * 100));
});

const canPaySubscription = computed(() => {
    return Boolean(
        props.affiliateLink &&
        !props.affiliateLink.is_blocked &&
        props.affiliateLink.balance >= props.monthlyPrice,
    );
});

function createLink() {
    createForm.post('/affiliation', {
        preserveScroll: true,
    });
}

function copyShareUrl() {
    if (!props.affiliateLink?.share_url) return;
    navigator.clipboard?.writeText(props.affiliateLink.share_url);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2500);
}

function payWithBalance() {
    askConfirm({
        title: 'Payer l\'abonnement avec le solde d\'affiliation',
        message: 'Voulez-vous vraiment utiliser votre solde d\'affiliation pour renouveler 1 mois d\'abonnement ?',
        confirmLabel: 'Confirmer le paiement',
        confirmClass: 'btn-success',
        onConfirm: () => router.post('/affiliation/payer-abonnement'),
    });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Affiliation &amp; Parrainage" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Programme d'Affiliation &amp; Code Promo</h1>
                <p class="text-muted small mb-0">
                    Parrainez d'autres garages et gagnez {{ fmt(commissionPerSignup) }} par inscription validée pour payer votre abonnement.
                </p>
            </div>
        </div>

        <!-- Alerte si lien bloqué par l'Admin -->
        <div v-if="affiliateLink?.is_blocked" class="alert alert-danger border-0 shadow-sm d-flex align-items-start gap-3 mb-4">
            <i class="bi bi-shield-exclamation fs-3 text-danger"></i>
            <div>
                <div class="fw-bold">Votre lien d'affiliation a été bloqué par l'administration</div>
                <div class="small">
                    Vous ne pouvez plus recevoir de commissions ni utiliser votre solde tant que ce blocage est actif.
                    <span v-if="affiliateLink.blocked_reason">Motif : <strong>{{ affiliateLink.blocked_reason }}</strong></span>
                </div>
            </div>
        </div>

        <!-- Création du lien unique si pas encore créé -->
        <div v-if="!affiliateLink" class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="badge bg-primary-subtle text-primary mb-2">1 seul lien par garage</span>
                    <h2 class="h5 fw-bold mb-2">Créez votre lien d'affiliation unique</h2>
                    <p class="text-muted small mb-0">
                        Chaque professionnel ne peut créer qu'<strong>un seul code promo / lien d'affiliation</strong>.
                        Choisissez un code personnalisé facile à retenir ou laissez le champ vide pour en générer un automatiquement.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form class="vstack gap-2" @submit.prevent="createLink">
                        <label class="form-label small fw-semibold mb-0">Code promo personnalisé (optionnel)</label>
                        <input
                            v-model="createForm.code"
                            type="text"
                            class="form-control text-uppercase"
                            placeholder="Ex: GARAGE-PRO24"
                            maxlength="24"
                        />
                        <div v-if="createForm.errors.code" class="text-danger small">{{ createForm.errors.code }}</div>
                        <button type="submit" class="btn btn-primary fw-semibold py-2" :disabled="createForm.processing">
                            <i class="bi bi-link-45deg me-1"></i>Générer mon lien d'affiliation unique
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tableau de bord du lien existant -->
        <template v-else>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                        <div class="small text-muted">Solde disponible</div>
                        <div class="h4 fw-bold text-success mb-1">{{ fmt(affiliateLink.balance) }}</div>
                        <div class="small text-muted">Utilisable pour l'abonnement</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                        <div class="small text-muted">Total gagné</div>
                        <div class="h4 fw-bold text-primary mb-1">{{ fmt(affiliateLink.total_earned) }}</div>
                        <div class="small text-muted">Depuis la création</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                        <div class="small text-muted">Garages inscrits</div>
                        <div class="h4 fw-bold text-dark mb-1">{{ referrals.length }}</div>
                        <div class="small text-muted">{{ affiliateLink.clicks_count }} clic(s) sur le lien</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                        <div class="small text-muted">Statut du lien</div>
                        <div class="mt-1">
                            <span class="badge fs-6" :class="affiliateLink.is_blocked ? 'bg-danger' : 'bg-success'">
                                {{ affiliateLink.is_blocked ? 'Bloqué' : 'Actif' }}
                            </span>
                        </div>
                        <div class="small text-muted mt-1">Code : <code>{{ affiliateLink.code }}</code></div>
                    </div>
                </div>
            </div>

            <!-- Partage & Paiement abonnement -->
            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <h2 class="h6 fw-bold mb-3"><i class="bi bi-share text-primary me-2"></i>Votre lien &amp; code promo unique</h2>
                        <div class="input-group mb-3">
                            <input type="text" class="form-control bg-light" :value="affiliateLink.share_url" readonly />
                            <button type="button" class="btn btn-primary fw-semibold" @click="copyShareUrl">
                                <i class="bi me-1" :class="copied ? 'bi-check2' : 'bi-clipboard'"></i>
                                {{ copied ? 'Copié !' : 'Copier le lien' }}
                            </button>
                        </div>
                        <div class="small text-muted">
                            Partagez ce lien ou demandez à vos confrères de saisir le code promo <strong class="text-dark">{{ affiliateLink.code }}</strong> lors de leur inscription.
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <h2 class="h6 fw-bold mb-2"><i class="bi bi-credit-card text-success me-2"></i>Payer votre abonnement</h2>
                        <div class="small text-muted mb-2">
                            Objectif abonnement mensuel : <strong>{{ fmt(monthlyPrice) }}</strong>
                        </div>
                        <div class="progress mb-2" style="height: 10px;">
                            <div class="progress-bar bg-success" :style="{ width: `${progressPercent}%` }"></div>
                        </div>
                        <div class="small text-muted mb-3">{{ progressPercent }}% atteint</div>
                        <div class="d-grid gap-2">
                            <button
                                type="button"
                                class="btn btn-success fw-semibold"
                                :disabled="!canPaySubscription"
                                @click="payWithBalance"
                            >
                                <i class="bi bi-wallet2 me-1"></i>Payer mon abonnement avec mon solde
                            </button>
                            <a href="/abonnement" class="btn btn-outline-primary btn-sm">
                                Ou payer via l'agrégateur SaSPay
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Règles du jeu & Prévention des abus -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-light">
            <h2 class="h6 fw-bold mb-3">
                <i class="bi bi-journal-check text-primary me-2"></i>Règles du jeu &amp; Conditions du programme d'affiliation
            </h2>
            <div class="row g-3 small">
                <div class="col-md-6">
                    <div class="fw-bold text-success mb-1"><i class="bi bi-check-circle-fill me-1"></i>Ce qui est autorisé</div>
                    <ul class="mb-0 ps-3 vstack gap-1 text-dark">
                        <li>Créer <strong>un seul et unique</strong> lien / code promo pour votre garage.</li>
                        <li>Partager votre lien auprès de vrais gérants de garages et ateliers mécaniques.</li>
                        <li>Cumuler {{ fmt(commissionPerSignup) }} par nouveau garage inscrit avec votre code.</li>
                        <li>Payer votre abonnement mensuel dès que votre solde atteint {{ fmt(monthlyPrice) }}.</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <div class="fw-bold text-danger mb-1"><i class="bi bi-x-octagon-fill me-1"></i>Ce qui est strictement interdit</div>
                    <ul class="mb-0 ps-3 vstack gap-1 text-dark">
                        <li>L'<strong>auto-parrainage</strong> (s'inscrire soi-même avec son propre code ou même adresse e-mail).</li>
                        <li>La création de <strong>faux comptes</strong> ou inscriptions multiples depuis la même connexion IP.</li>
                        <li>Le spam publicitaire ou l'usurpation d'identité de MboaCar.</li>
                        <li>En cas de fraude détectée, l'administration se réserve le droit de <strong>bloquer le lien</strong> et d'annuler les gains.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Liste des garages parrainés -->
        <div v-if="affiliateLink" class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white fw-bold py-3">
                Garages inscrits avec votre lien ({{ referrals.length }})
            </div>
            <div v-if="!referrals.length" class="p-4 text-center text-muted small">
                Aucun garage ne s'est encore inscrit avec votre code promo.
            </div>
            <div v-else class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Garage</th>
                            <th>Gérant / Contact</th>
                            <th>Localisation</th>
                            <th>Statut</th>
                            <th>Commission</th>
                            <th>Date d'inscription</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="refItem in referrals" :key="refItem.id">
                            <td class="fw-semibold">{{ refItem.referred_tenant?.name || 'Garage' }}</td>
                            <td class="small">
                                <div class="fw-medium">{{ refItem.referred_user?.name || '-' }}</div>
                                <div class="text-muted">{{ refItem.referred_user?.email || refItem.referred_tenant?.email }} {{ refItem.referred_user?.phone ? `• ${refItem.referred_user.phone}` : '' }}</div>
                            </td>
                            <td class="small text-muted">{{ refItem.referred_tenant?.city || '-' }} ({{ refItem.referred_tenant?.country || '-' }})</td>
                            <td>
                                <span class="badge" :class="refItem.status === 'credited' ? 'bg-success' : 'bg-danger'">
                                    {{ refItem.status === 'credited' ? 'Créditée' : 'Annulée' }}
                                </span>
                            </td>
                            <td class="fw-bold text-success">+{{ fmt(refItem.commission_amount) }}</td>
                            <td class="small text-muted">{{ new Date(refItem.created_at).toLocaleDateString('fr-FR') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </TenantLayout>
</template>
