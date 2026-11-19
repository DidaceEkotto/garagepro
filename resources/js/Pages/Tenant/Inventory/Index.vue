<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    parts: Object,
    lowStockCount: Number,
    agencies: Array,
    suppliers: Array,
    repairOrders: Array,
    mechanics: { type: Array, default: () => [] },
    recentMovements: { type: Array, default: () => [] },
});

const selectedPart = ref(null);
const b2bResults = ref([]);
const b2bQuery = ref('');
const b2bLoading = ref(false);

async function searchB2B(query) {
    if (!query || query.trim().length < 2) return;
    b2bQuery.value = query.trim();
    b2bLoading.value = true;
    try {
        const res = await fetch(`/marketplace-b2b/rechercher?q=${encodeURIComponent(b2bQuery.value)}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        b2bResults.value = data.items || [];
    } finally {
        b2bLoading.value = false;
    }
}

const partForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    sku: '',
    name: '',
    category: 'piece',
    condition: 'neuve',
    location: '',
    quantity_on_hand: 10,
    min_threshold: 2,
    purchase_price: 0,
    selling_price: 0,
});

const supplierForm = useForm({
    name: '',
    email: '',
    phone: '',
    address: '',
    city: '',
    tax_id: '',
    lead_time_days: 3,
    notes: '',
});

const moveForm = useForm({
    action: 'issue',
    repair_order_id: props.repairOrders?.[0]?.id ?? '',
    requested_by_employee_id: props.mechanics?.[0]?.id ?? '',
    target_agency_id: props.agencies?.[0]?.id ?? '',
    quantity: 1,
});

function createPart() {
    partForm.post('/stock/pieces', {
        onSuccess: () => {
            partForm.reset('sku', 'name', 'location', 'purchase_price', 'selling_price');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddPart'))?.hide();
        },
    });
}

function createSupplier() {
    supplierForm.post('/stock/fournisseurs', {
        onSuccess: () => {
            supplierForm.reset('name', 'phone', 'city');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddSupplier'))?.hide();
        },
    });
}

function submitMovement() {
    if (!selectedPart.value) return;
    const id = selectedPart.value.id;

    if (moveForm.action === 'reserve') {
        moveForm.post(`/stock/pieces/${id}/reserver`, { onSuccess: () => (selectedPart.value = null) });
    } else if (moveForm.action === 'issue') {
        moveForm.post(`/stock/pieces/${id}/sortie`, { onSuccess: () => (selectedPart.value = null) });
    } else {
        moveForm.post(`/stock/pieces/${id}/transfert`, { onSuccess: () => (selectedPart.value = null) });
    }
}
</script>

<template>
    <Head title="Stock & Pièces" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Stock &amp; Pièces détachées</h1>
                <p class="text-muted small mb-0">Entrées, sorties vers OR, réservations, transferts inter-agences et alertes</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span v-if="lowStockCount > 0" class="badge bg-danger px-3 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ lowStockCount }} alerte(s)
                </span>
                <a href="/export-excel/stock" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddSupplier">
                    <i class="bi bi-truck me-1"></i>Nouveau fournisseur
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddPart">
                    <i class="bi bi-plus-circle me-1"></i>Nouvelle pièce
                </button>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Réf / Désignation</th>
                                <th>Agence</th>
                                <th>Stock</th>
                                <th>Réservé</th>
                                <th class="text-end">Prix vente</th>
                                <th class="text-end">Mouvement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in parts.data" :key="p.id">
                                <td>
                                    <div class="fw-bold">{{ p.name }}</div>
                                    <div class="small text-muted">{{ p.sku }} • {{ p.location ?? 'Sans casier' }}</div>
                                </td>
                                <td class="small">{{ p.agency?.name }}</td>
                                <td>
                                    <span class="badge" :class="p.quantity_on_hand <= p.min_threshold ? 'bg-danger' : 'bg-success'">
                                        {{ p.quantity_on_hand }}
                                    </span>
                                    <span v-if="p.quantity_on_hand <= p.min_threshold" class="small text-danger ms-1">Alerte</span>
                                </td>
                                <td>{{ p.quantity_reserved }}</td>
                                <td class="text-end">{{ new Intl.NumberFormat('fr-FR').format(p.selling_price) }} FCFA</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <button
                                            v-if="p.quantity_on_hand <= p.min_threshold"
                                            type="button"
                                            class="btn btn-outline-warning btn-sm"
                                            title="Chercher cette pièce chez un autre garage (B2B)"
                                            @click="searchB2B(p.name)"
                                        >
                                            <i class="bi bi-shop me-1"></i>B2B
                                        </button>
                                        <button type="button" class="btn btn-outline-primary btn-sm" @click="selectedPart = p">
                                            Sortie / Transfert
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="b2bQuery" class="card border-0 shadow-sm border-start border-4 border-warning mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <span class="fw-bold"><i class="bi bi-shop text-warning me-2"></i>Approvisionnement Marketplace B2B pour « {{ b2bQuery }} »</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="b2bQuery = ''; b2bResults = []">&times;</button>
            </div>
            <div class="card-body p-3">
                <div v-if="b2bLoading" class="small text-muted">Recherche chez les autres garages...</div>
                <div v-else-if="!b2bResults.length" class="small text-muted">Aucun autre garage n'a cette pièce en stock pour le moment.</div>
                <div v-else class="row g-2">
                    <div v-for="item in b2bResults" :key="item.id" class="col-md-4">
                        <div class="border rounded p-2 bg-light small h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="fw-bold">{{ item.title }}</div>
                                <div class="text-muted">{{ item.garage_name }} ({{ item.garage_city || 'Cameroun' }}) · Stock : {{ item.stock_quantity }}</div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="fw-bold text-primary">{{ new Intl.NumberFormat('fr-FR').format(item.price) }} FCFA</span>
                                <a :href="item.url" target="_blank" class="btn btn-warning btn-sm py-0 px-2">Voir / Commander</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="selectedPart" class="card border-0 shadow-sm border-start border-4 border-primary mb-4">
            <div class="card-header bg-white fw-bold py-3">Mouvement sur « {{ selectedPart.name }} » ({{ selectedPart.sku }})</div>
            <div class="card-body p-4">
                <form class="row g-3 align-items-end" @submit.prevent="submitMovement">
                    <div class="col-md-3">
                        <label class="form-label small">Opération</label>
                        <select v-model="moveForm.action" class="form-select">
                            <option value="issue">Sortie vers OR</option>
                            <option value="reserve">Réserver pour OR</option>
                            <option value="transfer">Transfert inter-agences</option>
                        </select>
                    </div>
                    <div v-if="moveForm.action !== 'transfer'" class="col-md-3">
                        <label class="form-label small">Ordre de réparation</label>
                        <select v-model="moveForm.repair_order_id" class="form-select" required>
                            <option v-for="ro in repairOrders" :key="ro.id" :value="ro.id">{{ ro.order_number }}</option>
                        </select>
                    </div>
                    <div v-if="moveForm.action === 'issue'" class="col-md-3">
                        <label class="form-label small">Garagiste demandeur <span class="text-danger">*</span></label>
                        <select v-model="moveForm.requested_by_employee_id" class="form-select" required>
                            <option value="">-- Sélectionner le garagiste --</option>
                            <option v-for="m in mechanics" :key="m.id" :value="m.id">{{ m.full_name }} ({{ m.role_title }})</option>
                        </select>
                        <div v-if="moveForm.errors.requested_by_employee_id" class="text-danger small">{{ moveForm.errors.requested_by_employee_id }}</div>
                    </div>
                    <div v-if="moveForm.action === 'transfer'" class="col-md-3">
                        <label class="form-label small">Agence cible</label>
                        <select v-model="moveForm.target_agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small">Qté</label>
                        <input v-model.number="moveForm.quantity" type="number" min="1" class="form-control" required />
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100" :disabled="moveForm.processing">Valider</button>
                    </div>
                </form>
            </div>
        </div>

        <div v-if="recentMovements.length" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Historique récent des sorties &amp; mouvements</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>Pièce</th><th>Opération</th><th>Qté</th><th>Garagiste demandeur</th><th>Réf</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="mv in recentMovements" :key="mv.id">
                            <td class="fw-semibold">{{ mv.part?.name }}</td>
                            <td><span class="badge bg-light text-dark border">{{ mv.type }}</span></td>
                            <td :class="mv.quantity < 0 ? 'text-danger fw-bold' : 'text-success fw-bold'">{{ mv.quantity }}</td>
                            <td>{{ mv.requested_by?.full_name ?? '-' }}</td>
                            <td class="small">{{ mv.reference ?? '-' }}</td>
                            <td class="small text-muted">{{ new Date(mv.created_at).toLocaleString('fr-FR') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddPart" title="Ajouter une pièce au stock">
            <form @submit.prevent="createPart">
                <div class="mb-3">
                    <label class="form-label small">Agence</label>
                    <select v-model="partForm.agency_id" class="form-select" required>
                        <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label small">Référence SKU</label>
                        <input v-model="partForm.sku" type="text" class="form-control" required />
                    </div>
                    <div class="col-7">
                        <label class="form-label small">Désignation</label>
                        <input v-model="partForm.name" type="text" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small">Catégorie</label>
                        <select v-model="partForm.category" class="form-select">
                            <option value="piece">Pièce</option>
                            <option value="consommable">Consommable</option>
                            <option value="accessoire">Accessoire</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Emplacement</label>
                        <input v-model="partForm.location" type="text" class="form-control" placeholder="Rayon B2" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small">Stock initial</label>
                        <input v-model.number="partForm.quantity_on_hand" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Seuil alerte</label>
                        <input v-model.number="partForm.min_threshold" type="number" min="0" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="form-label small">Prix achat (FCFA)</label>
                        <input v-model.number="partForm.purchase_price" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Prix vente (FCFA)</label>
                        <input v-model.number="partForm.selling_price" type="number" min="0" class="form-control" required />
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="partForm.processing">Ajouter</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalAddSupplier" title="Nouveau fournisseur" size="modal-lg">
            <form @submit.prevent="createSupplier">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Nom du fournisseur</label>
                        <input v-model="supplierForm.name" type="text" class="form-control" required />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">E-mail (obligatoire pour les commandes)</label>
                        <input v-model="supplierForm.email" type="email" class="form-control" required />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Téléphone</label>
                        <input v-model="supplierForm.phone" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Ville</label>
                        <input v-model="supplierForm.city" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Délai livraison (jours)</label>
                        <input v-model.number="supplierForm.lead_time_days" type="number" min="0" class="form-control" />
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small">Adresse</label>
                        <input v-model="supplierForm.address" type="text" class="form-control" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">NIU / RCCM</label>
                        <input v-model="supplierForm.tax_id" type="text" class="form-control" />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Notes</label>
                    <textarea v-model="supplierForm.notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="supplierForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
