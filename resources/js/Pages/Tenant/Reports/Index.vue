<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    stats: Object,
    monthlyComparison: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
    liveCashSessions: { type: Array, default: () => [] },
    cashClosuresHistory: { type: Array, default: () => [] },
    repairOrderMargins: { type: Array, default: () => [] },
});

const selectedSession = ref(null);

const openCashForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    closure_date: new Date().toISOString().slice(0, 10),
    opening_float: 0,
    notes: '',
});

const closeCashForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    closure_date: new Date().toISOString().slice(0, 10),
    opening_float: 0,
    counted_cash: 0,
    notes: '',
});

function startOpenCash(session) {
    selectedSession.value = session;
    openCashForm.agency_id = session.agency_id;
    openCashForm.closure_date = session.closure_date;
    openCashForm.opening_float = session.opening_float || 0;
    openCashForm.notes = '';
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalOpenCash'))?.show();
}

function submitOpenCash() {
    openCashForm.post('/caisse/ouvrir', {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalOpenCash'))?.hide(),
    });
}

function startCloseCash(session) {
    selectedSession.value = session;
    closeCashForm.agency_id = session.agency_id;
    closeCashForm.closure_date = session.closure_date;
    closeCashForm.opening_float = session.opening_float || 0;
    closeCashForm.counted_cash = session.expected_cash || 0;
    closeCashForm.notes = session.notes || '';
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalCloseCashZ'))?.show();
}

function submitCloseCash() {
    closeCashForm.post('/caisse/cloture', {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalCloseCashZ'))?.hide(),
    });
}

const liveDiscrepancy = computed(() => {
    if (!selectedSession.value) return 0;
    return Number(closeCashForm.counted_cash || 0) - Number(selectedSession.value.expected_cash || 0);
});

const expenseForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    category: 'loyer',
    description: '',
    amount: 0,
    expense_date: new Date().toISOString().slice(0, 10),
});

const maxBarAmount = computed(() => {
    const values = props.monthlyComparison.flatMap((m) => [m.revenue, m.totalExpenses]);
    return Math.max(1, ...values);
});

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

function submitExpense() {
    expenseForm.post('/rapports/depenses', {
        onSuccess: () => {
            expenseForm.reset('description', 'amount');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddExpense'))?.hide();
        },
    });
}
</script>

<template>
    <Head title="Statistiques, Bénéfices & Rapports" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Statistiques, Chiffre d'Affaires &amp; Bénéfices</h1>
                <p class="text-muted small mb-0">Calcul du bénéfice net après dépenses et comparaison mensuelle</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/export-excel/rapports" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddExpense">
                    <i class="bi bi-plus-circle me-1"></i>Enregistrer une dépense
                </button>
            </div>
        </div>

        <!-- Indicateur d'alerte déficit / comparaison mensuelle -->
        <div
            v-if="stats.isDeficitAlert"
            class="alert alert-danger border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4"
        >
            <div>
                <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                <strong>Alerte Déficit / Baisse de Chiffre d'Affaires :</strong>
                <span v-if="stats.lastCompleted?.isDeficit">
                    Le mois passé ({{ stats.lastCompleted?.label }}) présente un déficit net de <strong>{{ fmt(stats.lastCompleted?.netProfit) }}</strong> après dépenses.
                </span>
                <span v-else>
                    Le chiffre d'affaires du mois passé ({{ stats.lastCompleted?.label }}) est en baisse de <strong>{{ Math.abs(stats.revenueChangePct) }}%</strong> par rapport au mois précédent.
                </span>
            </div>
            <span class="badge bg-danger">Vigilance Requise</span>
        </div>
        <div
            v-else
            class="alert alert-success border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4"
        >
            <div>
                <i class="bi bi-graph-up-arrow fs-5 me-2"></i>
                <strong>Performance Positive :</strong>
                Sur le mois passé ({{ stats.lastCompleted?.label }}), le bénéfice net après toutes dépenses est de
                <strong>{{ fmt(stats.lastCompleted?.netProfit) }}</strong>
                ({{ stats.revenueChangePct >= 0 ? '+' : '' }}{{ stats.revenueChangePct }}% de CA vs mois précédent).
            </div>
            <span class="badge bg-success">Excédentaire</span>
        </div>

        <!-- KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">CA Aujourd'hui</div>
                    <div class="h5 fw-bold text-success mb-0">{{ fmt(stats.todayRevenue) }}</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">CA Mois en cours</div>
                    <div class="h5 fw-bold text-primary mb-0">{{ fmt(stats.monthRevenue) }}</div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">Bénéfice Net ({{ stats.lastCompleted?.label }})</div>
                    <div class="h5 fw-bold mb-0" :class="stats.lastCompleted?.isDeficit ? 'text-danger' : 'text-success'">
                        {{ fmt(stats.lastCompleted?.netProfit) }}
                    </div>
                </div></div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <div class="small text-muted">Impayés Clients</div>
                    <div class="h5 fw-bold text-warning mb-0">{{ fmt(stats.unpaidTotal) }}</div>
                </div></div>
            </div>
        </div>

        <!-- Graphique comparatif visuel 6 mois -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Comparaison CA vs Dépenses (6 derniers mois)</span>
                <div class="small">
                    <span class="badge bg-primary me-2">Chiffre d'affaires</span>
                    <span class="badge bg-secondary">Dépenses totales</span>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div v-for="m in monthlyComparison" :key="m.monthKey" class="col-4 col-md-2 text-center">
                        <div class="d-flex justify-content-center align-items-end gap-1" style="height: 140px;">
                            <div
                                class="bg-primary rounded-top"
                                style="width: 20px;"
                                :style="{ height: Math.max(6, Math.round((m.revenue / maxBarAmount) * 130)) + 'px' }"
                                :title="`CA : ${fmt(m.revenue)}`"
                            ></div>
                            <div
                                class="bg-secondary rounded-top"
                                style="width: 20px;"
                                :style="{ height: Math.max(6, Math.round((m.totalExpenses / maxBarAmount) * 130)) + 'px' }"
                                :title="`Dépenses : ${fmt(m.totalExpenses)}`"
                            ></div>
                        </div>
                        <div class="small fw-semibold mt-2">{{ m.label }}</div>
                        <span
                            class="badge mt-1"
                            :class="m.isDeficit ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'"
                        >
                            {{ m.isDeficit ? 'Déficit' : 'Bénéfice' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tableau détaillé mois par mois -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Détail mensuel : Chiffre d'Affaires, Dépenses &amp; Bénéfice Net</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Mois</th>
                            <th class="text-end">Chiffre d'affaires</th>
                            <th class="text-end">Approvisionnement</th>
                            <th class="text-end">Salaires</th>
                            <th class="text-end">Autres charges</th>
                            <th class="text-end">Total Dépenses</th>
                            <th class="text-end">Bénéfice Net</th>
                            <th class="text-end">État</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in monthlyComparison" :key="m.monthKey">
                            <td class="fw-semibold">
                                {{ m.label }}
                                <span v-if="!m.isCompleted" class="badge bg-info text-dark ms-1">En cours</span>
                            </td>
                            <td class="text-end fw-bold text-primary">{{ fmt(m.revenue) }}</td>
                            <td class="text-end small">{{ fmt(m.purchaseExpenses) }}</td>
                            <td class="text-end small">{{ fmt(m.payrollExpenses) }}</td>
                            <td class="text-end small">{{ fmt(m.manualExpenses) }}</td>
                            <td class="text-end fw-semibold text-secondary">{{ fmt(m.totalExpenses) }}</td>
                            <td class="text-end fw-bold" :class="m.isDeficit ? 'text-danger' : 'text-success'">
                                {{ fmt(m.netProfit) }}
                            </td>
                            <td class="text-end">
                                <span class="badge" :class="m.isDeficit ? 'bg-danger' : 'bg-success'">
                                    {{ m.isDeficit ? 'Déficit' : 'Bénéfice' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Dernières dépenses enregistrées -->
        <div v-if="expenses.length" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Dernières dépenses / charges diverses</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Catégorie</th><th>Description</th><th class="text-end">Montant</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in expenses" :key="e.id">
                            <td class="small">{{ new Date(e.expense_date).toLocaleDateString('fr-FR') }}</td>
                            <td><span class="badge bg-light text-dark border">{{ e.category }}</span></td>
                            <td>{{ e.description }}</td>
                            <td class="text-end fw-semibold text-danger">{{ fmt(e.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddExpense" title="Enregistrer une dépense mensuelle">
            <form @submit.prevent="submitExpense">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Catégorie</label>
                        <select v-model="expenseForm.category" class="form-select">
                            <option value="loyer">Loyer atelier</option>
                            <option value="electricite_eau">Électricité / Eau / Internet</option>
                            <option value="outillage">Outillage &amp; Équipement</option>
                            <option value="transport">Transport &amp; Logistique</option>
                            <option value="autres">Autres charges</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Date</label>
                        <input v-model="expenseForm.expense_date" type="date" class="form-control" required />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <input v-model="expenseForm.description" type="text" class="form-control" placeholder="Ex: Facture ENEO / Loyer mois..." required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Montant (FCFA)</label>
                    <input v-model.number="expenseForm.amount" type="number" min="1" class="form-control" required />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="expenseForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <!-- Section Caisse Journalière Z (Ouverture / Fermeture & Détection d'écart) -->
        <div class="card border-0 shadow-sm mt-4 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-safe2-fill text-primary me-2"></i>Caisse Journalière &amp; Clôture Z par Agence</h2>
                    <p class="text-muted small mb-0">Espèces, Mobile Money, Carte, Virement, sorties espèces et détection automatique d'écart de caisse</p>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div v-for="sess in liveCashSessions" :key="sess.agency_id" class="col-md-6">
                        <div class="border rounded-3 p-3 h-100 bg-light bg-opacity-50">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold">{{ sess.agency_name }}</span>
                                <span v-if="sess.status === 'open'" class="badge bg-success">Caisse Ouverte ({{ sess.opened_at }})</span>
                                <span v-else-if="sess.status === 'closed'" class="badge bg-dark">Clôturée Z ({{ sess.closed_at }})</span>
                                <span v-else class="badge bg-secondary">Non ouverte</span>
                            </div>
                            <div class="row g-2 small mb-3">
                                <div class="col-6">Fond d'ouverture : <strong>{{ fmt(sess.opening_float) }}</strong></div>
                                <div class="col-6">Espèces encaissées : <strong class="text-success">{{ fmt(sess.cash_total) }}</strong></div>
                                <div class="col-6">Mobile Money : <strong>{{ fmt(sess.mobile_money_total) }}</strong></div>
                                <div class="col-6">Carte / Chèque : <strong>{{ fmt(sess.card_bank_total) }}</strong></div>
                                <div class="col-6">Virement : <strong>{{ fmt(sess.virement_total) }}</strong></div>
                                <div class="col-6">Dépenses jour : <strong class="text-danger">-{{ fmt(sess.expenses_cash_total) }}</strong></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top flex-wrap gap-2">
                                <div class="small">
                                    Espèces attendues : <strong>{{ fmt(sess.expected_cash) }}</strong>
                                    <span v-if="sess.status === 'closed'" class="ms-2 badge" :class="sess.cash_discrepancy === 0 ? 'bg-success' : 'bg-danger'">
                                        Écart : {{ sess.cash_discrepancy > 0 ? '+' : '' }}{{ fmt(sess.cash_discrepancy) }}
                                    </span>
                                </div>
                                <div class="d-flex gap-2">
                                    <button v-if="sess.status === 'not_opened'" type="button" class="btn btn-outline-primary btn-sm" @click="startOpenCash(sess)">
                                        <i class="bi bi-unlock me-1"></i>Ouvrir la caisse
                                    </button>
                                    <button v-if="sess.status !== 'closed'" type="button" class="btn btn-primary btn-sm" @click="startCloseCash(sess)">
                                        <i class="bi bi-lock-fill me-1"></i>Clôturer (Z)
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="cashClosuresHistory.length" class="table-responsive mt-3">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Agence</th>
                                <th class="text-end">Espèces</th>
                                <th class="text-end">Mobile Money</th>
                                <th class="text-end">Carte / Vir.</th>
                                <th class="text-end">Attendu / Compté</th>
                                <th class="text-end">Écart</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in cashClosuresHistory" :key="c.id">
                                <td>{{ String(c.closure_date || '').slice(0, 10) }}</td>
                                <td>{{ c.agency?.name }}</td>
                                <td class="text-end">{{ fmt(c.cash_total) }}</td>
                                <td class="text-end">{{ fmt(c.mobile_money_total) }}</td>
                                <td class="text-end">{{ fmt((c.card_bank_total || 0) + (c.virement_total || 0)) }}</td>
                                <td class="text-end">{{ fmt(c.expected_cash) }} / {{ fmt(c.counted_cash ?? c.expected_cash) }}</td>
                                <td class="text-end">
                                    <span class="badge" :class="(c.cash_discrepancy || 0) === 0 ? 'bg-success' : 'bg-danger'">
                                        {{ (c.cash_discrepancy || 0) > 0 ? '+' : '' }}{{ fmt(c.cash_discrepancy || 0) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Marge nette réelle par Ordre de Réparation -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-cash-coin text-success me-2"></i>Marge Nette Réelle par Ordre de Réparation</h2>
                <p class="text-muted small mb-0">CA facturé (pièces + main-d'œuvre) moins coût réel d'achat pièces sorties et sous-traitance</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>OR</th>
                                <th>Client &amp; Véhicule</th>
                                <th class="text-end">CA Facturé HT</th>
                                <th class="text-end">Coût Pièces + ST</th>
                                <th class="text-end">Marge Nette</th>
                                <th class="text-end">Taux</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="ro in repairOrderMargins" :key="ro.id">
                                <td class="fw-bold">{{ ro.order_number }}</td>
                                <td>
                                    <div>{{ ro.customer_name }}</div>
                                    <div class="text-muted" style="font-size: 0.72rem;">{{ ro.vehicle_label }}</div>
                                </td>
                                <td class="text-end">{{ fmt(ro.billed_revenue) }}</td>
                                <td class="text-end text-danger">-{{ fmt(ro.parts_cost + ro.subcontract_cost) }}</td>
                                <td class="text-end fw-bold" :class="ro.net_margin >= 0 ? 'text-success' : 'text-danger'">
                                    {{ fmt(ro.net_margin) }}
                                </td>
                                <td class="text-end">
                                    <span class="badge" :class="ro.margin_rate >= 30 ? 'bg-success' : (ro.margin_rate >= 15 ? 'bg-warning text-dark' : 'bg-danger')">
                                        {{ ro.margin_rate }}%
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="!repairOrderMargins.length">
                                <td colspan="6" class="text-center text-muted py-4">Aucun ordre de réparation facturé pour le moment.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalOpenCash" title="Ouvrir la caisse journalière">
            <form @submit.prevent="submitOpenCash">
                <div class="mb-3">
                    <label class="form-label small">Fond de caisse espèces à l'ouverture (FCFA)</label>
                    <input v-model.number="openCashForm.opening_float" type="number" min="0" class="form-control" required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Note d'ouverture (optionnel)</label>
                    <input v-model="openCashForm.notes" type="text" class="form-control" placeholder="Ex: Billetage vérifié" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="openCashForm.processing">Ouvrir la caisse</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalCloseCashZ" title="Clôture de caisse journalière (Z)">
            <form @submit.prevent="submitCloseCash">
                <div v-if="selectedSession" class="alert alert-light border small mb-3">
                    <div>Espèces attendues en tiroir : <strong>{{ fmt(selectedSession.expected_cash) }}</strong></div>
                    <div class="mt-1">
                        Écart calculé :
                        <strong :class="liveDiscrepancy === 0 ? 'text-success' : 'text-danger'">
                            {{ liveDiscrepancy > 0 ? '+' : '' }}{{ fmt(liveDiscrepancy) }}
                        </strong>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Espèces réellement comptées dans le tiroir (FCFA)</label>
                    <input v-model.number="closeCashForm.counted_cash" type="number" min="0" class="form-control" required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Observation / Justification d'écart</label>
                    <textarea v-model="closeCashForm.notes" rows="2" class="form-control" placeholder="Ex: RAS ou justification d'écart..."></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="closeCashForm.processing">Valider la clôture Z</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
