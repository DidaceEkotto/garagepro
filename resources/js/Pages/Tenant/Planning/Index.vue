<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    selectedDate: { type: String, required: true },
    weekDays: { type: Array, default: () => [] },
    bays: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    mechanics: { type: Array, default: () => [] },
});

const editingAppointment = ref(null);
const assignForm = useForm({
    bay_name: '',
    assigned_mechanic_id: '',
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

function unassignedAppointments() {
    return props.appointments.filter((a) => !props.bays.includes(a.bay_name));
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Planning Visuel de l'Atelier</h1>
                <p class="text-muted small mb-0">Vue hebdomadaire par pont / baie et répartition des mécaniciens</p>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" @click="changeWeek(-7)"><i class="bi bi-chevron-left"></i> Semaine préc.</button>
                <button type="button" class="btn btn-outline-primary" @click="goToday">Aujourd'hui</button>
                <button type="button" class="btn btn-outline-secondary" @click="changeWeek(7)">Semaine suiv. <i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <div v-if="unassignedAppointments().length" class="alert alert-warning py-2 small mb-3">
            <strong>Rendez-vous sans pont attribué cette semaine :</strong>
            <span v-for="a in unassignedAppointments()" :key="a.id" class="badge bg-white text-dark border ms-2" style="cursor: pointer;" @click="openAssignModal(a)">
                {{ a.date }} {{ a.time }} — {{ a.customer?.name }} ({{ a.vehicle?.license_plate ?? a.reason }}) <i class="bi bi-pencil ms-1"></i>
            </span>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-top small">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 140px;">Pont / Poste</th>
                            <th v-for="day in weekDays" :key="day.date" :class="{ 'table-primary': day.isToday }">
                                {{ day.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="bay in bays" :key="bay">
                            <td class="fw-bold bg-light">{{ bay }}</td>
                            <td v-for="day in weekDays" :key="day.date" style="min-width: 135px;">
                                <div v-for="a in slotAppointments(bay, day.date)" :key="a.id" class="p-2 mb-1 rounded border bg-primary-subtle" style="cursor: pointer;" @click="openAssignModal(a)">
                                    <div class="fw-bold">{{ a.time }} • {{ a.customer?.name }}</div>
                                    <div class="text-muted" style="font-size: 0.72rem;">{{ a.vehicle?.license_plate ?? a.reason }}</div>
                                    <div v-if="a.mechanic" class="badge bg-white text-dark border mt-1">{{ a.mechanic.name }}</div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 fw-bold small">
                <i class="bi bi-wrench-adjustable me-1 text-primary"></i>Ordres de réparation en cours — Affectation rapide des mécaniciens
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>N° OR</th>
                            <th>Client / Véhicule</th>
                            <th>Statut</th>
                            <th>Mécanicien affecté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!repairOrders.length">
                            <td colspan="4" class="text-center text-muted py-3">Aucun ordre de réparation actif.</td>
                        </tr>
                        <tr v-for="ro in repairOrders" :key="ro.id">
                            <td class="fw-bold">{{ ro.order_number }}</td>
                            <td>{{ ro.customer?.name }} — {{ ro.vehicle?.license_plate }}</td>
                            <td><span class="badge bg-info text-dark">{{ ro.status }}</span></td>
                            <td style="max-width: 240px;">
                                <select :value="ro.assigned_mechanic_id ?? ''" class="form-select form-select-sm" @change="updateRoMechanic(ro.id, $event.target.value)">
                                    <option value="">-- Non affecté --</option>
                                    <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAssignSlot" title="Affecter un pont et un mécanicien">
            <form v-if="editingAppointment" @submit.prevent="submitAssign">
                <p class="small text-muted mb-3">
                    Client : <strong>{{ editingAppointment.customer?.name }}</strong> ({{ editingAppointment.date }} à {{ editingAppointment.time }})
                </p>
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
                    <button type="submit" class="btn btn-primary" :disabled="assignForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
