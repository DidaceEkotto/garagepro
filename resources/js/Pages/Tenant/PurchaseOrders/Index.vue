<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    purchaseOrders: Object,
    agencies: Array,
    suppliers: Array,
    parts: Array,
});

const form = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    supplier_id: props.suppliers?.[0]?.id ?? '',
    expected_at: '',
    items: [
        { part_id: props.parts?.[0]?.id ?? '', description: 'Réapprovisionnement pièces', quantity_ordered: 5, unit_cost: 3500 },
    ],
});

function addItem() {
    form.items.push({ part_id: '', description: '', quantity_ordered: 1, unit_cost: 0 });
}

function removeItem(idx) {
    if (form.items.length > 1) {
        form.items.splice(idx, 1);
    }
}

function remindSupplier(poId) {
    router.post(`/approvisionnement/${poId}/relancer`);
}

function submitPo() {
    form.post('/approvisionnement', {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalAddPo'))?.hide(),
    });
}

function receiveAll(po) {
    const received = {};
    (po.items ?? []).forEach((item) => {
        const remaining = Math.max(0, item.quantity_ordered - item.quantity_received);
        if (remaining > 0) received[item.id] = remaining;
    });
    router.post(`/approvisionnement/${po.id}/reception`, { received });
}
</script>

<template>
    <Head title="Bons de commande fournisseurs" />
    <TenantLayout>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Approvisionnement &amp; Fournisseurs</h1>
                <p class="text-muted small mb-0">Bons de commande fournisseurs et entrée automatique en stock à la réception</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddPo">
                <i class="bi bi-cart-plus me-1"></i>Nouveau bon de commande
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Numéro BC</th>
                                <th>Fournisseur</th>
                                <th>Statut</th>
                                <th class="text-end">Montant</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="po in purchaseOrders.data" :key="po.id">
                                <td class="fw-bold">{{ po.po_number }}</td>
                                <td>{{ po.supplier?.name }}</td>
                                <td><span class="badge bg-info text-dark">{{ po.status }}</span></td>
                                <td class="text-end">{{ new Intl.NumberFormat('fr-FR').format(po.total_amount) }} FCFA</td>
                                <td class="text-end text-nowrap">
                                    <button v-if="po.status !== 'recu'" type="button" class="btn btn-outline-warning btn-sm me-1" @click="remindSupplier(po.id)">
                                        Relancer
                                    </button>
                                    <button v-if="po.status !== 'recu'" type="button" class="btn btn-outline-success btn-sm" @click="receiveAll(po)">
                                        Réceptionner
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddPo" title="Nouveau bon de commande fournisseur" size="modal-lg">
            <form @submit.prevent="submitPo">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small">Agence</label>
                        <select v-model="form.agency_id" class="form-select" required>
                            <option v-for="ag in agencies" :key="ag.id" :value="ag.id">{{ ag.name }}</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Fournisseur</label>
                        <select v-model="form.supplier_id" class="form-select" required>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <span class="form-label small mb-0">Articles commandés</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="addItem">+ Ligne</button>
                </div>

                <div v-for="(item, idx) in form.items" :key="idx" class="border rounded p-2 mb-2 bg-light">
                    <select v-model="item.part_id" class="form-select form-select-sm mb-2">
                        <option value="">-- Article libre --</option>
                        <option v-for="pt in parts" :key="pt.id" :value="pt.id">{{ pt.sku }} - {{ pt.name }}</option>
                    </select>
                    <input v-model="item.description" type="text" class="form-control form-control-sm mb-2" placeholder="Description" required />
                    <div class="row g-2 align-items-center">
                        <div class="col-4">
                            <input v-model.number="item.quantity_ordered" type="number" min="1" class="form-control form-control-sm" placeholder="Qté" required />
                        </div>
                        <div class="col-6">
                            <input v-model.number="item.unit_cost" type="number" min="0" class="form-control form-control-sm" placeholder="Coût unitaire" required />
                        </div>
                        <div class="col-2 text-end">
                            <button type="button" class="btn btn-outline-danger btn-sm" :disabled="form.items.length <= 1" @click="removeItem(idx)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Envoyer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
