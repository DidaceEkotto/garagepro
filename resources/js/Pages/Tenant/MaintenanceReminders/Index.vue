<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    vehicles: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const searchQuery = ref('');
const editingVehicle = ref(null);

const form = useForm({
    next_service_date: '',
    next_service_mileage: '',
    technical_inspection_expires_at: '',
    insurance_expires_at: '',
});

const filteredVehicles = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.vehicles;
    return props.vehicles.filter((v) =>
        [v.license_plate, v.brand, v.model, v.customer?.name, v.customer?.phone]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(q)
    );
});

function dateBadge(dateStr) {
    if (!dateStr) return { cls: 'bg-light text-muted border', label: 'Non défini' };
    const diffDays = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
    if (diffDays < 0) return { cls: 'bg-danger text-white', label: `${dateStr} (Expiré)` };
    if (diffDays <= 15) return { cls: 'bg-warning text-dark', label: `${dateStr} (Sous ${diffDays}j)` };
    return { cls: 'bg-success-subtle text-success border border-success-subtle', label: dateStr };
}

async function openEditModal(v) {
    editingVehicle.value = v;
    form.next_service_date = v.next_service_date ?? '';
    form.next_service_mileage = v.next_service_mileage ?? '';
    form.technical_inspection_expires_at = v.technical_inspection_expires_at ?? '';
    form.insurance_expires_at = v.insurance_expires_at ?? '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditMaintenanceDates'))?.show();
}

function submitDates() {
    if (!editingVehicle.value) return;
    form.patch(`/rappels-entretien/${editingVehicle.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalEditMaintenanceDates'))?.hide();
        },
    });
}

async function sendWhatsapp(v) {
    const ok = await confirm({
        title: 'Envoyer un rappel périodique WhatsApp',
        message: `Envoyer un rappel d'entretien / visite technique / assurance pour ${v.license_plate} à ${v.customer?.name ?? 'ce client'} ?`,
        confirmLabel: 'Envoyer sur WhatsApp',
        variant: 'success',
    });
    if (!ok) return;
    router.post(`/rappels-entretien/${v.id}/whatsapp`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Rappels Entretien & Documents" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Rappels Périodiques (Vidange, Visite Technique, Assurance)</h1>
                <p class="text-muted small mb-0">Relances automatiques WhatsApp sous 15 jours avant échéance et relance manuelle en 1 clic</p>
            </div>
            <div class="input-group input-group-sm" style="max-width: 280px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input v-model="searchQuery" type="text" class="form-control" placeholder="Plaque, client..." />
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Véhicule / Client</th>
                            <th>Prochaine Vidange</th>
                            <th>Visite Technique</th>
                            <th>Assurance</th>
                            <th>Dernier Rappel</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!filteredVehicles.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucun véhicule trouvé.</td>
                        </tr>
                        <tr v-for="v in filteredVehicles" :key="v.id">
                            <td>
                                <div class="fw-bold">{{ v.license_plate }} <span class="fw-normal text-muted">{{ v.brand }} {{ v.model }}</span></div>
                                <div class="text-muted">{{ v.customer?.name ?? '-' }} ({{ v.customer?.phone ?? 'sans tél' }})</div>
                            </td>
                            <td>
                                <span class="badge" :class="dateBadge(v.next_service_date).cls">{{ dateBadge(v.next_service_date).label }}</span>
                                <div v-if="v.next_service_mileage" class="text-muted mt-1">{{ v.next_service_mileage }} km</div>
                            </td>
                            <td>
                                <span class="badge" :class="dateBadge(v.technical_inspection_expires_at).cls">{{ dateBadge(v.technical_inspection_expires_at).label }}</span>
                            </td>
                            <td>
                                <span class="badge" :class="dateBadge(v.insurance_expires_at).cls">{{ dateBadge(v.insurance_expires_at).label }}</span>
                            </td>
                            <td class="text-muted">{{ v.maintenance_reminder_sent_at ?? '-' }}</td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="openEditModal(v)">
                                    <i class="bi bi-calendar-event me-1"></i>Échéances
                                </button>
                                <button v-if="v.customer?.phone" type="button" class="btn btn-outline-success btn-sm" @click="sendWhatsapp(v)">
                                    <i class="bi bi-whatsapp me-1"></i>Relancer
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalEditMaintenanceDates" title="Configurer les échéances du véhicule">
            <form v-if="editingVehicle" @submit.prevent="submitDates">
                <p class="small text-muted mb-3">Véhicule : <strong>{{ editingVehicle.license_plate }}</strong></p>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Prochaine vidange (date)</label>
                        <DatePickerInput v-model="form.next_service_date" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Prochaine vidange (km)</label>
                        <input v-model="form.next_service_mileage" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Expiration Visite Technique</label>
                        <DatePickerInput v-model="form.technical_inspection_expires_at" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Expiration Assurance</label>
                        <DatePickerInput v-model="form.insurance_expires_at" />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
