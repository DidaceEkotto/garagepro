<script setup>
import { useMarketplaceCart } from '@/composables/useMarketplaceCart';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, default: null },
});

const { cart, addToCart } = useMarketplaceCart();

const imageIdx = ref(0);
const qty = ref(1);

watch(
    () => props.product?.id,
    () => {
        imageIdx.value = 0;
        qty.value = 1;
    },
);

const isInCart = computed(() => {
    if (!props.product) return false;
    return (cart.value.items || []).some((i) => Number(i.id) === Number(props.product.id));
});

function handleAdd() {
    if (!props.product) return;
    addToCart(props.product.id, qty.value, isInCart.value);
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <div
        id="modalProductQuickView"
        class="modal fade"
        data-bs-backdrop="static"
        data-bs-keyboard="false"
        tabindex="-1"
        aria-hidden="true"
        style="z-index: 1060;"
    >
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div v-if="product" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title fw-bold text-truncate">{{ product.title }}</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="bg-light rounded-3 border overflow-hidden mb-2 d-flex align-items-center justify-content-center" style="height: 220px;">
                                <img
                                    v-if="product.image_urls?.length"
                                    :src="product.image_urls[imageIdx]"
                                    alt=""
                                    class="w-100 h-100"
                                    style="object-fit: contain;"
                                />
                                <i v-else class="bi bi-car-front fs-1 text-muted"></i>
                            </div>
                            <div v-if="product.image_urls?.length > 1" class="d-flex flex-wrap gap-1">
                                <button
                                    v-for="(u, i) in product.image_urls"
                                    :key="u"
                                    type="button"
                                    class="btn p-0 border rounded overflow-hidden"
                                    :class="{ 'border-primary border-2': imageIdx === i }"
                                    style="width: 48px; height: 48px;"
                                    @click="imageIdx = i"
                                >
                                    <img :src="u" alt="" class="w-100 h-100" style="object-fit: cover;" />
                                </button>
                            </div>
                        </div>
                        <div class="col-md-7 d-flex flex-column">
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <span v-if="product.category?.name" class="badge bg-primary-subtle text-primary">{{ product.category.name }}</span>
                                <span class="badge bg-dark">{{ product.condition }}</span>
                                <span v-if="product.country_name" class="badge bg-info text-dark">{{ product.country_name }}</span>
                            </div>
                            <div class="h4 fw-bold text-primary mb-2">{{ fmt(product.price) }}</div>
                            <div class="small text-muted mb-2">
                                <strong>Vendeur :</strong> {{ product.seller?.name }} •
                                <strong>Stock :</strong> {{ product.stock_quantity }}
                            </div>
                            <div
                                class="small text-dark mb-3"
                                style="white-space: pre-line;"
                            >{{ product.description || 'Pièce automobile vérifiée prête pour expédition ou retrait au garage.' }}</div>

                            <div v-if="isInCart" class="alert alert-warning py-2 small mb-3">
                                <i class="bi bi-exclamation-circle me-1"></i>Ce produit est déjà dans votre panier. Vous pouvez augmenter sa quantité.
                            </div>

                            <div class="mt-auto d-flex flex-wrap align-items-center gap-2">
                                <div class="input-group input-group-sm" style="width: 115px;">
                                    <button type="button" class="btn btn-outline-secondary" @click="qty = Math.max(1, qty - 1)">-</button>
                                    <input v-model.number="qty" type="number" min="1" class="form-control text-center" />
                                    <button type="button" class="btn btn-outline-secondary" @click="qty++">+</button>
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-sm fw-semibold flex-grow-1 py-2"
                                    :class="isInCart ? 'btn-warning text-dark' : 'btn-primary'"
                                    :disabled="product.stock_quantity <= 0"
                                    @click="handleAdd"
                                >
                                    <i class="bi bi-cart-plus me-1"></i>
                                    {{ isInCart ? `Augmenter la quantité (+${qty})` : 'Ajouter au panier' }}
                                </button>
                                <Link :href="`/marketplace/produit/${product.slug || product.id}`" class="btn btn-outline-dark btn-sm py-2" data-bs-dismiss="modal">
                                    Fiche complète
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
