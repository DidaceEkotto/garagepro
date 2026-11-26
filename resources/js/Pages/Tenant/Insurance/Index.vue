<script setup>
import DatePickerInput from '@/Components/DatePickerInput.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    tenantType: { type: String, default: 'garage' },
    filters: { type: Object, default: () => ({ q: '', claim_status: 'all', policy_status: 'all' }) },
    policies: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    insuranceTenants: { type: Array, default: () => [] },
    garageTenants: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const { openLightbox } = useLightbox();
const { isOnline, enqueueOperation, pendingItemsForEntity } = useOfflineSync();
const offlinePolicies = pendingItemsForEntity('insurance_policy');
const offlineClaims = pendingItemsForEntity('insurance_claim');
const isInsuranceSpace = computed(() => props.tenantType === 'assurance');

const activeTab = ref('claims');
const approvingClaim = ref(null);
const editingPolicy = ref(null);

const searchQuery = ref(props.filters?.q || '');
const claimStatusFilter = ref(props.filters?.claim_status || 'all');
const policyStatusFilter = ref(props.filters?.policy_status || 'all');

function applyFilters() {
    router.get('/assurances', {
        q: searchQuery.value || undefined,
        claim_status: claimStatusFilter.value !== 'all' ? claimStatusFilter.value : undefined,
        policy_status: policyStatusFilter.value !== 'all' ? policyStatusFilter.value : undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

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

const editPolicyForm = useForm({
    policy_number: '',
    insurer_name: '',
    coverage_type: 'tous_risques',
    premium_amount: 0,
    deductible_amount: 0,
    starts_at: '',
    expires_at: '',
    status: 'active',
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
    photos: [],
});

const approveForm = useForm({
    approved_amount: 0,
    insurance_share_percent: 80,
    expert_name: '',
    expert_notes: '',
});

const policyVehicles = computed(() => {
    if (!policyForm.customer_id) return props.vehicles;
    return props.vehicles.filter((v) => Number(v.customer_id) === Number(policyForm.customer_id));
});

const claimVehicles = computed(() => {
    if (!claimForm.customer_id) return props.vehicles;
    return props.vehicles.filter((v) => Number(v.customer_id) === Number(claimForm.customer_id));
});

const claimPolicies = computed(() => {
    if (!claimForm.customer_id) return props.policies;
    return props.policies.filter((p) => Number(p.customer_id) === Number(claimForm.customer_id));
});

const claimRepairOrders = computed(() => {
    if (!claimForm.customer_id) return props.repairOrders;
    return props.repairOrders.filter((ro) => Number(ro.customer_id) === Number(claimForm.customer_id));
});

watch(() => policyForm.customer_id, () => {
    if (policyForm.vehicle_id && !policyVehicles.value.some((v) => Number(v.id) === Number(policyForm.vehicle_id))) {
        policyForm.vehicle_id = '';
    }
});

watch(() => claimForm.customer_id, () => {
    if (claimForm.vehicle_id && !claimVehicles.value.some((v) => Number(v.id) === Number(claimForm.vehicle_id))) {
        claimForm.vehicle_id = '';
    }
    if (claimForm.insurance_policy_id && !claimPolicies.value.some((p) => Number(p.id) === Number(claimForm.insurance_policy_id))) {
        claimForm.insurance_policy_id = '';
    }
    if (claimForm.repair_order_id && !claimRepairOrders.value.some((ro) => Number(ro.id) === Number(claimForm.repair_order_id))) {
        claimForm.repair_order_id = '';
    }
});

function onClaimPhotosChange(event) {
    claimForm.photos = Array.from(event.target.files || []).slice(0, 6);
}

async function submitPolicy() {
    if (!isOnline.value) {
        await enqueueOperation({
            entity: 'insurance_policy',
            action: 'create',
            label: `Police ${policyForm.policy_number} (${policyForm.insurer_name})`,
            payload: {
                customer_id: policyForm.customer_id,
                vehicle_id: policyForm.vehicle_id,
                policy_number: policyForm.policy_number,
                insurer_name: policyForm.insurer_name,
                coverage_type: policyForm.coverage_type,
                premium_amount: Number(policyForm.premium_amount || 0),
                deductible_amount: Number(policyForm.deductible_amount || 0),
                starts_at: policyForm.starts_at,
                expires_at: policyForm.expires_at,
                notes: policyForm.notes || null,
            },
        });
        policyForm.reset('customer_id', 'vehicle_id', 'policy_number', 'insurer_name', 'premium_amount', 'deductible_amount', 'expires_at', 'notes');
        window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPolicy'))?.hide();
        return;
    }

    policyForm.post('/assurances/polices', {
        onSuccess: () => {
            policyForm.reset('customer_id', 'vehicle_id', 'policy_number', 'insurer_name', 'premium_amount', 'deductible_amount', 'expires_at', 'notes');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewPolicy'))?.hide();
        },
    });
}

async function openEditPolicyModal(policy) {
    editingPolicy.value = policy;
    editPolicyForm.clearErrors();
    editPolicyForm.policy_number = policy.policy_number || '';
    editPolicyForm.insurer_name = policy.insurer_name || '';
    editPolicyForm.coverage_type = policy.coverage_type || 'tous_risques';
    editPolicyForm.premium_amount = policy.premium_amount || 0;
    editPolicyForm.deductible_amount = policy.deductible_amount || 0;
    editPolicyForm.starts_at = policy.starts_at_iso || '';
    editPolicyForm.expires_at = policy.expires_at_iso || '';
    editPolicyForm.status = policy.status || 'active';
    editPolicyForm.notes = policy.notes || '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalEditPolicy'))?.show();
}

async function submitEditPolicy() {
    if (!editingPolicy.value) return;
    if (!isOnline.value) {
        await enqueueOperation({
            entity: 'insurance_policy',
            action: 'update',
            recordId: editingPolicy.value.id,
            baseUpdatedAt: editingPolicy.value.updated_at || null,
            label: `Modif. police ${editPolicyForm.policy_number}`,
            payload: {
                customer_id: editingPolicy.value.customer_id,
                vehicle_id: editingPolicy.value.vehicle_id,
                policy_number: editPolicyForm.policy_number,
                insurer_name: editPolicyForm.insurer_name,
                coverage_type: editPolicyForm.coverage_type,
                premium_amount: Number(editPolicyForm.premium_amount || 0),
                deductible_amount: Number(editPolicyForm.deductible_amount || 0),
                starts_at: editPolicyForm.starts_at,
                expires_at: editPolicyForm.expires_at,
                status: editPolicyForm.status,
                notes: editPolicyForm.notes || null,
            },
        });
        window.bootstrap?.Modal.getInstance(document.getElementById('modalEditPolicy'))?.hide();
        return;
    }

    editPolicyForm.put(`/assurances/polices/${editingPolicy.value.id}`, {
        preserveScroll: true,
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalEditPolicy'))?.hide(),
    });
}

async function deletePolicy(policy) {
    const ok = await confirm({
        title: 'Supprimer la police d\'assurance',
        message: `Voulez-vous vraiment supprimer la police ${policy.policy_number} ?`,
        confirmLabel: 'Supprimer',
        variant: 'danger',
    });
    if (!ok) return;
    router.delete(`/assurances/polices/${policy.id}`, { preserveScroll: true });
}

async function submitClaim() {
    if (!isOnline.value) {
        await enqueueOperation({
            entity: 'insurance_claim',
            action: 'create',
            label: `Sinistre du ${claimForm.incident_date || 'jour'} (${(claimForm.photos || []).length} photo(s))`,
            payload: {
                insurance_policy_id: claimForm.insurance_policy_id || null,
                customer_id: claimForm.customer_id,
                vehicle_id: claimForm.vehicle_id,
                insurance_tenant_id: claimForm.insurance_tenant_id || null,
                garage_tenant_id: claimForm.garage_tenant_id || null,
                incident_date: claimForm.incident_date,
                description: claimForm.description,
                expert_name: claimForm.expert_name || null,
                estimated_amount: Number(claimForm.estimated_amount || 0),
            },
            files: Array.isArray(claimForm.photos) ? claimForm.photos : [],
        });
        claimForm.reset('insurance_policy_id', 'repair_order_id', 'customer_id', 'vehicle_id', 'description', 'expert_name', 'estimated_amount', 'photos');
        window.bootstrap?.Modal.getInstance(document.getElementById('modalNewClaim'))?.hide();
        return;
    }

    claimForm.post('/assurances/sinistres', {
        forceFormData: true,
        onSuccess: () => {
            claimForm.reset('insurance_policy_id', 'repair_order_id', 'customer_id', 'vehicle_id', 'description', 'expert_name', 'estimated_amount', 'photos');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewClaim'))?.hide();
        },
    });
}

async function openApproveModal(claim) {
    approvingClaim.value = claim;
    approveForm.clearErrors();
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

function coverageLabel(code) {
    if (code === 'tous_risques') return 'Tous Risques';
    if (code === 'tiers_plus') return 'Tiers étendu';
    return 'Tiers simple';
}

function policyStatusBadge(p) {
    if (p.status === 'expiree') return { cls: 'bg-danger', label: 'Expirée' };
    if (p.status === 'resiliee') return { cls: 'bg-secondary', label: 'Résiliée' };
    if (p.is_expiring_soon) return { cls: 'bg-warning text-dark', label: 'Expire bientôt' };
    return { cls: 'bg-success', label: 'Active' };
}
</script>

<template>
    <Head title="Assurances &amp; Sinistres" />
    <TenantLayout>
        <!-- Polices / Sinistres saisis hors ligne -->
        <div v-if="offlinePolicies.length || offlineClaims.length" class="alert alert-warning border-0 shadow-sm mb-3">
            <div class="fw-bold small mb-2">
                <i class="bi bi-wifi-off me-1"></i>Saisies hors ligne en attente de synchronisation
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span v-for="item in offlinePolicies" :key="item.client_uuid" class="badge bg-white text-dark border px-2 py-1">
                    <i class="bi bi-shield-check text-primary me-1"></i>{{ item.label }}
                </span>
                <span v-for="item in offlineClaims" :key="item.client_uuid" class="badge bg-white text-dark border px-2 py-1">
                    <i class="bi bi-exclamation-octagon text-danger me-1"></i>{{ item.label }}
                </span>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Assurances Automobile &amp; Sinistres</h1>
                <p class="text-muted small mb-0">Gestion des polices d'assurance, dossiers sinistres, photos, accords de prise en charge et attestations PDF</p>
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

        <!-- Barre recherche & filtres -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <form class="row g-2 align-items-center" @submit.prevent="applyFilters">
                    <div class="col-md-5">
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="form-control form-control-sm"
                            placeholder="Rechercher par N° sinistre, N° police, client, plaque ou expert..."
                        />
                    </div>
                    <div class="col-md-3">
                        <select
                            v-show="activeTab === 'claims'"
                            key="claim-status-filter"
                            v-model="claimStatusFilter"
                            class="form-select form-select-sm no-select2"
                            @change="applyFilters"
                        >
                            <option value="all">Tous les statuts sinistres</option>
                            <option value="declare">Déclaré</option>
                            <option value="expertise">En expertise</option>
                            <option value="accord_prise_en_charge">Accord prise en charge</option>
                            <option value="en_reparation">En réparation</option>
                            <option value="cloture">Clôturé</option>
                            <option value="refuse">Refusé</option>
                        </select>
                        <select
                            v-show="activeTab === 'policies'"
                            key="policy-status-filter"
                            v-model="policyStatusFilter"
                            class="form-select form-select-sm no-select2"
                            @change="applyFilters"
                        >
                            <option value="all">Tous les statuts polices</option>
                            <option value="active">Actives</option>
                            <option value="expiree">Expirées</option>
                            <option value="resiliee">Résiliées</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                            <i class="bi bi-search me-1"></i>Filtrer
                        </button>
                    </div>
                </form>
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
                            <th>Client / Véhicule / Partenaires</th>
                            <th>Montants &amp; Expertise</th>
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
                                <div v-if="c.policy" class="text-muted" style="font-size: 0.72rem;">Police : {{ c.policy.policy_number }}</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ c.customer?.name || 'Client' }} ({{ c.vehicle?.license_plate || '-' }})</div>
                                <div class="text-muted text-truncate" style="max-width: 280px;" :title="c.description">{{ c.description }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <span v-if="c.insurance_tenant" class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-shield-check me-1"></i>{{ c.insurance_tenant.name }}
                                    </span>
                                    <span v-if="c.garage_tenant" class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <i class="bi bi-wrench-adjustable me-1"></i>{{ c.garage_tenant.name }}
                                    </span>
                                    <span v-if="c.repair_order" class="badge bg-light text-dark border">
                                        OR {{ c.repair_order.order_number }}
                                    </span>
                                </div>
                                <div v-if="c.photo_urls && c.photo_urls.length" class="d-flex flex-wrap gap-1 mt-2">
                                    <button
                                        v-for="(url, idx) in c.photo_urls"
                                        :key="idx"
                                        type="button"
                                        class="btn p-0 border-0 bg-transparent"
                                        title="Agrandir la photo"
                                        @click="openLightbox(c.photo_urls, idx)"
                                    >
                                        <img :src="url" alt="Photo sinistre" class="rounded border shadow-sm" style="width: 42px; height: 42px; object-fit: cover; cursor: zoom-in;" />
                                    </button>
                                </div>
                            </td>
                            <td>
                                <div>Estimé : <strong>{{ formatMoney(c.estimated_amount) }}</strong></div>
                                <div v-if="c.approved_amount > 0" class="text-success fw-semibold">
                                    Accord : {{ formatMoney(c.approved_amount) }} ({{ c.insurance_share_percent }}%) • Franchise : {{ formatMoney(c.client_deductible_amount) }}
                                </div>
                                <div v-if="c.expert_name" class="text-muted" style="font-size: 0.75rem;">
                                    <i class="bi bi-person-badge me-1"></i>Expert : {{ c.expert_name }}
                                </div>
                                <div v-if="c.expert_notes" class="text-muted fst-italic" style="font-size: 0.72rem; max-width: 240px;">
                                    « {{ c.expert_notes }} »
                                </div>
                            </td>
                            <td>
                                <select :value="c.status" class="form-select form-select-sm" @change="updateClaimStatus(c.id, $event.target.value)">
                                    <option value="declare">Déclaré</option>
                                    <option value="expertise">En expertise</option>
                                    <option value="accord_prise_en_charge" :disabled="!c.can_approve">Accord prise en charge</option>
                                    <option value="en_reparation">En réparation</option>
                                    <option value="cloture">Clôturé</option>
                                    <option value="refuse" :disabled="!c.can_approve">Refusé</option>
                                </select>
                            </td>
                            <td class="text-end text-nowrap">
                                <a :href="c.pdf_url" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1" title="Télécharger le dossier PDF">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </a>
                                <button
                                    v-if="c.can_approve"
                                    type="button"
                                    class="btn btn-outline-success btn-sm"
                                    @click="openApproveModal(c)"
                                >
                                    <i class="bi bi-check2-circle me-1"></i>Accord / Expertise
                                </button>
                                <span v-else class="badge bg-light text-muted border">En attente assureur</span>
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
                            <th>Formule &amp; Statut</th>
                            <th>Prime / Franchise</th>
                            <th>Validité</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!policies.length">
                            <td colspan="6" class="text-center text-muted py-4">Aucune police enregistrée.</td>
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
                            <td>
                                <span class="badge bg-info-subtle text-dark border me-1">{{ coverageLabel(p.coverage_type) }}</span>
                                <span class="badge" :class="policyStatusBadge(p).cls">{{ policyStatusBadge(p).label }}</span>
                            </td>
                            <td>
                                <div>Prime : <strong>{{ formatMoney(p.premium_amount) }}</strong></div>
                                <div class="text-muted">Franchise : {{ formatMoney(p.deductible_amount) }}</div>
                            </td>
                            <td>{{ p.starts_at }} → <strong>{{ p.expires_at }}</strong></td>
                            <td class="text-end text-nowrap">
                                <a :href="p.pdf_url" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm me-1" title="Attestation PDF">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </a>
                                <button type="button" class="btn btn-outline-primary btn-sm me-1" @click="openEditPolicyModal(p)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="deletePolicy(p)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Nouvelle Police -->
        <StaticModal id="modalNewPolicy" title="Enregistrer une police d'assurance" size="modal-lg">
            <form @submit.prevent="submitPolicy">
                <div v-if="Object.keys(policyForm.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in policyForm.errors" :key="field">{{ err }}</div>
                </div>
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
                            <option v-for="v in policyVehicles" :key="v.id" :value="v.id">{{ v.license_plate }} ({{ v.brand }} {{ v.model }})</option>
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
                <div class="row g-2 mb-3">
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
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Observations</label>
                    <textarea v-model="policyForm.notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="policyForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Éditer / Renouveler / Résilier Police -->
        <StaticModal id="modalEditPolicy" title="Modifier / Renouveler la police d'assurance" size="modal-lg">
            <form v-if="editingPolicy" @submit.prevent="submitEditPolicy">
                <div v-if="Object.keys(editPolicyForm.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in editPolicyForm.errors" :key="field">{{ err }}</div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">N° Police <span class="text-danger">*</span></label>
                        <input v-model="editPolicyForm.policy_number" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Compagnie</label>
                        <input v-model="editPolicyForm.insurer_name" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Statut</label>
                        <select v-model="editPolicyForm.status" class="form-select">
                            <option value="active">Active</option>
                            <option value="expiree">Expirée</option>
                            <option value="resiliee">Résiliée</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Formule</label>
                        <select v-model="editPolicyForm.coverage_type" class="form-select">
                            <option value="tiers">Tiers simple</option>
                            <option value="tiers_plus">Tiers étendu</option>
                            <option value="tous_risques">Tous Risques</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Prime (FCFA)</label>
                        <input v-model="editPolicyForm.premium_amount" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Franchise (FCFA)</label>
                        <input v-model="editPolicyForm.deductible_amount" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Date d'effet</label>
                        <DatePickerInput v-model="editPolicyForm.starts_at" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Date d'expiration</label>
                        <DatePickerInput v-model="editPolicyForm.expires_at" required />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Observations</label>
                    <textarea v-model="editPolicyForm.notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="editPolicyForm.processing">Mettre à jour</button>
                </div>
            </form>
        </StaticModal>

        <!-- Modal Sinistre -->
        <StaticModal id="modalNewClaim" title="Déclarer un dossier sinistre" size="modal-lg">
            <form @submit.prevent="submitClaim">
                <div v-if="Object.keys(claimForm.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in claimForm.errors" :key="field">{{ err }}</div>
                </div>
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
                            <option v-for="v in claimVehicles" :key="v.id" :value="v.id">{{ v.license_plate }} ({{ v.brand }} {{ v.model }})</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Date du sinistre <span class="text-danger">*</span></label>
                        <DatePickerInput v-model="claimForm.incident_date" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div :class="isInsuranceSpace ? 'col-md-6' : 'col-md-4'">
                        <label class="form-label small fw-semibold">Police liée (facultatif)</label>
                        <select v-model="claimForm.insurance_policy_id" class="form-select">
                            <option value="">-- Aucune --</option>
                            <option v-for="p in claimPolicies" :key="p.id" :value="p.id">{{ p.policy_number }}</option>
                        </select>
                    </div>
                    <div v-if="!isInsuranceSpace" class="col-md-4">
                        <label class="form-label small fw-semibold">OR lié (facultatif)</label>
                        <select v-model="claimForm.repair_order_id" class="form-select">
                            <option value="">-- Aucun --</option>
                            <option v-for="ro in claimRepairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }}</option>
                        </select>
                    </div>
                    <div :class="isInsuranceSpace ? 'col-md-6' : 'col-md-4'">
                        <label class="form-label small fw-semibold">Devis estimé (FCFA)</label>
                        <input v-model="claimForm.estimated_amount" type="number" min="0" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div v-if="!isInsuranceSpace" class="col-md-6">
                        <label class="form-label small fw-semibold">Assurance partenaire MboaCar (facultatif)</label>
                        <select v-model="claimForm.insurance_tenant_id" class="form-select">
                            <option value="">-- Gestion interne --</option>
                            <option v-for="ins in insuranceTenants" :key="ins.id" :value="ins.id">{{ ins.name }}</option>
                        </select>
                    </div>
                    <div :class="isInsuranceSpace ? 'col-md-12' : 'col-md-6'">
                        <label class="form-label small fw-semibold">Garage réparateur partenaire (facultatif)</label>
                        <select v-model="claimForm.garage_tenant_id" class="form-select">
                            <option value="">-- Aucun / À définir --</option>
                            <option v-for="gar in garageTenants" :key="gar.id" :value="gar.id">{{ gar.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Photos des dégâts / constat (nombre illimité)</label>
                    <FileDropzone
                        v-model="claimForm.photos"
                        :multiple="true"
                        accept="image/jpeg,image/png,image/webp"
                        label="Glissez-déposez autant d'images que vous voulez ici ou cliquez pour parcourir"
                    />
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
                <div v-if="Object.keys(approveForm.errors).length" class="alert alert-danger py-2 small mb-3">
                    <div v-for="(err, field) in approveForm.errors" :key="field">{{ err }}</div>
                </div>
                <p class="small text-muted">Dossier : <strong>{{ approvingClaim.claim_number }}</strong> — {{ approvingClaim.customer?.name }} ({{ approvingClaim.vehicle?.license_plate }})</p>
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
                <div class="alert alert-light border py-2 small mb-3">
                    Part assurance : <strong>{{ formatMoney(Math.round((Number(approveForm.approved_amount || 0) * Number(approveForm.insurance_share_percent || 0)) / 100)) }}</strong>
                    • Franchise client : <strong>{{ formatMoney(Math.max(0, Number(approveForm.approved_amount || 0) - Math.round((Number(approveForm.approved_amount || 0) * Number(approveForm.insurance_share_percent || 0)) / 100))) }}</strong>
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
