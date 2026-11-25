<script setup>
import FileDropzone from '@/Components/FileDropzone.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useLightbox } from '@/composables/useLightbox';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { openLightbox } = useLightbox();

const props = defineProps({
    products: Object,
    categories: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
    defaultCountry: { type: String, default: 'CM' },
});

const currentYear = new Date().getFullYear();
const yearOptions = Array.from({ length: Math.max(1, currentYear - 1960 + 1) }, (_, i) => currentYear - i);

const creatingMissingCategory = ref(false);
const creatingMissingSubCategory = ref(false);

const form = useForm({
    category_id: props.categories?.[0]?.id ?? '',
    new_category_name: '',
    sub_category_id: '',
    new_sub_category_name: '',
    title: '',
    sku: '',
    brand: '',
    vehicle_compatibility: '',
    vehicle_brand: '',
    vehicle_model: '',
    vehicle_year_from: '',
    vehicle_year_to: '',
    condition: 'neuf',
    price: 15000,
    stock_quantity: 5,
    country_code: props.defaultCountry || 'CM',
    description: '',
    images: [],
});

const availableSubCategories = computed(() => {
    const cat = props.categories.find((c) => Number(c.id) === Number(form.category_id));
    return cat?.sub_categories ?? [];
});

function createProduct() {
    form.post('/marketplace-gestion/produits', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('title', 'sku', 'brand', 'vehicle_compatibility', 'vehicle_brand', 'vehicle_model', 'vehicle_year_from', 'vehicle_year_to', 'description', 'images');
            window.bootstrap?.Modal.getInstance(document.getElementById('modalAddMarketplaceProduct'))?.hide();
        },
    });
}

const { askConfirm } = useConfirm();

function toggleProduct(id) {
    askConfirm({
        title: 'Modifier la visibilité du produit',
        message: 'Voulez-vous vraiment publier ou masquer ce produit sur le Marketplace ?',
        confirmLabel: 'Confirmer',
        confirmClass: 'btn-primary',
        onConfirm: () => router.post(`/marketplace-gestion/produits/${id}/toggle`),
    });
}

function deleteProduct(id) {
    askConfirm({
        title: 'Supprimer le produit',
        message: 'Voulez-vous vraiment supprimer définitivement ce produit de votre catalogue Marketplace ?',
        confirmLabel: 'Supprimer',
        confirmClass: 'btn-danger',
        onConfirm: () => router.delete(`/marketplace-gestion/produits/${id}`),
    });
}
</script>

<template>
    <Head title="Marketplace — Mes Produits" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Mes Pièces en vente sur le Marketplace</h1>
                <p class="text-muted small mb-0">Publiez vos pièces avec une ou plusieurs photos de présentation</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddMarketplaceProduct">
                <i class="bi bi-plus-circle me-1"></i>Publier une pièce
            </button>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th>Catégorie</th>
                            <th>Pays</th>
                            <th class="text-end">Prix</th>
                            <th>Stock</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!products.data?.length">
                            <td colspan="7" class="text-center text-muted py-4 small">Aucun produit publié sur le Marketplace.</td>
                        </tr>
                        <tr v-for="p in products.data" :key="p.id">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img
                                        v-if="p.image_urls?.length"
                                        :src="p.image_urls[0]"
                                        :alt="p.title"
                                        class="rounded border"
                                        style="width: 44px; height: 44px; object-fit: cover; cursor: zoom-in;"
                                        title="Cliquer pour voir les photos"
                                        @click="openLightbox(p.image_urls, 0, p.title)"
                                    />
                                    <div v-else class="bg-light border rounded d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                                        <i class="bi bi-image text-muted"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold">{{ p.title }}</div>
                                        <div class="small text-muted">
                                            {{ p.brand || 'Générique' }} • {{ p.condition }} • {{ p.image_urls?.length ?? 0 }} photo(s)
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="small">{{ p.category?.name }} <span v-if="p.sub_category">/ {{ p.sub_category.name }}</span></td>
                            <td><span class="badge bg-light text-dark border">{{ p.country_code }}</span></td>
                            <td class="text-end fw-bold text-primary">{{ new Intl.NumberFormat('fr-FR').format(p.price) }} FCFA</td>
                            <td><span class="badge bg-secondary">{{ p.stock_quantity }}</span></td>
                            <td>
                                <span class="badge" :class="p.is_published ? 'bg-success' : 'bg-secondary'">
                                    {{ p.is_published ? 'Publié' : 'Masqué' }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-outline-secondary btn-sm me-1" @click="toggleProduct(p.id)">
                                    {{ p.is_published ? 'Masquer' : 'Publier' }}
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="deleteProduct(p.id)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StaticModal id="modalAddMarketplaceProduct" title="Publier une pièce sur le Marketplace" size="modal-lg">
            <form @submit.prevent="createProduct">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small mb-0">Catégorie</label>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none"
                                style="font-size: 0.75rem;"
                                @click="creatingMissingCategory = !creatingMissingCategory; form.category_id = ''; form.new_category_name = ''"
                            >
                                {{ creatingMissingCategory ? 'Choisir existante' : '+ Créer si absente' }}
                            </button>
                        </div>
                        <select v-if="!creatingMissingCategory" v-model="form.category_id" class="form-select" required>
                            <option value="">-- Catégorie --</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <input
                            v-else
                            v-model="form.new_category_name"
                            type="text"
                            class="form-control"
                            placeholder="Nom de la nouvelle catégorie..."
                            required
                        />
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small mb-0">Sous-catégorie</label>
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none"
                                style="font-size: 0.75rem;"
                                @click="creatingMissingSubCategory = !creatingMissingSubCategory; form.sub_category_id = ''; form.new_sub_category_name = ''"
                            >
                                {{ creatingMissingSubCategory ? 'Choisir existante' : '+ Créer si absente' }}
                            </button>
                        </div>
                        <select v-if="!creatingMissingSubCategory" v-model="form.sub_category_id" class="form-select">
                            <option value="">-- Optionnel --</option>
                            <option v-for="sc in availableSubCategories" :key="sc.id" :value="sc.id">{{ sc.name }}</option>
                        </select>
                        <input
                            v-else
                            v-model="form.new_sub_category_name"
                            type="text"
                            class="form-control"
                            placeholder="Nom de la nouvelle sous-catégorie..."
                        />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small">Titre de la pièce</label>
                        <input v-model="form.title" type="text" class="form-control" placeholder="Ex: Kit embrayage complet Valeo" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">État</label>
                        <select v-model="form.condition" class="form-select">
                            <option value="neuf">Neuf</option>
                            <option value="occasion">Occasion</option>
                            <option value="adaptable">Adaptable</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Marque</label>
                        <input v-model="form.brand" type="text" class="form-control" placeholder="Bosch, Valeo..." />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Référence / SKU</label>
                        <input v-model="form.sku" type="text" class="form-control" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Compatibilité véhicules (texte)</label>
                        <input v-model="form.vehicle_compatibility" type="text" class="form-control" placeholder="Toyota Hilux, Corolla..." />
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Marque véhicule compatible</label>
                        <input v-model="form.vehicle_brand" type="text" class="form-control" placeholder="Ex: Toyota" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Modèle véhicule compatible</label>
                        <input v-model="form.vehicle_model" type="text" class="form-control" placeholder="Ex: Corolla" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Année min</label>
                        <select v-model="form.vehicle_year_from" class="form-select">
                            <option value="">--</option>
                            <option v-for="y in yearOptions" :key="`from-${y}`" :value="String(y)">{{ y }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Année max</label>
                        <select v-model="form.vehicle_year_to" class="form-select">
                            <option value="">--</option>
                            <option v-for="y in yearOptions" :key="`to-${y}`" :value="String(y)">{{ y }}</option>
                        </select>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small">Prix (FCFA)</label>
                        <input v-model.number="form.price" type="number" min="100" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Quantité en stock</label>
                        <input v-model.number="form.stock_quantity" type="number" min="0" class="form-control" required />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Pays de disponibilité</label>
                        <select v-model="form.country_code" class="form-select" required>
                            <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Description détaillée (éditeur enrichi)</label>
                    <RichTextEditor v-model="form.description" />
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold">Images de présentation (1 ou plusieurs)</label>
                    <FileDropzone v-model="form.images" :multiple="true" label="Glisser-déposer une ou plusieurs photos du produit" />
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="form.processing">Publier le produit</button>
                </div>
            </form>
        </StaticModal>
    </TenantLayout>
</template>
