<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    invoices: Object,
    agencies: Array,
    repairOrders: Array,
});

const payingInvoice = ref(null);

const invoiceForm = useForm({
    repair_order_id: props.repairOrders?.[0]?.id ?? '',
    type: 'facture',
    deposit_deducted: 0,
    discount_amount: 0,
    items: [
        { type: 'main_oeuvre', description: 'Prestation atelier', quantity: 1, unit_price: 50000 },
    ],
});

const payForm = useForm({
    payment_method: 'especes',
    amount: 0,
});

const closureForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    closure_date: new Date().toISOString().slice(0, 10),
    cash_total: 0,
    mobile_money_total: 0,
    card_bank_total: 0,
});

function createInvoice() {
    if (!invoiceForm.repair_order_id) return;
    invoiceForm.post(`/ordres-reparation/${invoiceForm.repair_order_id}/facturer`, {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalAddInvoice'))?.hide(),
    });
}

function openPay(inv) {
    payingInvoice.value = inv;
    payForm.amount = Math.max(0, inv.total_ttc - inv.deposit_deducted - inv.paid_amount);
}

function submitPayment() {
    if (!payingInvoice.value) return;
    payForm.post(`/factures/${payingInvoice.value.id}/payer`, {
        onSuccess: () => (payingInvoice.value = null),
    });
}

const { askConfirm } = useConfirm();

function createCreditNote(id) {
    askConfirm({
        title: 'Émettre un avoir',
        message: 'Voulez-vous vraiment émettre un avoir pour annuler comptablement cette facture ?',
        confirmLabel: 'Émettre l\'avoir',
        confirmClass: 'btn-danger',
        onConfirm: () => router.post(`/factures/${id}/avoir`),
    });
}

function remindInvoice(id) {
    askConfirm({
        title: 'Relancer le client',
        message: 'Voulez-vous envoyer une relance par e-mail au client pour cette facture ?',
        confirmLabel: 'Envoyer la relance',
        confirmClass: 'btn-primary',
        onConfirm: () => router.post(`/factures/${id}/relancer`),
    });
}

function submitClosure() {
    closureForm.post('/caisse/cloture', {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalClosure'))?.hide(),
    });
}
</script>

<template>
    <Head title="Facturation & Caisse" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Facturation, Encaissements &amp; Caisse</h1>
                <p class="text-muted small mb-0">Factures séquentielles, paiements Mobile Money/Espèces/SaSPay, avoirs et clôture</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/export-excel/factures" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalClosure">
                    <i class="bi bi-safe me-1"></i>Clôture caisse
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddInvoice">
                    <i class="bi bi-receipt me-1"></i>Facturer un OR
                </button>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Numéro</th>
                                <th>Client</th>
                                <th>Statut</th>
                                <th class="text-end">Total TTC / Payé</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="inv in invoices.data" :key="inv.id">
                                <td>
                                    <div class="fw-bold">{{ inv.invoice_number }}</div>
                                    <div class="small text-muted">{{ inv.type }}</div>
                                </td>
                                <td>{{ inv.customer?.name }}</td>
                                <td><span class="badge bg-info text-dark">{{ inv.status }}</span></td>
                                <td class="text-end small">
                                    <div class="fw-bold">{{ new Intl.NumberFormat('fr-FR').format(inv.total_ttc) }} FCFA</div>
                                    <div class="text-success">Payé : {{ new Intl.NumberFormat('fr-FR').format(inv.paid_amount) }} FCFA</div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button v-if="inv.type === 'facture' && inv.status !== 'payee'" type="button" class="btn btn-outline-success btn-sm me-1" @click="openPay(inv)">Encaisser</button>
                                    <button v-if="inv.type === 'facture' && inv.status !== 'payee' && inv.customer?.email" type="button" class="btn btn-outline-info btn-sm me-1" @click="remindInvoice(inv.id)">
                                        <i class="bi bi-envelope-paper me-1"></i>Relancer
                                    </button>
                                    <button v-if="inv.type === 'facture'" type="button" class="btn btn-outline-warning btn-sm me-1" @click="createCreditNote(inv.id)">Avoir</button>
                                    <a v-if="inv.payments?.length" :href="`/paiements/${inv.payments[0].id}/recu-pdf`" class="btn btn-outline-primary btn-sm me-1">Reçu PDF</a>
                                    <a v-if="inv.payments?.length" :href="`/paiements/${inv.payments[0].id}/imprimer`" target="_blank" class="btn btn-outline-secondary btn-sm me-1">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <a :href="`/factures/${inv.id}/pdf`" class="btn btn-outline-dark btn-sm">PDF</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="payingInvoice" class="card border-0 shadow-sm border-start border-4 border-success">
            <div class="card-header bg-white fw-bold py-3">Encaisser sur {{ payingInvoice.invoice_number }}</div>
            <div class="card-body p-4">
                <form class="row g-3 align-items-end" @submit.prevent="submitPayment">
                    <div class="col-md-5">
                        <label class="form-label small">Mode de règlement</label>
                        <select v-model="payForm.payment_method" class="form-select">
                            <option value="especes">Espèces</option>
                            <option value="mtn_momo">MTN Mobile Money</option>
                            <option value="orange_money">Orange Money</option>
                            <option value="saspay_checkout">SaSPay en ligne</option>
                            <option value="virement">Virement bancaire</option>
                            <option value="cheque">Chèque</option>
                            <option value="carte">Carte bancaire</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Montant (FCFA)</label>
                        <input v-model.number="payForm.amount" type="number" min="100" class="form-control" required />
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-success w-100" :disabled="payForm.processing">Valider</button>
                    </div>
                </form>
            </div>
        </div>

        <StaticModal id="modalAddInvoice" title="Facturer un Ordre de Réparation">
            <form @submit.prevent="createInvoice">
                <div class="mb-3">
                    <label class="form-label small">Ordre de réparation</label>
                    <select v-model="invoiceForm.repair_order_id" class="form-select" required>
                        <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }} — {{ ro.customer?.name }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Désignation</label>
                    <input v-model="invoiceForm.items[0].description" type="text" class="form-control" required />
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="form-label small">Montant HT (FCFA)</label>
                        <input v-model.number="invoiceForm.items[0].unit_price" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Acompte déduit</label>
                        <input v-model.number="invoiceForm.deposit_deducted" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="invoiceForm.processing">Générer la facture</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalClosure" title="Clôture de caisse journalière">
            <form @submit.prevent="submitClosure">
                <div class="mb-3">
                    <label class="form-label small">Agence</label>
                    <select v-model="closureForm.agency_id" class="form-select" required>
                        <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Espèces (FCFA)</label>
                    <input v-model.number="closureForm.cash_total" type="number" min="0" class="form-control" required />
                </div>
                <div class="mb-4">
                    <label class="form-label small">Mobile Money (FCFA)</label>
                    <input v-model.number="closureForm.mobile_money_total" type="number" min="0" class="form-control" required />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="closureForm.processing">Clôturer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
