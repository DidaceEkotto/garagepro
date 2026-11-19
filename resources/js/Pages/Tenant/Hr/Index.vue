<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import TimePickerInput from '@/Components/TimePickerInput.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps({
    selectedDate: String,
    isWorkDay: Boolean,
    schedule: Object,
    attendances: Array,
    payroll: Array,
});

const dateModel = ref(props.selectedDate);
const times = reactive({});

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

function changeDate(newDate) {
    if (newDate) {
        router.get('/rh', { date: newDate }, { preserveState: false });
    }
}

function checkIn(attId) {
    router.post(`/rh/pointages/${attId}/arrivee`, {
        time: times[`in_${attId}`] || null,
    });
}

function checkOut(attId) {
    router.post(`/rh/pointages/${attId}/depart`, {
        time: times[`out_${attId}`] || null,
    });
}
</script>

<template>
    <Head title="Pointage & Paie (RH)" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Pointage &amp; Calcul de Paie</h1>
                <p class="text-muted small mb-0">
                    Horaire prévu : <strong>{{ schedule.work_start_time }} - {{ schedule.work_end_time }}</strong> •
                    Retenue retard : <strong>{{ fmt(schedule.late_penalty_per_hour) }} / h</strong>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <DatePickerInput v-model="dateModel" input-class="form-control form-control-sm" @change="changeDate" />
                <Link href="/professionnels" class="btn btn-outline-primary btn-sm text-nowrap">
                    <i class="bi bi-person-plus me-1"></i>Gérer l'équipe
                </Link>
            </div>
        </div>

        <div v-if="!isWorkDay" class="alert alert-info small mb-4">
            <i class="bi bi-info-circle me-1"></i>
            Le <strong>{{ selectedDate }}</strong> n'est pas configuré comme jour ouvré dans les <Link href="/parametres-espace">Paramètres Espace</Link>.
        </div>

        <!-- Fiche de pointage du jour (générée automatiquement) -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold py-3">
                Fiche de pointage du {{ selectedDate }}
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Employé</th>
                                <th>Agence</th>
                                <th>Arrivée</th>
                                <th>Départ</th>
                                <th>Retard</th>
                                <th class="text-end">Retenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!attendances?.length">
                                <td colspan="6" class="text-center text-muted py-4 small">
                                    Aucune fiche de pointage pour cette date.
                                </td>
                            </tr>
                            <tr v-for="att in attendances" :key="att.id">
                                <td>
                                    <div class="fw-semibold">{{ att.employee_name }}</div>
                                    <div class="small text-muted">{{ att.role_title }}</div>
                                </td>
                                <td class="small">{{ att.agency_name ?? '-' }}</td>
                                <td>
                                    <span v-if="att.checked_in_at" class="badge bg-success">{{ att.checked_in_at }}</span>
                                    <div v-else class="d-flex gap-1 align-items-center" style="max-width: 220px;">
                                        <TimePickerInput v-model="times[`in_${att.id}`]" input-class="form-select form-select-sm" />
                                        <button type="button" class="btn btn-primary btn-sm" @click="checkIn(att.id)">Arrivée</button>
                                    </div>
                                </td>
                                <td>
                                    <span v-if="att.checked_out_at" class="badge bg-secondary">{{ att.checked_out_at }}</span>
                                    <div v-else class="d-flex gap-1 align-items-center" style="max-width: 220px;">
                                        <TimePickerInput v-model="times[`out_${att.id}`]" input-class="form-select form-select-sm" />
                                        <button type="button" class="btn btn-outline-dark btn-sm" @click="checkOut(att.id)">Départ</button>
                                    </div>
                                </td>
                                <td>
                                    <span v-if="att.late_minutes > 0" class="badge bg-danger">{{ att.late_minutes }} min</span>
                                    <span v-else class="text-muted small">0 min</span>
                                </td>
                                <td class="text-end fw-semibold" :class="att.late_penalty > 0 ? 'text-danger' : 'text-muted'">
                                    {{ fmt(att.late_penalty) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Calcul automatique de la paie du mois -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold py-3">
                État de paie mensuel (avec retenues automatiques de retard)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Employé</th>
                                <th class="text-end">Salaire de base</th>
                                <th class="text-end">Commissions</th>
                                <th class="text-end">Retards cumulés</th>
                                <th class="text-end">Retenues</th>
                                <th class="text-end">Net à payer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in payroll" :key="row.id">
                                <td>
                                    <div class="fw-semibold">{{ row.full_name }}</div>
                                    <div class="small text-muted">{{ row.role_title }}</div>
                                </td>
                                <td class="text-end">{{ fmt(row.base_salary) }}</td>
                                <td class="text-end text-success">+{{ fmt(row.commissions) }}</td>
                                <td class="text-end small">{{ row.late_minutes }} min</td>
                                <td class="text-end text-danger">-{{ fmt(row.late_penalties) }}</td>
                                <td class="text-end fw-bold text-primary">{{ fmt(row.net_salary) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
