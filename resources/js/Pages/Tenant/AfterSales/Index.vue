<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    cases: Object,
    loyaltyCards: Array,
    agencies: Array,
    customers: Array,
    vehicles: Array,
});

const caseForm = useForm({
    agency_id: props.agencies?.[0]?.id ?? '',
    customer_id: props.customers?.[0]?.id ?? '',
    type: 'satisfaction',
    rating: 5,
    description: '',
});

const loyaltyForm = useForm({
    customer_id: props.customers?.[0]?.id ?? '',
    points: 100,
});

function submitCase() {
    caseForm.post('/apres-vente/dossiers', {
        onSuccess: () => {
            caseForm.reset('description');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddCase'))?.hide();
        },
    });
}

function awardPoints() {
    loyaltyForm.post('/apres-vente/fidelite', {
        onSuccess: () => window.bootstrap?.Modal.getInstance(document.getElementById('modalAwardLoyalty'))?.hide(),
    });
}
</script>

<template>
    <Head title="Après-vente & Fidélisation" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Après-vente, Garanties &amp; Fidélité</h1>
                <p class="text-muted small mb-0">Suivi des réclamations, retours sous garantie, enquêtes et cartes de fidélité</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAwardLoyalty">
                    <i class="bi bi-award me-1"></i>Créditer points
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddCase">
                    <i class="bi bi-chat-square-heart me-1"></i>Nouveau dossier SAV
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Dossiers SAV &amp; Satisfaction</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Client</th><th>Type</th><th>Note</th><th>Statut</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="c in cases.data" :key="c.id">
                                    <td class="fw-semibold">{{ c.customer?.name }}</td>
                                    <td>{{ c.type }}</td>
                                    <td>{{ c.rating ? c.rating + '/5' : '-' }}</td>
                                    <td><span class="badge bg-info text-dark">{{ c.status }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3">Cartes de fidélité</div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>N° Carte</th><th>Client</th><th>Points</th><th>Niveau</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="lc in loyaltyCards" :key="lc.id">
                                    <td class="fw-bold">{{ lc.card_number }}</td>
                                    <td>{{ lc.customer?.name }}</td>
                                    <td>{{ lc.points }} pts</td>
                                    <td><span class="badge bg-warning text-dark">{{ lc.tier }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddCase" title="Nouveau dossier SAV / Satisfaction">
            <form @submit.prevent="submitCase">
                <div class="mb-3">
                    <label class="form-label small">Client</label>
                    <select v-model="caseForm.customer_id" class="form-select" required>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small">Type</label>
                        <select v-model="caseForm.type" class="form-select">
                            <option value="satisfaction">Satisfaction</option>
                            <option value="garantie">Garantie</option>
                            <option value="reclamation">Réclamation</option>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label small">Note (/5)</label>
                        <input v-model.number="caseForm.rating" type="number" min="1" max="5" class="form-control" />
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Description</label>
                    <textarea v-model="caseForm.description" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="caseForm.processing">Enregistrer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalAwardLoyalty" title="Créditer des points de fidélité">
            <form @submit.prevent="awardPoints">
                <div class="mb-3">
                    <label class="form-label small">Client</label>
                    <select v-model="loyaltyForm.customer_id" class="form-select" required>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Points à créditer</label>
                    <input v-model.number="loyaltyForm.points" type="number" min="1" class="form-control" required />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="loyaltyForm.processing">Créditer</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
