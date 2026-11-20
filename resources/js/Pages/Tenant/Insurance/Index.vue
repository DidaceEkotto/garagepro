<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    policies: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    insuranceTenants: { type: Array, default: () => [] },
    garageTenants: { type: Array, default: () => [] },
});

const activeTab = ref('claims');
const approvingClaim = ref(null);

const policyForm = useForm({
    customer_id: '',
    vehicle_id: '',
    policy_number: '',
    insurer_name: '',
    coverage_type: 'tous_risques',
    premium_amount: 0,
    deductible_amount: 0,
    starts_at: new Date().toISOString().slice(0, 10),
    expires_at: '',
    notes: '',
});

const claimForm = useForm({
    insurance_policy_id: '',
    repair_order_id: '',
    customer_id: '',
    vehicle_id: '',
    insurance_tenant_id: '',
    garage_tenant_id: '',
    incident_date: new Date().toISOString().slice(0, 10),
    description: '',
    expert_name: '',
    estimated_amount: 0,
});

const approveForm = useForm({
    approved_amount: 0,
    insurance_share_percent: 80,
    expert_name: '',
    expert_notes: '',
});

function submitPolicy() {
    policyForm.post('/assurances/polices', {
        onSuccess: () => {
            policyForm.reset('customer_id', 'vehicle_id', 'policy_number', 'insurer_name', 'premium_amount', 'deductible_amount', 'expires_at', 'notes');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPolicy'))?.hide();
        },
    });
}

function submitClaim() {
    claimForm.post('/assurances/sinistres', {
        onSuccess: () => {
            claimForm.reset('insurance_policy_id', 'repair_order_id', 'customer_id', 'vehicle_id', 'description', 'expert_name', 'estimated_amount');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewClaim'))?.hide();
        },
    });
}

async function openApproveModal(claim) {
    approvingClaim.value = claim;
    approveForm.approved_amount = claim.approved_amount || claim.estimated_amount || 0;
    approveForm.insurance_share_percent = claim.insurance_share_percent || 80;
    approveForm.expert_name = claim.expert_name || '';
    approveForm.expert_notes = claim.expert_notes || '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalApproveClaim'))?.show();
}

function submitApprove() {
    if (!approvingClaim.value) return;
    approveForm.post(`/assurances/sinistres/${approvingClaim.value.id}/approuver`, {
        preserveScroll: true,
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalApproveClaim'))?.hide(),
    });
}

function updateClaimStatus(id, status) {
    router.patch(`/assurances/sinistres/${id}/statut`, { status }, { preserveScroll: true });
}

function formatMoney(v) {
    return `${Number(v || 0).toLocaleString('fr-FR')} FCFA`;
}
</script>

<template>
    <Head title="Assurances &amp; Sinistres" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Assurances Automobile &amp; Sinistres</h1>
                <p class="text-muted small mb-0">Gestion des polices d'assurance, dossiers sinistres, accords de prise en charge et calcul de franchise</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNewPolicy">
                    <i class="bi bi-shield-plus me-1"></i>Nouvelle police
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewClaim">
                    <i class="bi bi-exclamation-diamond me-1"></i>Déclarer un sinistre
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'claims' }" @click="activeTab = 'claims'">
                    <i class="bi bi-file-earmark-medical me-1"></i>Dossiers Sinistres ({{ claims.length }})
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'policies' }" @click="activeTab = 'policies'">
                    <i class="bi bi-shield-check me-1"></i>Polices d'assurance ({{ policies.length }})
                </button>
            </li>
        </ul>

        <!-- Sinistres -->
        <div v-if="activeTab === 'claims'" class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>N° Sinistre / Date</th>
                            <th>Client / Véhicule</th>
                            <th>Montants &amp; Franchise</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!claims.length">
                            <td colspan="5" class="text-center text-muted py-4">Aucun dossier sinistre.</td>
                        </tr>
                        <tr v-for="c in claims" :key="c.id">
                            <td>
                                <div class="fw-bold">{{ c.claim_number }}</div>
                                <div class="text-muted">{{ c.incident_date }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ c.customer?.name }} ({{ c.vehicle?.license_plate }})</div>
                                <div class="text-muted text-truncate" style="max-width: 260px;">{{ c.description }}</div>
                            </td>
                            <td>
                                <div>Estimé : {{ formatMoney(c.estimated_amount) }}</div>
                                <div v-if="c.approved_amount > 0" class="text-success fw-semibold">
                                    Accord : {{ formatMoney(c.approved_amount) }} ({{ c.insurance_share_percent }}%) • Franchise : {{ formatMoney(c.client_deductible_amount) }}
                                </div>
                            </td>
                            <td>
                                <select :value="c.status" class="form-select form-select-sm" @change="updateClaimStatus(c.id, $event.target.value)">
                                    <option value="declare">Déclaré</option>
                                    <option value="expertise">En expertise</option>
                                    <option value="accord_prise_en_charge">Accord prise en charge</option>
                                    <option value="en_reparation">En réparation</option>
                                    <option value="cloture">Clôturé</option>
                                    <option value="refuse">Refusé</option>
                                </select>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-success btn-sm" @click="openApproveModal(c)">
                                    <i class="bi bi-check2-circle me-1"></i>Accord / Expertise
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Polices -->
        <div v-else class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>N° Police / Assureur</th>
                            <th>Client / Véhicule</th>
                            <th>Formule</th>
                            <th>Prime / Franchise</th>
                            <th>Validité</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!policies.length">
                            <td colspan="5" class="text-center text-muted py-4">Aucune police enregistrée.</td>
                        </tr>
                        <tr v-for="p in policies" :key="p.id">
                            <td>
                                <div class="fw-bold">{{ p.policy_number }}</div>
                                <div class="text-muted">{{ p.insurer_name || '-' }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ p.customer?.name }}</div>
                                <div class="text-muted">{{ p.vehicle?.license_plate }} ({{ p.vehicle?.brand }} {{ p.vehicle?.model }})</div>
                            </td>
                            <td><span class="badge bg-info-subtle text-dark border">{{ p.coverage_type }}</span></td>
                            <td>
                                <div>Prime : {{ formatMoney(p.premium_amount) }}</div>
                                <div class="text-muted">Franchise : {{ formatMoney(p.deductible_amount) }}</div>
                            </td>
                            <td>{{ p.starts_at }} → <strong>{{ p.expires_at }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Police -->
        <StaticModal id="modalNewPolicy" title="Enregistrer une police d'assurance" size="modal-lg">
            <form @submit.prevent="submitPolicy">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Client <span class="text-danger">*</span></label>
                        <select v-model="policyForm.customer_id" class="form-select" required>
                            <option value="">-- Choisir un client --</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.phone }})</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Véhicule <span class="text-danger">*</span></label>
                        <select v-model="policyForm.vehicle_id" class="form-select" required>
                            <option value="">-- Choisir un véhicule --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.license_plate }} ({{ v.brand }} {{ v.model }})</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">N° Police <span class="text-danger">*</span></label>
                        <input v-model="policyForm.policy_number" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Compagnie d'assurance</label>
                        <input v-model="policyForm.insurer_name" type="text" class="form-control" placeholder="Ex: AXA, Activa..." />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Formule</label>
                        <select v-model="policyForm.coverage_type" class="form-select">
                            <option value="tiers">Tiers simple</option>
                            <option value="tiers_plus">Tiers étendu</option>
                            <option value="tous_risques">Tous Risques</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Prime (FCFA)</label>
                        <input v-model="policyForm.premium_amount" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Franchise (FCFA)</label>
                        <input v-model="policyForm.deductible_amount" type="number" min="0" class="form-control" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Date d'effet</label>
                        <DatePickerInput v-model="policyForm.starts_at" required />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Expiration</label>
                        <DatePickerInput v-model="policyForm.expires_at" required />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="policyForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Sinistre -->
        <StaticModal id="modalNewClaim" title="Déclarer un dossier sinistre" size="modal-lg">
            <form @submit.prevent="submitClaim">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Client <span class="text-danger">*</span></label>
                        <select v-model="claimForm.customer_id" class="form-select" required>
                            <option value="">-- Client --</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Véhicule <span class="text-danger">*</span></label>
                        <select v-model="claimForm.vehicle_id" class="form-select" required>
                            <option value="">-- Véhicule --</option>
                            <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.license_plate }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Date du sinistre <span class="text-danger">*</span></label>
                        <DatePickerInput v-model="claimForm.incident_date" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Police liée (facultatif)</label>
                        <select v-model="claimForm.insurance_policy_id" class="form-select">
                            <option value="">-- Aucune --</option>
                            <option v-for="p in policies" :key="p.id" :value="p.id">{{ p.policy_number }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">OR lié (facultatif)</label>
                        <select v-model="claimForm.repair_order_id" class="form-select">
                            <option value="">-- Aucun --</option>
                            <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Devis estimé (FCFA)</label>
                        <input v-model="claimForm.estimated_amount" type="number" min="0" class="form-control" required />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Circonstances &amp; dommages <span class="text-danger">*</span></label>
                    <textarea v-model="claimForm.description" rows="2" class="form-control" required></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="claimForm.processing">Déclarer le sinistre</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Accord Prise en Charge -->
        <StaticModal id="modalApproveClaim" title="Accord de prise en charge (Expertise)">
            <form v-if="approvingClaim" @submit.prevent="submitApprove">
                <p class="small text-muted">Dossier : <strong>{{ approvingClaim.claim_number }}</strong></p>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Montant approuvé (FCFA)</label>
                        <input v-model="approveForm.approved_amount" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Part Assurance (%)</label>
                        <input v-model="approveForm.insurance_share_percent" type="number" min="0" max="100" class="form-control" required />
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nom de l'expert</label>
                    <input v-model="approveForm.expert_name" type="text" class="form-control" />
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Notes d'expertise</label>
                    <textarea v-model="approveForm.expert_notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success" :disabled="approveForm.processing">Valider l'accord &amp; Notifier</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
