<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { nextTick, reactive, ref } from 'vue';

const props = defineProps({
    selectedDate: String,
    periodMonth: String,
    isWorkDay: Boolean,
    schedule: Object,
    attendances: Array,
    payroll: Array,
    adjustments: { type: Array, default: () => [] },
    employees: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const dateModel = ref(props.selectedDate);
const activeTab = ref('attendance');
const times = reactive({});
const payingPayslip = ref(null);

const adjustmentForm = useForm({
    employee_id: props.employees?.[0]?.id ?? '',
    type: 'avance',
    amount: 10000,
    label: '',
    effective_date: props.selectedDate,
});

const payForm = useForm({
    payment_method: 'especes',
});

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

function changeDate(newDate) {
    if (newDate) {
        router.get('/rh', { date: newDate }, { preserveState: false });
    }
}

function checkIn(attId, nowImmediate = false) {
    router.post(
        `/rh/pointages/${attId}/arrivee`,
        { time: nowImmediate ? null : times[`in_${attId}`] || null },
        { preserveScroll: true }
    );
}

function checkOut(attId, nowImmediate = false) {
    router.post(
        `/rh/pointages/${attId}/depart`,
        { time: nowImmediate ? null : times[`out_${attId}`] || null },
        { preserveScroll: true }
    );
}

function updateAttendanceStatus(attId, status) {
    router.patch(`/rh/pointages/${attId}/statut`, { status }, { preserveScroll: true });
}

function submitAdjustment() {
    adjustmentForm.post('/rh/ajustements', {
        preserveScroll: true,
        onSuccess: () => {
            adjustmentForm.reset('label');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddAdjustment'))?.hide();
        },
    });
}

async function removeAdjustment(adj) {
    const ok = await confirm({
        title: 'Supprimer cette écriture',
        message: `Supprimer « ${adj.label} » (${fmt(adj.amount)}) pour ${adj.employee_name} ?`,
        confirmLabel: 'Oui, supprimer',
        variant: 'danger',
    });
    if (!ok) return;
    router.delete(`/rh/ajustements/${adj.id}`, { preserveScroll: true });
}

async function generatePayslips() {
    const ok = await confirm({
        title: 'Générer les bulletins du mois',
        message: `Générer ou actualiser les bulletins de paie de ${props.periodMonth} pour tous les employés actifs ? (Les bulletins déjà payés ne sont pas modifiés.)`,
        confirmLabel: 'Générer les bulletins',
        variant: 'primary',
    });
    if (!ok) return;
    router.post('/rh/bulletins/generer', { period_month: props.periodMonth }, { preserveScroll: true });
}

async function openPayModal(row) {
    payingPayslip.value = row;
    payForm.payment_method = 'especes';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalPayPayslip'))?.show();
}

function submitPay() {
    if (!payingPayslip.value?.payslip_id) return;
    payForm.post(`/rh/bulletins/${payingPayslip.value.payslip_id}/payer`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalPayPayslip'))?.hide();
            payingPayslip.value = null;
        },
    });
}

async function sendPayslipWhatsapp(row) {
    if (!row.payslip_id) return;
    const ok = await confirm({
        title: 'Envoyer le bulletin sur WhatsApp',
        message: `Envoyer le récapitulatif de paie (${props.periodMonth}) à ${row.full_name} ?`,
        confirmLabel: 'Envoyer sur WhatsApp',
        variant: 'success',
    });
    if (!ok) return;
    router.post(`/rh/bulletins/${row.payslip_id}/whatsapp`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Pointage & Paie (RH)" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Pointage, Avances &amp; Bulletins de Paie</h1>
                <p class="text-muted small mb-0">
                    Horaire : <strong>{{ schedule.work_start_time }} - {{ schedule.work_end_time }}</strong> •
                    Tolérance : <strong>{{ schedule.late_tolerance_minutes }} min</strong> •
                    Retenue : <strong>{{ fmt(schedule.late_penalty_per_hour) }}/h</strong> •
                    H. Sup : <strong>{{ fmt(schedule.overtime_rate_per_hour) }}/h</strong>
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <DatePickerInput v-model="dateModel" input-class="form-control form-control-sm" @change="changeDate" />
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddAdjustment">
                    <i class="bi bi-cash-coin me-1"></i>Avance / Prime
                </button>
                <Link href="/professionnels" class="btn btn-outline-secondary btn-sm text-nowrap">
                    <i class="bi bi-person-plus me-1"></i>Équipe
                </Link>
            </div>
        </div>

        <div v-if="!isWorkDay" class="alert alert-info small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Le <strong>{{ selectedDate }}</strong> n'est pas configuré comme jour ouvré dans les <Link href="/parametres-espace">Paramètres Espace</Link>.
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'attendance' }" @click="activeTab = 'attendance'">
                    <i class="bi bi-clock-history me-1"></i>Pointage du {{ selectedDate }}
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'adjustments' }" @click="activeTab = 'adjustments'">
                    <i class="bi bi-cash-stack me-1"></i>Avances &amp; Primes ({{ adjustments.length }})
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'payroll' }" @click="activeTab = 'payroll'">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Paie &amp; Bulletins ({{ periodMonth }})
                </button>
            </li>
        </ul>

        <!-- 1. Pointage du jour -->
        <div v-if="activeTab === 'attendance'" class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Employé</th>
                            <th>Statut</th>
                            <th>Arrivée</th>
                            <th>Départ</th>
                            <th>Retard / H. Sup</th>
                            <th class="text-end">Impact</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!attendances?.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucune fiche de pointage pour cette date.</td>
                        </tr>
                        <tr v-for="att in attendances" :key="att.id">
                            <td>
                                <div class="fw-semibold">{{ att.employee_name }}</div>
                                <div class="text-muted">{{ att.role_title }} • {{ att.agency_name ?? '-' }}</div>
                            </td>
                            <td style="min-width: 125px;">
                                <select :value="att.status" class="form-select form-select-sm" @change="updateAttendanceStatus(att.id, $event.target.value)">
                                    <option value="present">Présent</option>
                                    <option value="retard">En retard</option>
                                    <option value="absent">Absent</option>
                                    <option value="conge">Congé</option>
                                    <option value="permission">Permission</option>
                                </select>
                            </td>
                            <td>
                                <span v-if="att.checked_in_at" class="badge bg-success">{{ att.checked_in_at }}</span>
                                <div v-else-if="att.status === 'present' || att.status === 'retard'" class="d-flex gap-1 align-items-center">
                                    <button type="button" class="btn btn-primary btn-sm text-nowrap" @click="checkIn(att.id, true)">Maintenant</button>
                                    <TimePickerInput v-model="times[`in_${att.id}`]" input-class="form-select form-select-sm" />
                                    <button type="button" class="btn btn-outline-primary btn-sm" @click="checkIn(att.id, false)">OK</button>
                                </div>
                                <span v-else class="text-muted">-</span>
                            </td>
                            <td>
                                <span v-if="att.checked_out_at" class="badge bg-secondary">{{ att.checked_out_at }}</span>
                                <div v-else-if="att.checked_in_at" class="d-flex gap-1 align-items-center">
                                    <button type="button" class="btn btn-outline-dark btn-sm text-nowrap" @click="checkOut(att.id, true)">Maintenant</button>
                                    <TimePickerInput v-model="times[`out_${att.id}`]" input-class="form-select form-select-sm" />
                                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="checkOut(att.id, false)">OK</button>
                                </div>
                                <span v-else class="text-muted">-</span>
                            </td>
                            <td>
                                <div v-if="att.late_minutes > 0" class="text-danger">Retard : {{ att.late_minutes }} min</div>
                                <div v-if="att.overtime_minutes > 0" class="text-success">H. Sup : +{{ att.overtime_minutes }} min</div>
                                <span v-if="!att.late_minutes && !att.overtime_minutes" class="text-muted">-</span>
                            </td>
                            <td class="text-end">
                                <div v-if="att.late_penalty > 0" class="text-danger fw-semibold">-{{ fmt(att.late_penalty) }}</div>
                                <div v-if="att.overtime_bonus > 0" class="text-success fw-semibold">+{{ fmt(att.overtime_bonus) }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Avances & Primes -->
        <div v-else-if="activeTab === 'adjustments'" class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <span class="fw-bold small">Avances sur salaire (Acomptes) &amp; Primes — {{ periodMonth }}</span>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddAdjustment">
                    + Ajouter
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Employé</th>
                            <th>Type</th>
                            <th>Libellé</th>
                            <th class="text-end">Montant</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!adjustments.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucune avance ni prime enregistrée pour {{ periodMonth }}.</td>
                        </tr>
                        <tr v-for="adj in adjustments" :key="adj.id">
                            <td>{{ adj.effective_date }}</td>
                            <td class="fw-semibold">{{ adj.employee_name }}</td>
                            <td>
                                <span class="badge" :class="adj.type === 'avance' ? 'bg-warning text-dark' : 'bg-success'">
                                    {{ adj.type === 'avance' ? 'Avance (-)' : 'Prime (+)' }}
                                </span>
                            </td>
                            <td>{{ adj.label }}</td>
                            <td class="text-end fw-bold" :class="adj.type === 'avance' ? 'text-danger' : 'text-success'">
                                {{ adj.type === 'avance' ? '-' : '+' }}{{ fmt(adj.amount) }}
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="removeAdjustment(adj)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. Paie & Bulletins -->
        <div v-else class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-bold small">Calcul &amp; Bulletins de Paie — {{ periodMonth }}</span>
                <button type="button" class="btn btn-primary btn-sm" @click="generatePayslips">
                    <i class="bi bi-calculator me-1"></i>Générer / Actualiser les bulletins
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Employé &amp; Assiduité</th>
                            <th class="text-end">Base</th>
                            <th class="text-end">Primes / H.Sup / Comm.</th>
                            <th class="text-end">Retenues / Avances</th>
                            <th class="text-end">Net à payer</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!payroll?.length">
                            <td colspan="7" class="text-center text-muted py-4">Aucun employé actif.</td>
                        </tr>
                        <tr v-for="row in payroll" :key="row.id">
                            <td>
                                <div class="fw-semibold">{{ row.full_name }}</div>
                                <div class="text-muted">
                                    {{ row.role_title }} •
                                    <span class="text-success">P:{{ row.attendance_counts?.present ?? 0 }}</span>
                                    <span class="text-warning ms-1">R:{{ row.attendance_counts?.retard ?? 0 }}</span>
                                    <span class="text-danger ms-1">A:{{ row.attendance_counts?.absent ?? 0 }}</span>
                                    <span class="text-info ms-1">C:{{ row.attendance_counts?.conge ?? 0 }}</span>
                                </div>
                            </td>
                            <td class="text-end">{{ fmt(row.base_salary) }}</td>
                            <td class="text-end text-success">+{{ fmt((row.primes_total || 0) + (row.overtime_total || 0) + (row.commissions || 0)) }}</td>
                            <td class="text-end text-danger">-{{ fmt((row.late_penalties || 0) + (row.advances_total || 0)) }}</td>
                            <td class="text-end fw-bold">{{ fmt(row.net_salary) }}</td>
                            <td>
                                <span
                                    class="badge"
                                    :class="row.payslip_status === 'paye' ? 'bg-success' : (row.payslip_status === 'valide' ? 'bg-info text-dark' : 'bg-light text-muted border')"
                                >
                                    {{ row.payslip_status === 'paye' ? `Payé (${row.payment_method})` : (row.payslip_status === 'valide' ? 'Validé' : 'Non généré') }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <template v-if="row.payslip_id">
                                    <button v-if="row.payslip_status !== 'paye'" type="button" class="btn btn-success btn-sm me-1" @click="openPayModal(row)">
                                        Payer
                                    </button>
                                    <a :href="`/rh/bulletins/${row.payslip_id}/pdf`" target="_blank" class="btn btn-outline-primary btn-sm me-1" title="Télécharger PDF">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                    <button v-if="row.phone" type="button" class="btn btn-outline-success btn-sm" title="Envoyer sur WhatsApp" @click="sendPayslipWhatsapp(row)">
                                        <i class="bi bi-whatsapp"></i>
                                    </button>
                                </template>
                                <span v-else class="text-muted fst-italic">Générez d'abord</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Avance / Prime -->
        <StaticModal id="modalAddAdjustment" title="Enregistrer une Avance ou une Prime">
            <form @submit.prevent="submitAdjustment">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Employé <span class="text-danger">*</span></label>
                    <select v-model="adjustmentForm.employee_id" class="form-select" required>
                        <option value="">-- Choisir --</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }} ({{ e.role_title }})</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Type</label>
                        <select v-model="adjustmentForm.type" class="form-select" required>
                            <option value="avance">Avance sur salaire (-)</option>
                            <option value="prime">Prime / Bonus (+)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Montant (FCFA) <span class="text-danger">*</span></label>
                        <input v-model="adjustmentForm.amount" type="number" min="100" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Motif / Libellé <span class="text-danger">*</span></label>
                        <input v-model="adjustmentForm.label" type="text" class="form-control" placeholder="Ex: Acompte mi-mois / Prime transport" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Date effective</label>
                        <DatePickerInput v-model="adjustmentForm.effective_date" required />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="adjustmentForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Payer Bulletin -->
        <StaticModal id="modalPayPayslip" title="Valider le paiement du salaire">
            <form v-if="payingPayslip" @submit.prevent="submitPay">
                <p class="small">
                    Salarié : <strong>{{ payingPayslip.full_name }}</strong><br />
                    Net à payer : <strong class="text-success">{{ fmt(payingPayslip.net_salary) }}</strong>
                </p>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Mode de paiement</label>
                    <select v-model="payForm.payment_method" class="form-select" required>
                        <option value="especes">Espèces (Caisse)</option>
                        <option value="mobile_money">Mobile Money (OM / MoMo)</option>
                        <option value="virement">Virement bancaire</option>
                        <option value="cheque">Chèque</option>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success" :disabled="payForm.processing">Confirmer le paiement</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
