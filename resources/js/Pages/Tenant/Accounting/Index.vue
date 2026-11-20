<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    balance: Array,
    incomeStatement: Object,
    vatSummary: Object,
    entries: Array,
    fiscalYears: Array,
    accounts: Array,
    journals: Array,
    agencies: Array,
});

const entryForm = useForm({
    journal_code: 'OD',
    entry_date: new Date().toISOString().slice(0, 10),
    label: '',
    agency_id: props.agencies?.[0]?.id ?? '',
    lines: [
        { account_code: '571', debit: 0, credit: 0 },
        { account_code: '706', debit: 0, credit: 0 },
    ],
});

const totalDebit = computed(() => entryForm.lines.reduce((a, l) => a + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => entryForm.lines.reduce((a, l) => a + (Number(l.credit) || 0), 0));
const isBalanced = computed(() => totalDebit.value > 0 && totalDebit.value === totalCredit.value);

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

function addLine() {
    entryForm.lines.push({ account_code: '411', debit: 0, credit: 0 });
}

function removeLine(idx) {
    if (entryForm.lines.length > 2) {
        entryForm.lines.splice(idx, 1);
    }
}

function submitEntry() {
    entryForm.post('/comptabilite/ecritures', {
        onSuccess: () => {
            entryForm.reset('label');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddEntry'))?.hide();
        },
    });
}

const { askConfirm } = useConfirm();

function reverseEntry(id) {
    askConfirm({
        title: 'Contre-passer l\'écriture',
        message: 'Voulez-vous vraiment contre-passer cette écriture comptable ? Une écriture d\'annulation sera générée.',
        confirmLabel: 'Contre-passer',
        confirmClass: 'btn-warning text-dark',
        onConfirm: () => router.post(`/comptabilite/ecritures/${id}/contre-passer`),
    });
}

function closeFy(id) {
    askConfirm({
        title: 'Clôturer l\'exercice comptable',
        message: 'Attention : voulez-vous vraiment clôturer cet exercice comptable ? Aucune nouvelle écriture ne pourra plus y être ajoutée.',
        confirmLabel: 'Clôturer définitivement',
        confirmClass: 'btn-danger',
        onConfirm: () => router.post(`/comptabilite/exercices/${id}/cloturer`),
    });
}
</script>

<template>
    <Head title="Comptabilité SYSCOHADA" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Comptabilité SYSCOHADA révisée</h1>
                <p class="text-muted small mb-0">Écritures automatiques et manuelles équilibrées, grand livre, balance et clôture</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/comptabilite/balance-pdf" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-file-pdf me-1"></i>Balance PDF
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddEntry">
                    <i class="bi bi-journal-plus me-1"></i>Nouvelle écriture
                </button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <div class="small text-muted">Produits (Classe 7)</div>
                    <div class="h5 fw-bold text-success mb-0">{{ fmt(incomeStatement?.revenues) }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <div class="small text-muted">Résultat Net</div>
                    <div class="h5 fw-bold mb-0">{{ fmt(incomeStatement?.net_result) }}</div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm"><div class="card-body">
                    <div class="small text-muted">TVA Nette Due</div>
                    <div class="h5 fw-bold text-primary mb-0">{{ fmt(vatSummary?.vat_due) }}</div>
                </div></div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3">Balance générale</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Compte</th><th>Intitulé</th><th class="text-end">Débit</th><th class="text-end">Crédit</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in balance" :key="row.code">
                                    <td class="fw-bold">{{ row.code }}</td>
                                    <td>{{ row.name }}</td>
                                    <td class="text-end">{{ fmt(row.total_debit) }}</td>
                                    <td class="text-end">{{ fmt(row.total_credit) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3">Journal des écritures récentes</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Pièce</th><th>Libellé</th><th>Statut</th><th class="text-end">Action</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in entries" :key="e.id">
                                    <td class="fw-bold">{{ e.entry_number }}</td>
                                    <td class="small">{{ e.label }}</td>
                                    <td><span class="badge" :class="e.status === 'validee' ? 'bg-success' : 'bg-secondary'">{{ e.status }}</span></td>
                                    <td class="text-end">
                                        <button v-if="e.status === 'validee'" type="button" class="btn btn-outline-danger btn-sm" @click="reverseEntry(e.id)">Contre-passer</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Exercices comptables</div>
                    <div class="card-body">
                        <div v-for="fy in fiscalYears" :key="fy.id" class="d-flex justify-content-between align-items-center mb-2">
                            <span>Exercice {{ fy.label }} <span class="badge bg-info text-dark ms-1">{{ fy.status }}</span></span>
                            <button v-if="fy.status === 'ouvert'" type="button" class="btn btn-outline-dark btn-sm" @click="closeFy(fy.id)">Clôturer</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddEntry" title="Saisir une écriture SYSCOHADA" size="modal-lg">
            <form @submit.prevent="submitEntry">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small">Journal</label>
                        <select v-model="entryForm.journal_code" class="form-select">
                            <option v-for="j in journals" :key="j.code" :value="j.code">{{ j.code }} - {{ j.name }}</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Date</label>
                        <DatePickerInput v-model="entryForm.entry_date" required />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Libellé</label>
                    <input v-model="entryForm.label" type="text" class="form-control" required />
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="form-label small mb-0">Lignes (Débit = Crédit)</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="addLine">+ Ligne</button>
                </div>

                <div v-for="(line, idx) in entryForm.lines" :key="idx" class="row g-2 mb-2 align-items-center">
                    <div class="col-5">
                        <select v-model="line.account_code" class="form-select form-select-sm">
                            <option v-for="acc in accounts" :key="acc.code" :value="acc.code">{{ acc.code }} - {{ acc.name }}</option>
                        </select>
                    </div>
                    <div class="col-3">
                        <input v-model.number="line.debit" type="number" min="0" class="form-control form-control-sm" placeholder="Débit" />
                    </div>
                    <div class="col-3">
                        <input v-model.number="line.credit" type="number" min="0" class="form-control form-control-sm" placeholder="Crédit" />
                    </div>
                    <div class="col-1 text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" :disabled="entryForm.lines.length <= 2" @click="removeLine(idx)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="small mb-3 fw-semibold" :class="isBalanced ? 'text-success' : 'text-danger'">
                    Débit : {{ fmt(totalDebit) }} | Crédit : {{ fmt(totalCredit) }}
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="!isBalanced || entryForm.processing">Valider l'écriture</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
