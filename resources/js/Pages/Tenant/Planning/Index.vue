<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    selectedDate: { type: String, required: true },
    weekDays: { type: Array, default: () => [] },
    bays: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    mechanics: { type: Array, default: () => [] },
});

const bayPalette = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];

function bayColor(index) {
    return bayPalette[index % bayPalette.length];
}

const editingAppointment = ref(null);
const assignForm = useForm({
    bay_name: '',
    assigned_mechanic_id: '',
});

const unassignedList = computed(() =>
    props.appointments.filter((a) => !props.bays.includes(a.bay_name))
);

const assignedCount = computed(() =>
    props.appointments.length - unassignedList.value.length
);

const weekRangeLabel = computed(() => {
    if (!props.weekDays.length) return '';
    const first = props.weekDays[0]?.label ?? '';
    const last = props.weekDays[props.weekDays.length - 1]?.label ?? '';
    return `${first} — ${last}`;
});

function changeWeek(offsetDays) {
    const d = new Date(props.selectedDate);
    d.setDate(d.getDate() + offsetDays);
    router.get('/planning-atelier', { date: d.toISOString().slice(0, 10) }, { preserveState: true });
}

function goToday() {
    router.get('/planning-atelier', {}, { preserveState: true });
}

function slotAppointments(bay, date) {
    return props.appointments.filter((a) => a.bay_name === bay && a.date === date);
}

function statusBadgeClass(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('cours') || s.includes('progress')) return 'bg-primary-subtle text-primary border border-primary-subtle';
    if (s.includes('attente') || s.includes('wait')) return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    if (s.includes('diagnostic') || s.includes('devis')) return 'bg-info-subtle text-info-emphasis border border-info-subtle';
    return 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
}

async function openAssignModal(appt) {
    editingAppointment.value = appt;
    assignForm.bay_name = appt.bay_name === 'Non affecté' ? '' : appt.bay_name;
    assignForm.assigned_mechanic_id = appt.assigned_mechanic_id ?? '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalAssignSlot'))?.show();
}

function submitAssign() {
    if (!editingAppointment.value) return;
    assignForm.patch(`/planning-atelier/rdv/${editingAppointment.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAssignSlot'))?.hide();
        },
    });
}

function updateRoMechanic(roId, mechanicId) {
    router.patch(`/planning-atelier/or/${roId}`, { assigned_mechanic_id: mechanicId || null }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Planning Atelier" />
    <TenantLayout>
        <!-- Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h1 class="h4 fw-bold mb-0">Planning Visuel de l'Atelier</h1>
                    <span v-if="weekRangeLabel" class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                        <i class="bi bi-calendar3 me-1"></i>{{ weekRangeLabel }}
                    </span>
                </div>
                <p class="text-muted small mb-0 mt-1">Organisation hebdomadaire par pont / baie et affectation rapide des mécaniciens</p>
            </div>
            <div class="btn-group shadow-sm">
                <button type="button" class="btn btn-white border btn-sm px-3" @click="changeWeek(-7)">
                    <i class="bi bi-chevron-left me-1"></i>Semaine préc.
                </button>
                <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold" @click="goToday">
                    <i class="bi bi-crosshair me-1"></i>Aujourd'hui
                </button>
                <button type="button" class="btn btn-white border btn-sm px-3" @click="changeWeek(7)">
                    Semaine suiv.<i class="bi bi-chevron-right ms-1"></i>
                </button>
            </div>
        </div>

        <!-- KPI Summary Strip -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 planning-kpi-card">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="planning-kpi-icon bg-primary-subtle text-primary">
                            <i class="bi bi-calendar-check-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">RDV cette semaine</div>
                            <div class="h4 fw-bold mb-0">{{ appointments.length }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 planning-kpi-card">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="planning-kpi-icon bg-success-subtle text-success">
                            <i class="bi bi-grid-3x3-gap-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Affectés sur pont</div>
                            <div class="h4 fw-bold mb-0">{{ assignedCount }} <span class="fs-6 fw-normal text-muted">/ {{ bays.length }} ponts</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 planning-kpi-card">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="planning-kpi-icon" :class="unassignedList.length ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-muted'">
                            <i class="bi bi-exclamation-circle-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">RDV sans pont</div>
                            <div class="h4 fw-bold mb-0">{{ unassignedList.length }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 planning-kpi-card">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="planning-kpi-icon bg-info-subtle text-info-emphasis">
                            <i class="bi bi-wrench-adjustable-circle-fill"></i>
                        </div>
                        <div>
                            <div class="text-muted small">OR en cours</div>
                            <div class="h4 fw-bold mb-0">{{ repairOrders.length }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unassigned Appointments Banner -->
        <div v-if="unassignedList.length" class="card border-0 shadow-sm mb-4 border-start border-4 border-warning bg-warning-subtle bg-opacity-25">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    <strong class="small">Rendez-vous sans pont attribué cette semaine ({{ unassignedList.length }}) — cliquez pour affecter :</strong>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button
                        v-for="a in unassignedList"
                        :key="a.id"
                        type="button"
                        class="btn btn-white btn-sm border shadow-sm d-inline-flex align-items-center gap-2 text-start planning-chip"
                        @click="openAssignModal(a)"
                    >
                        <span class="badge bg-warning text-dark">{{ a.date }} • {{ a.time }}</span>
                        <span class="fw-semibold">{{ a.customer?.name }}</span>
                        <span class="text-muted small">{{ a.vehicle?.license_plate ?? a.reason }}</span>
                        <i class="bi bi-sliders text-primary"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Weekly Bay Schedule Grid -->
        <div class="card border-0 shadow-sm mb-4 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div class="fw-bold small d-flex align-items-center gap-2">
                    <i class="bi bi-kanban text-primary"></i>
                    <span>Grille hebdomadaire par pont / baie</span>
                </div>
                <span class="text-muted" style="font-size: 0.78rem;">
                    <i class="bi bi-hand-index-thumb me-1"></i>Cliquez sur un créneau pour modifier le pont ou le mécanicien
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-top planning-grid-table">
                    <thead>
                        <tr>
                            <th class="bg-light text-uppercase text-muted small fw-bold py-2 px-2" style="width: 108px;">
                                Pont
                            </th>
                            <th
                                v-for="day in weekDays"
                                :key="day.date"
                                class="py-2 px-1 text-center"
                                :class="day.isToday ? 'planning-today-th' : 'bg-light'"
                            >
                                <div class="d-flex flex-column align-items-center justify-content-center gap-1">
                                    <span class="fw-bold" style="font-size: 0.78rem;">{{ day.label }}</span>
                                    <span v-if="day.isToday" class="badge rounded-pill bg-primary" style="font-size: 0.62rem;">Aujourd'hui</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(bay, bayIdx) in bays" :key="bay">
                            <td class="bg-light bg-opacity-50 p-2 align-middle">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="planning-bay-dot" :style="{ backgroundColor: bayColor(bayIdx) }"></span>
                                    <span class="fw-bold text-dark text-truncate" style="font-size: 0.78rem;" :title="bay">{{ bay }}</span>
                                </div>
                            </td>
                            <td
                                v-for="day in weekDays"
                                :key="day.date"
                                class="p-1"
                                :class="{ 'planning-today-td': day.isToday }"
                            >
                                <div v-if="slotAppointments(bay, day.date).length" class="d-flex flex-column gap-1">
                                    <div
                                        v-for="a in slotAppointments(bay, day.date)"
                                        :key="a.id"
                                        class="planning-appt-card"
                                        :style="{ borderLeftColor: bayColor(bayIdx) }"
                                        @click="openAssignModal(a)"
                                    >
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="badge bg-dark bg-opacity-75 text-white" style="font-size: 0.64rem;">
                                                {{ a.time }}
                                            </span>
                                            <i class="bi bi-pencil-square text-muted" style="font-size: 0.68rem;"></i>
                                        </div>
                                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.76rem;" :title="a.customer?.name">
                                            {{ a.customer?.name }}
                                        </div>
                                        <div class="text-muted text-truncate" style="font-size: 0.69rem;" :title="a.vehicle?.license_plate ?? a.reason">
                                            {{ a.vehicle?.license_plate ?? a.reason }}
                                        </div>
                                        <div v-if="a.mechanic" class="mt-1 pt-1 border-top" style="font-size: 0.67rem;">
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-medium d-block text-truncate" :title="a.mechanic.name">
                                                {{ a.mechanic.name }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="planning-empty-slot">—</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Active Repair Orders -->
        <div class="card border-0 shadow-sm overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div class="fw-bold small d-flex align-items-center gap-2">
                    <i class="bi bi-wrench-adjustable text-primary"></i>
                    <span>Ordres de réparation en cours — Affectation rapide des mécaniciens</span>
                </div>
                <span class="badge bg-light text-dark border">{{ repairOrders.length }} actif(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">N° OR</th>
                            <th>Client &amp; Véhicule</th>
                            <th>Statut</th>
                            <th class="pe-3" style="width: 260px;">Mécanicien affecté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!repairOrders.length">
                            <td colspan="4" class="text-center text-muted py-4">
                                <i class="bi bi-check-circle text-success fs-5 d-block mb-1"></i>
                                Aucun ordre de réparation actif pour le moment.
                            </td>
                        </tr>
                        <tr v-for="ro in repairOrders" :key="ro.id">
                            <td class="ps-3 fw-bold text-primary">{{ ro.order_number }}</td>
                            <td>
                                <div class="fw-semibold text-dark">{{ ro.customer?.name }}</div>
                                <div class="text-muted" style="font-size: 0.76rem;">
                                    <i class="bi bi-car-front me-1"></i>
                                    {{ ro.vehicle?.license_plate }}
                                    <span v-if="ro.vehicle?.brand"> — {{ ro.vehicle.brand }} {{ ro.vehicle.model }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-2 py-1" :class="statusBadgeClass(ro.status)">
                                    {{ ro.status }}
                                </span>
                            </td>
                            <td class="pe-3">
                                <select
                                    :value="ro.assigned_mechanic_id ?? ''"
                                    class="form-select form-select-sm"
                                    @change="updateRoMechanic(ro.id, $event.target.value)"
                                >
                                    <option value="">-- Non affecté --</option>
                                    <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Assign Slot Modal -->
        <StaticModal id="modalAssignSlot" title="Affecter un pont et un mécanicien">
            <form v-if="editingAppointment" @submit.prevent="submitAssign">
                <div class="p-3 rounded bg-light border mb-3 small">
                    <div class="fw-bold text-dark">{{ editingAppointment.customer?.name }}</div>
                    <div class="text-muted mt-1">
                        <i class="bi bi-calendar-event me-1"></i>{{ editingAppointment.date }} à {{ editingAppointment.time }}
                        <span v-if="editingAppointment.vehicle?.license_plate"> • <i class="bi bi-car-front me-1"></i>{{ editingAppointment.vehicle.license_plate }}</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Pont / Baie</label>
                    <select v-model="assignForm.bay_name" class="form-select">
                        <option value="">-- Non affecté --</option>
                        <option v-for="b in bays" :key="b" :value="b">{{ b }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Mécanicien</label>
                    <select v-model="assignForm.assigned_mechanic_id" class="form-select">
                        <option value="">-- Non affecté --</option>
                        <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="assignForm.processing">
                        <i class="bi bi-check2 me-1"></i>Enregistrer
                    </button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>

<style scoped>
.planning-kpi-card {
    border-radius: 0.75rem;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.planning-kpi-card:hover {
    transform: translateY(-2px);
}

.planning-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.planning-chip {
    background-color: #fff;
    border-radius: 0.5rem;
    transition: transform 0.12s ease, border-color 0.12s ease;
}

.planning-chip:hover {
    transform: translateY(-1px);
    border-color: #2563eb !important;
}

.planning-grid-table {
    table-layout: fixed;
    width: 100%;
    min-width: 720px;
}

.planning-grid-table th,
.planning-grid-table td {
    border-color: #e5e7eb;
    overflow: hidden;
}

.planning-today-th {
    background-color: #eff6ff !important;
    color: #1d4ed8;
    border-bottom: 2px solid #2563eb !important;
}

.planning-today-td {
    background-color: rgba(239, 246, 255, 0.45);
}

.planning-bay-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    flex-shrink: 0;
}

.planning-appt-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #2563eb;
    border-radius: 0.5rem;
    padding: 0.55rem 0.65rem;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease;
}

.planning-appt-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.09);
    border-color: #cbd5e1;
}

.planning-empty-slot {
    min-height: 54px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #cbd5e1;
    font-size: 0.85rem;
    border: 1px dashed #e2e8f0;
    border-radius: 0.45rem;
}
</style>
