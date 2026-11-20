<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps({
    tools: { type: Array, default: () => [] },
    consumables: { type: Array, default: () => [] },
    mechanics: { type: Array, default: () => [] },
    repairOrders: { type: Array, default: () => [] },
    agencies: { type: Array, default: () => [] },
});

const { confirm } = useConfirm();
const activeTab = ref('tools');
const borrowingTool = ref(null);
const movingBulk = ref(null);

const toolForm = useForm({ name: '', code: '', category: 'Diagnostic', notes: '' });
const borrowForm = useForm({ borrowed_by_user_id: '' });
const bulkForm = useForm({ name: '', unit: 'L', container_capacity: 200, current_quantity: 200, alert_threshold: 20, unit_cost: 0 });
const moveForm = useForm({ type: 'sortie', quantity: 5, repair_order_id: '', notes: '' });

function submitTool() {
    toolForm.post('/outillage-vrac/outils', {
        onSuccess: () => {
            toolForm.reset('name', 'code', 'notes');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewTool'))?.hide();
        },
    });
}

async function openBorrowModal(tool) {
    borrowingTool.value = tool;
    borrowForm.borrowed_by_user_id = props.mechanics?.[0]?.id ?? '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalBorrowTool'))?.show();
}

function submitBorrow() {
    if (!borrowingTool.value) return;
    borrowForm.post(`/outillage-vrac/outils/${borrowingTool.value.id}/emprunter`, {
        preserveScroll: true,
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalBorrowTool'))?.hide(),
    });
}

function returnTool(tool) {
    router.post(`/outillage-vrac/outils/${tool.id}/rendre`, {}, { preserveScroll: true });
}

async function deleteTool(tool) {
    const ok = await confirm({
        title: 'Supprimer cet outil',
        message: `Supprimer « ${tool.name} » du registre ?`,
        confirmLabel: 'Oui, supprimer',
        variant: 'danger',
    });
    if (!ok) return;
    router.delete(`/outillage-vrac/outils/${tool.id}`, { preserveScroll: true });
}

function submitBulk() {
    bulkForm.post('/outillage-vrac/vrac', {
        onSuccess: () => {
            bulkForm.reset('name');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalNewBulk'))?.hide();
        },
    });
}

async function openMoveModal(c, type) {
    movingBulk.value = c;
    moveForm.type = type;
    moveForm.quantity = 5;
    moveForm.repair_order_id = '';
    moveForm.notes = '';
    await nextTick();
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modalBulkMove'))?.show();
}

function submitMove() {
    if (!movingBulk.value) return;
    moveForm.post(`/outillage-vrac/vrac/${movingBulk.value.id}/mouvement`, {
        preserveScroll: true,
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalBulkMove'))?.hide(),
    });
}
</script>

<template>
    <Head title="Outillage &amp; Consommables en Vrac" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-0">Outillage Atelier &amp; Consommables en Vrac</h1>
                <p class="text-muted small mb-0">Suivi des prêts d'outils aux mécaniciens et gestion des fûts (huile au litre, graisse, liquide)</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNewTool">
                    <i class="bi bi-tools me-1"></i>Nouvel outil
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNewBulk">
                    <i class="bi bi-droplet-half me-1"></i>Nouveau fût / vrac
                </button>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'tools' }" @click="activeTab = 'tools'">
                    <i class="bi bi-tools me-1"></i>Registre Outillage ({{ tools.length }})
                </button>
            </li>
            <li class="nav-item">
                <button type="button" class="nav-link" :class="{ active: activeTab === 'bulk' }" @click="activeTab = 'bulk'">
                    <i class="bi bi-droplet me-1"></i>Fûts &amp; Consommables Vrac ({{ consumables.length }})
                </button>
            </li>
        </ul>

        <!-- Outillage -->
        <div v-if="activeTab === 'tools'" class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Outil / Code</th>
                            <th>Catégorie</th>
                            <th>Statut</th>
                            <th>Emprunté par</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!tools.length">
                            <td colspan="5" class="text-center text-muted py-4">Aucun outil enregistré.</td>
                        </tr>
                        <tr v-for="t in tools" :key="t.id">
                            <td>
                                <div class="fw-bold">{{ t.name }}</div>
                                <div class="text-muted">{{ t.code || '-' }}</div>
                            </td>
                            <td>{{ t.category }}</td>
                            <td>
                                <span class="badge" :class="t.status === 'disponible' ? 'bg-success' : 'bg-warning text-dark'">
                                    {{ t.status === 'disponible' ? 'Disponible' : 'Emprunté' }}
                                </span>
                            </td>
                            <td>{{ t.borrower?.name ?? '-' }}</td>
                            <td class="text-end text-nowrap">
                                <button v-if="t.status === 'disponible'" type="button" class="btn btn-outline-primary btn-sm me-1" @click="openBorrowModal(t)">Emprunter</button>
                                <button v-else type="button" class="btn btn-outline-success btn-sm me-1" @click="returnTool(t)">Rendre</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="deleteTool(t)"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Vrac -->
        <div v-else class="row g-3">
            <div v-if="!consumables.length" class="col-12">
                <div class="card border-0 shadow-sm p-4 text-center text-muted small">Aucun fût ou consommable en vrac enregistré.</div>
            </div>
            <div v-for="c in consumables" :key="c.id" class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h2 class="h6 fw-bold mb-0">{{ c.name }}</h2>
                                <small class="text-muted">Capacité : {{ c.container_capacity }} {{ c.unit }} • Seuil alerte : {{ c.alert_threshold }} {{ c.unit }}</small>
                            </div>
                            <span class="badge" :class="c.current_quantity <= c.alert_threshold ? 'bg-danger' : 'bg-success'">
                                {{ c.current_quantity }} {{ c.unit }} restants
                            </span>
                        </div>
                        <div class="progress mb-3" style="height: 10px;">
                            <div
                                class="progress-bar"
                                :class="c.current_quantity <= c.alert_threshold ? 'bg-danger' : 'bg-primary'"
                                :style="{ width: Math.min(100, Math.round((c.current_quantity / (c.container_capacity || 1)) * 100)) + '%' }"
                            ></div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-success btn-sm" @click="openMoveModal(c, 'entree')">+ Entrée fût</button>
                            <button type="button" class="btn btn-outline-warning btn-sm text-dark" @click="openMoveModal(c, 'sortie')">- Sortie atelier</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalNewTool" title="Ajouter un outil atelier">
            <form @submit.prevent="submitTool">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nom de l'outil <span class="text-danger">*</span></label>
                    <input v-model="toolForm.name" type="text" class="form-control" placeholder="Ex: Valise Diagnostic Autel" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Code / N° série</label>
                        <input v-model="toolForm.code" type="text" class="form-control" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Catégorie</label>
                        <input v-model="toolForm.category" type="text" class="form-control" />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="toolForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalBorrowTool" title="Emprunter un outil">
            <form v-if="borrowingTool" @submit.prevent="submitBorrow">
                <p class="small text-muted">Outil : <strong>{{ borrowingTool.name }}</strong></p>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Mécanicien / Technicien</label>
                    <select v-model="borrowForm.borrowed_by_user_id" class="form-select" required>
                        <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="borrowForm.processing">Confirmer l'emprunt</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalNewBulk" title="Ajouter un fût / consommable en vrac">
            <form @submit.prevent="submitBulk">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nom du produit <span class="text-danger">*</span></label>
                    <input v-model="bulkForm.name" type="text" class="form-control" placeholder="Ex: Huile Moteur 5W30 (Fût 200L)" required />
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Unité</label>
                        <select v-model="bulkForm.unit" class="form-select">
                            <option value="L">Litre (L)</option>
                            <option value="kg">Kilogramme (kg)</option>
                            <option value="m">Mètre (m)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Capacité</label>
                        <input v-model="bulkForm.container_capacity" type="number" step="0.1" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Stock initial</label>
                        <input v-model="bulkForm.current_quantity" type="number" step="0.1" class="form-control" required />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="bulkForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalBulkMove" title="Mouvement de consommable en vrac">
            <form v-if="movingBulk" @submit.prevent="submitMove">
                <p class="small text-muted">Produit : <strong>{{ movingBulk.name }}</strong> ({{ moveForm.type === 'entree' ? 'Entrée' : 'Sortie' }})</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Quantité ({{ movingBulk.unit }})</label>
                    <input v-model="moveForm.quantity" type="number" step="0.1" min="0.1" class="form-control" required />
                </div>
                <div v-if="moveForm.type === 'sortie'" class="mb-3">
                    <label class="form-label small fw-semibold">OR lié (facultatif)</label>
                    <select v-model="moveForm.repair_order_id" class="form-select">
                        <option value="">-- Aucun --</option>
                        <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Notes</label>
                    <input v-model="moveForm.notes" type="text" class="form-control" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary" :disabled="moveForm.processing">Valider</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
