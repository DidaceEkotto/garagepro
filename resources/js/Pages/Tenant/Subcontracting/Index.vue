<script setup>
import PhoneInput from '@/Components/PhoneInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    interventions: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const typeFilter = ref('tous');

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    repair_order_id: '',
    vehicle_id: '',
    customer_id: '',
    type: 'sous_traitance',
    provider_name: '',
    provider_phone: '',
    description: '',
    location_address: '',
    cost_amount: 0,
    billed_amount: 0,
});

const filtered = computed(() => {
    if (typeFilter.value === 'tous') return props.interventions;
    return props.interventions.filter((i) => i.type === typeFilter.value);
});

function submit() {
    form.post('/sous-traitance', {
        onSuccess: () => {
            form.reset('repair_order_id', 'vehicle_id', 'customer_id', 'provider_name', 'provider_phone', 'description', 'location_address', 'cost_amount', 'billed_amount');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewSubcontract'))?.hide();
        },
    });
}

function updateStatus(id, status) {
    router.patch(`/sous-traitance/${id}/statut`, { status }, { preserveScroll: true });
}

async function removeIntervention(item) {
    const ok = await confirm({
        title: 'Supprimer cette intervention',
        message: `Supprimer l'intervention « ${item.provider_name} » ?`,
        confirmLabel: 'Oui, supprimer',
        variant: 'danger',
    });
    if (!ok) return;
    router.delete(`/sous-traitance/${item.id}`, { preserveScroll: true });
}

function formatMoney(v) {
    return `${Number(v || 0).toLocaleString('fr-FR')} FCFA`;
}
</script>

<template>
    <Head title="Sous-traitance &amp; Dépannage" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Sous-traitance, Dépannage &amp; Remorquage</h1>
                <p class="text-muted small mb-0">Suivi des prestataires externes, dépannages hors atelier, coûts et marges refacturées</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewSubcontract">
                <i class="bi bi-plus-circle me-1"></i>Nouvelle intervention
            </button>
        </div>

        <div class="btn-group btn-group-sm mb-3">
            <button type="button" class="btn" :class="typeFilter === 'tous' ? 'btn-primary' : 'btn-outline-secondary'" @click="typeFilter = 'tous'">Tous</button>
            <button type="button" class="btn" :class="typeFilter === 'sous_traitance' ? 'btn-primary' : 'btn-outline-secondary'" @click="typeFilter = 'sous_traitance'">Sous-traitance</button>
            <button type="button" class="btn" :class="typeFilter === 'depannage' ? 'btn-primary' : 'btn-outline-secondary'" @click="typeFilter = 'depannage'">Dépannage</button>
            <button type="button" class="btn" :class="typeFilter === 'remorquage' ? 'btn-primary' : 'btn-outline-secondary'" @click="typeFilter = 'remorquage'">Remorquage</button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Type / Prestataire</th>
                            <th>Description / Lieu</th>
                            <th>Véhicule / OR</th>
                            <th>Coût / Facturé / Marge</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!filtered.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucune intervention enregistrée.</td>
                        </tr>
                        <tr v-for="i in filtered" :key="i.id">
                            <td>
                                <span class="badge bg-info-subtle text-dark border mb-1">{{ i.type }}</span>
                                <div class="fw-bold">{{ i.provider_name }}</div>
                                <div class="text-muted">{{ i.provider_phone || '-' }}</div>
                            </td>
                            <td>
                                <div>{{ i.description }}</div>
                                <div v-if="i.location_address" class="text-muted"><i class="bi bi-geo-alt me-1"></i>{{ i.location_address }}</div>
                            </td>
                            <td>
                                <div>{{ i.vehicle?.license_plate ?? '-' }}</div>
                                <div class="text-muted">{{ i.repair_order?.order_number ?? '' }}</div>
                            </td>
                            <td>
                                <div>Coût : {{ formatMoney(i.cost_amount) }}</div>
                                <div>Facturé : <strong>{{ formatMoney(i.billed_amount) }}</strong></div>
                                <div class="text-success fw-semibold">Marge : {{ formatMoney((i.billed_amount || 0) - (i.cost_amount || 0)) }}</div>
                            </td>
                            <td>
                                <select :value="i.status" class="form-select form-select-sm" @change="updateStatus(i.id, $event.target.value)">
                                    <option value="planifie">Planifié</option>
                                    <option value="en_cours">En cours</option>
                                    <option value="termine">Terminé</option>
                                    <option value="facture">Facturé</option>
                                </select>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="removeIntervention(i)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalNewSubcontract" title="Nouvelle intervention externe / dépannage" size="modal-lg">
            <form @submit.prevent="submit">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Type <span class="text-danger">*</span></label>
                        <select v-model="form.type" class="form-select" required>
                            <option value="sous_traitance">Sous-traitance (Peinture, Clim...)</option>
                            <option value="depannage">Dépannage sur place</option>
                            <option value="remorquage">Remorquage</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Prestataire / Dépanneur <span class="text-danger">*</span></label>
                        <input v-model="form.provider_name" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Téléphone prestataire</label>
                        <PhoneInput v-model="form.provider_phone" :is-invalid="!!form.errors.provider_phone" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Client (facultatif)</label>
                        <select v-model="form.customer_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Véhicule (facultatif)</label>
                        <select v-model="form.vehicle_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.license_plate }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">OR lié (facultatif)</label>
                        <select v-model="form.repair_order_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }}</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Description de la prestation <span class="text-danger">*</span></label>
                    <textarea v-model="form.description" rows="2" class="form-control" required></textarea>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lieu / Zone (si dépannage)</label>
                        <input v-model="form.location_address" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Coût prestataire (FCFA)</label>
                        <input v-model="form.cost_amount" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Montant refacturé client (FCFA)</label>
                        <input v-model="form.billed_amount" type="number" min="0" class="form-control" required />
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
