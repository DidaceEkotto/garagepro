<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    fleets: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
});

const selectedFleet = ref(null);
const configForm = useForm({
    customer_id: '',
    company_name: '',
    credit_limit: 500000,
    fleet_manager_name: '',
    fleet_notes: '',
});

const invoiceForm = useForm({
    month: new Date().toISOString().slice(0, 7),
    flat_amount: '',
    description: '',
});

async function openConfigModal(fleet = null) {
    configForm.customer_id = fleet ? fleet.id : '';
    configForm.company_name = fleet?.name ?? '';
    configForm.credit_limit = fleet?.credit_limit ?? 500000;
    configForm.fleet_manager_name = fleet?.fleet_manager_name ?? '';
    configForm.fleet_notes = fleet?.fleet_notes ?? '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalFleetConfig'))?.show();
}

function submitConfig() {
    if (!configForm.customer_id) return;
    configForm.post(`/flottes/${configForm.customer_id}/parametres`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalFleetConfig'))?.hide();
        },
    });
}

async function openMonthlyInvoiceModal(fleet) {
    selectedFleet.value = fleet;
    invoiceForm.month = new Date().toISOString().slice(0, 7);
    invoiceForm.flat_amount = '';
    invoiceForm.description = '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalFleetInvoice'))?.show();
}

function submitMonthlyInvoice() {
    if (!selectedFleet.value) return;
    invoiceForm.post(`/flottes/${selectedFleet.value.id}/facture-mensuelle`, {
        preserveScroll: true,
        onSuccess: () => {
            window.bootstrap?.Modal.getInstance(document.getElementById('modalFleetInvoice'))?.hide();
        },
    });
}

function formatMoney(val) {
    return `${Number(val || 0).toLocaleString('fr-FR')} FCFA`;
}
</script>

<template>
    <Head title="Flottes Entreprises (B2B)" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Gestion des Flottes Entreprises (B2B)</h1>
                <p class="text-muted small mb-0">Plafonds d'encours, coût par véhicule et facturation mensuelle groupée</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openConfigModal(null)">
                <i class="bi bi-building-add me-1"></i>Configurer un compte Flotte
            </button>
        </div>

        <div class="row g-3">
            <div v-if="!fleets.length" class="col-12">
                <div class="card border-0 shadow-sm p-4 text-center text-muted small">
                    Aucune flotte B2B configurée. Cliquez sur « Configurer un compte Flotte » pour activer la facturation groupée pour une entreprise.
                </div>
            </div>

            <div v-for="f in fleets" :key="f.id" class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                        <div>
                            <h2 class="h6 fw-bold mb-0">{{ f.name }}</h2>
                            <div class="small text-muted">
                                Responsable : {{ f.fleet_manager_name || f.contact_name }} • Tél : {{ f.phone }} • {{ f.vehicles_count }} véhicule(s)
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge bg-light text-dark border">Encours : {{ formatMoney(f.unpaid_total) }} / {{ formatMoney(f.credit_limit) }}</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="openConfigModal(f)">Paramètres</button>
                            <button type="button" class="btn btn-success btn-sm" @click="openMonthlyInvoiceModal(f)">
                                <i class="bi bi-receipt me-1"></i>Facture mensuelle groupée
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Immatriculation</th>
                                        <th>Marque / Modèle</th>
                                        <th>Kilométrage</th>
                                        <th class="text-end">Coût cumulé facturé</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!f.vehicles?.length">
                                        <td colspan="4" class="text-center text-muted py-2">Aucun véhicule rattaché.</td>
                                    </tr>
                                    <tr v-for="v in f.vehicles" :key="v.id">
                                        <td class="fw-bold">{{ v.license_plate }}</td>
                                        <td>{{ v.brand }} {{ v.model }}</td>
                                        <td>{{ v.mileage ? `${v.mileage} km` : '-' }}</td>
                                        <td class="text-end fw-semibold">{{ formatMoney(v.total_cost) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalFleetConfig" title="Configurer une flotte entreprise (B2B)">
            <form @submit.prevent="submitConfig">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client / Entreprise <span class="text-danger">*</span></label>
                    <select v-model="configForm.customer_id" class="form-select" required>
                        <option value="">-- Choisir un client --</option>
                        <option v-for="c in allCustomers" :key="c.id" :value="c.id">{{ c.company_name || c.name }} ({{ c.phone }})</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Raison sociale</label>
                        <input v-model="configForm.company_name" type="text" class="form-control" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Plafond d'encours (FCFA)</label>
                        <input v-model="configForm.credit_limit" type="number" min="0" class="form-control" required />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Responsable de flotte</label>
                    <input v-model="configForm.fleet_manager_name" type="text" class="form-control" />
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Notes contrat</label>
                    <textarea v-model="configForm.fleet_notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="configForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalFleetInvoice" title="Générer une facture mensuelle groupée">
            <form v-if="selectedFleet" @submit.prevent="submitMonthlyInvoice">
                <p class="small text-muted">
                    Flotte : <strong>{{ selectedFleet.name }}</strong>. Regroupe automatiquement les OR non encore facturés.
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Mois concerné</label>
                    <input v-model="invoiceForm.month" type="month" class="form-control" required />
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Montant forfaitaire si aucun OR chiffré (facultatif)</label>
                    <input v-model="invoiceForm.flat_amount" type="number" min="0" class="form-control" placeholder="Ex: 150000" />
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Libellé</label>
                    <input v-model="invoiceForm.description" type="text" class="form-control" placeholder="Entretien mensuel flotte" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success" :disabled="invoiceForm.processing">Générer la facture</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
