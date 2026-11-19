<script setup>
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps({
    categories: { type: Array, default: () => [] },
});

const catForm = useForm({
    name: '',
    icon: 'bi-gear-wide-connected',
    description: '',
});

const subForm = useForm({
    category_id: '',
    name: '',
});

function createCategory() {
    catForm.post('/marketplace-gestion/categories', {
        onSuccess: () => {
            catForm.reset('name', 'description');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddCategory'))?.hide();
        },
    });
}

function createSubCategory() {
    subForm.post('/marketplace-gestion/sous-categories', {
        onSuccess: () => {
            subForm.reset('name');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddSubCategory'))?.hide();
        },
    });
}

function deleteCategory(id) {
    router.delete(`/marketplace-gestion/categories/${id}`);
}

function deleteSubCategory(id) {
    router.delete(`/marketplace-gestion/sous-categories/${id}`);
}
</script>

<template>
    <Head title="Marketplace — Catégories" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Catégories &amp; Sous-catégories Marketplace</h1>
                <p class="text-muted small mb-0">Organisez vos pièces automobiles pour faciliter la recherche des acheteurs</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddSubCategory">
                    <i class="bi bi-diagram-3 me-1"></i>Nouvelle sous-catégorie
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddCategory">
                    <i class="bi bi-plus-circle me-1"></i>Nouvelle catégorie
                </button>
            </div>
        </div>

        <div class="row g-3">
            <div v-if="!categories.length" class="col-12">
                <div class="card border-0 shadow-sm p-5 text-center text-muted small">
                    Aucune catégorie créée. Commencez par ajouter une catégorie (ex : Moteur, Freinage, Carrosserie...).
                </div>
            </div>
            <div v-for="cat in categories" :key="cat.id" class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i :class="`bi ${cat.icon} text-primary me-2`"></i>{{ cat.name }}</span>
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" @click="deleteCategory(cat.id)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="card-body">
                        <p v-if="cat.description" class="small text-muted mb-3">{{ cat.description }}</p>
                        <div class="small fw-semibold text-muted mb-2">Sous-catégories ({{ cat.sub_categories?.length ?? 0 }})</div>
                        <div class="d-flex flex-wrap gap-1">
                            <span
                                v-for="sub in cat.sub_categories"
                                :key="sub.id"
                                class="badge bg-light text-dark border d-inline-flex align-items-center gap-1 py-1 px-2"
                            >
                                <span>{{ sub.name }}</span>
                                <button type="button" class="btn-close" style="font-size: 0.55rem;" @click="deleteSubCategory(sub.id)"></button>
                            </span>
                            <span v-if="!cat.sub_categories?.length" class="small text-muted">Aucune sous-catégorie</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <StaticModal id="modalAddCategory" title="Nouvelle catégorie">
            <form @submit.prevent="createCategory">
                <div class="mb-3">
                    <label class="form-label small">Nom de la catégorie</label>
                    <input v-model="catForm.name" type="text" class="form-control" placeholder="Ex: Freinage" required />
                </div>
                <div class="mb-3">
                    <label class="form-label small">Icône Bootstrap</label>
                    <select v-model="catForm.icon" class="form-select">
                        <option value="bi-gear-wide-connected">Moteur / Mécanique</option>
                        <option value="bi-disc">Freinage / Roues</option>
                        <option value="bi-car-front">Carrosserie</option>
                        <option value="bi-lightning-charge">Électricité / Batterie</option>
                        <option value="bi-droplet">Huiles &amp; Lubrifiants</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Description (optionnel)</label>
                    <textarea v-model="catForm.description" rows="2" class="form-control"></textarea>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="catForm.processing">Créer</button>
                </div>
            </form>
        </StaticModal>

        <StaticModal id="modalAddSubCategory" title="Nouvelle sous-catégorie">
            <form @submit.prevent="createSubCategory">
                <div class="mb-3">
                    <label class="form-label small">Catégorie parente</label>
                    <select v-model="subForm.category_id" class="form-select" required>
                        <option value="">-- Choisir --</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Nom de la sous-catégorie</label>
                    <input v-model="subForm.name" type="text" class="form-control" placeholder="Ex: Plaquettes de frein" required />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="subForm.processing">Ajouter</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
