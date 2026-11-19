<script setup>
import MarketplaceProductCard from '@/Components/MarketplaceProductCard.vue';
import MarketplaceQuickViewModal from '@/Components/MarketplaceQuickViewModal.vue';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout.vue';
import { useMarketplaceCart } from '@/composables/useMarketplaceCart';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    product: Object,
    relatedProducts: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    ratingBreakdown: { type: Object, default: () => ({ 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 }) },
});

const { cart, addToCart } = useMarketplaceCart();
const selectedImageIndex = ref(0);
const quantity = ref(1);
const quickProduct = ref(null);
const hoverRating = ref(0);

const reviewForm = useForm({
    reviewer_name: '',
    reviewer_email: '',
    order_number: '',
    rating: 5,
    comment: '',
    website: '',
});

const isInCart = computed(() => (cart.value.items || []).some((i) => Number(i.id) === Number(props.product.id)));

async function handleAdd(goToCheckout = false, increment = false) {
    const result = await addToCart(props.product.id, quantity.value, increment);
    if (goToCheckout && (result.ok || result.alreadyInCart)) {
        router.visit('/commander');
    }
}

function submitReview() {
    reviewForm.post(`/marketplace/produit/${props.product.slug || props.product.id}/avis`, {
        preserveScroll: true,
        onSuccess: () => {
            reviewForm.reset('comment', 'order_number');
        },
    });
}

function breakdownPercent(star) {
    const total = Number(props.product.reviews_count || 0);
    if (!total) return 0;
    return Math.round(((props.ratingBreakdown?.[star] || 0) / total) * 100);
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head :title="`${product.title} — Marketplace`" />
    <MarketplaceLayout>
        <div class="container py-4">
            <nav aria-label="breadcrumb" class="small mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><Link href="/marketplace">Marketplace</Link></li>
                    <li v-if="product.category" class="breadcrumb-item">
                        <Link :href="`/marketplace/categorie/${product.category.slug}`">{{ product.category.name }}</Link>
                    </li>
                    <li v-if="product.sub_category" class="breadcrumb-item">
                        <Link :href="`/marketplace/categorie/${product.category.slug}/${product.sub_category.slug}`">{{ product.sub_category.name }}</Link>
                    </li>
                    <li class="breadcrumb-item active text-truncate" style="max-width: 220px;">{{ product.title }}</li>
                </ol>
            </nav>

            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4 mb-5">
                <div class="row g-4">
                    <!-- Galerie multi-images -->
                    <div class="col-lg-6">
                        <div class="bg-light rounded-4 overflow-hidden border mb-3 d-flex align-items-center justify-content-center" style="height: 380px;">
                            <img
                                v-if="product.image_urls?.length"
                                :src="product.image_urls[selectedImageIndex]"
                                :alt="product.title"
                                class="w-100 h-100"
                                style="object-fit: contain;"
                            />
                            <i v-else class="bi bi-car-front fs-1 text-muted"></i>
                        </div>
                        <div v-if="product.image_urls?.length > 1" class="d-flex flex-wrap gap-2">
                            <button
                                v-for="(url, idx) in product.image_urls"
                                :key="url"
                                type="button"
                                class="btn p-0 border rounded-3 overflow-hidden"
                                :class="selectedImageIndex === idx ? 'border-primary border-2 shadow-sm' : 'opacity-75'"
                                style="width: 72px; height: 72px;"
                                @click="selectedImageIndex = idx"
                            >
                                <img :src="url" alt="" class="w-100 h-100" style="object-fit: cover;" />
                            </button>
                        </div>
                    </div>

                    <!-- Infos & Achat -->
                    <div class="col-lg-6 d-flex flex-column">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="badge bg-primary-subtle text-primary">{{ product.category?.name }}</span>
                            <span class="badge bg-dark">{{ product.condition }}</span>
                            <span class="badge bg-info text-dark"><i class="bi bi-geo-alt me-1"></i>{{ product.country_name }}</span>
                        </div>

                        <h1 class="h3 fw-bold mb-2">{{ product.title }}</h1>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="text-warning">
                                <i
                                    v-for="s in 5"
                                    :key="`top-star-${s}`"
                                    class="bi"
                                    :class="s <= Math.round(product.rating_avg || 0) ? 'bi-star-fill' : 'bi-star'"
                                ></i>
                            </span>
                            <a href="#avis-clients" class="small text-decoration-none fw-semibold text-dark">
                                {{ product.reviews_count > 0 ? `${product.rating_avg}/5 (${product.reviews_count} avis)` : 'Aucun avis pour le moment — Donner votre avis' }}
                            </a>
                        </div>
                        <div class="display-6 fw-bold text-primary mb-3">{{ fmt(product.price) }}</div>

                        <div class="p-3 bg-light rounded-3 border small mb-3 vstack gap-1">
                            <div><strong>Vendeur :</strong> {{ product.seller?.name }} {{ product.seller?.city ? `(${product.seller.city})` : '' }}</div>
                            <div v-if="product.brand"><strong>Marque :</strong> {{ product.brand }}</div>
                            <div v-if="product.sku"><strong>Référence SKU :</strong> {{ product.sku }}</div>
                            <div v-if="product.vehicle_compatibility"><strong>Compatibilité :</strong> {{ product.vehicle_compatibility }}</div>
                            <div>
                                <strong>Disponibilité :</strong>
                                <span :class="product.stock_quantity > 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold'">
                                    {{ product.stock_quantity > 0 ? `${product.stock_quantity} en stock` : 'Rupture de stock' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="product.description" class="text-muted small mb-4" v-html="product.description"></div>

                        <div class="mt-auto">
                            <div class="row g-2 align-items-center">
                                <div class="col-4 col-sm-3">
                                    <input
                                        v-model.number="quantity"
                                        type="number"
                                        min="1"
                                        :max="Math.max(1, product.stock_quantity)"
                                        class="form-control"
                                    />
                                </div>
                                <div class="col-8 col-sm-5">
                                    <button
                                        type="button"
                                        class="btn w-100 fw-semibold py-2"
                                        :class="isInCart ? 'btn-warning text-dark' : 'btn-primary'"
                                        @click="handleAdd(false, isInCart)"
                                    >
                                        <i class="bi bi-cart-plus me-2"></i>
                                        {{ isInCart ? `Augmenter la quantité (+${quantity})` : 'Ajouter au panier' }}
                                    </button>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <button type="button" class="btn btn-dark w-100 fw-semibold py-2" @click="handleAdd(true, false)">
                                        Commander
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Avis & Notes par étoiles -->
            <div id="avis-clients" class="card border-0 shadow-sm rounded-4 p-4 mb-5">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <h2 class="h5 fw-bold mb-3">
                            <i class="bi bi-star-fill text-warning me-2"></i>Notes &amp; Avis Clients
                        </h2>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 border mb-3">
                            <div class="text-center px-2">
                                <div class="display-5 fw-bold text-dark lh-1">{{ product.rating_avg || '0.0' }}</div>
                                <div class="text-warning small my-1">
                                    <i
                                        v-for="s in 5"
                                        :key="`sum-star-${s}`"
                                        class="bi"
                                        :class="s <= Math.round(product.rating_avg || 0) ? 'bi-star-fill' : 'bi-star'"
                                    ></i>
                                </div>
                                <div class="small text-muted">{{ product.reviews_count || 0 }} avis</div>
                            </div>
                            <div class="flex-grow-1 vstack gap-1">
                                <div v-for="star in [5, 4, 3, 2, 1]" :key="`bar-${star}`" class="d-flex align-items-center gap-2 small">
                                    <span class="text-nowrap" style="width: 30px;">{{ star }} ★</span>
                                    <div class="progress flex-grow-1" style="height: 8px;">
                                        <div class="progress-bar bg-warning" :style="{ width: `${breakdownPercent(star)}%` }"></div>
                                    </div>
                                    <span class="text-muted text-end" style="width: 28px;">{{ ratingBreakdown?.[star] || 0 }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Formulaire d'avis sécurisé -->
                        <form class="border rounded-3 p-3 bg-white" @submit.prevent="submitReview">
                            <h3 class="h6 fw-bold mb-2">Donner votre avis</h3>
                            <p class="small text-muted mb-3">
                                <i class="bi bi-shield-check text-success me-1"></i>
                                1 avis par client/adresse e-mail. Indiquez l'e-mail ou le n° de commande de votre achat pour obtenir le badge <strong>Achat vérifié</strong>.
                            </p>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold d-block mb-1">Votre note *</label>
                                <div class="d-flex align-items-center gap-1 fs-4">
                                    <button
                                        v-for="s in 5"
                                        :key="`pick-${s}`"
                                        type="button"
                                        class="btn btn-link p-0 text-warning border-0"
                                        @mouseenter="hoverRating = s"
                                        @mouseleave="hoverRating = 0"
                                        @click="reviewForm.rating = s"
                                    >
                                        <i class="bi" :class="s <= (hoverRating || reviewForm.rating) ? 'bi-star-fill' : 'bi-star'"></i>
                                    </button>
                                    <span class="small text-muted ms-2">{{ reviewForm.rating }}/5</span>
                                </div>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-sm-6">
                                    <label class="form-label small mb-1">Nom *</label>
                                    <input v-model="reviewForm.reviewer_name" type="text" class="form-control form-control-sm" required />
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small mb-1">Email *</label>
                                    <input v-model="reviewForm.reviewer_email" type="email" class="form-control form-control-sm" required />
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small mb-1">N° de commande (optionnel, pour badge vérifié)</label>
                                <input v-model="reviewForm.order_number" type="text" class="form-control form-control-sm" placeholder="Ex: CMD-..." />
                            </div>

                            <!-- Honeypot invisible anti-bot -->
                            <input v-model="reviewForm.website" type="text" tabindex="-1" autocomplete="off" style="position: absolute; left: -9999px; opacity: 0;" />

                            <div class="mb-3">
                                <label class="form-label small mb-1">Commentaire</label>
                                <textarea v-model="reviewForm.comment" rows="3" class="form-control form-control-sm" placeholder="Qualité de la pièce, délai de livraison..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold" :disabled="reviewForm.processing">
                                Publier mon avis
                            </button>
                        </form>
                    </div>

                    <div class="col-lg-7">
                        <h3 class="h6 fw-bold mb-3">Derniers avis publiés</h3>
                        <div v-if="!reviews.length" class="text-center text-muted py-5 border rounded-3 bg-light">
                            <i class="bi bi-chat-square-text fs-2 d-block mb-2"></i>
                            Aucun avis pour cette pièce pour l'instant. Soyez le premier à noter !
                        </div>
                        <div v-else class="vstack gap-3">
                            <div v-for="rev in reviews" :key="rev.id" class="border rounded-3 p-3 bg-light bg-opacity-50">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold small text-dark">{{ rev.reviewer_name }}</span>
                                        <span v-if="rev.is_verified_purchase" class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-patch-check-fill me-1"></i>Achat vérifié
                                        </span>
                                    </div>
                                    <span class="small text-muted">{{ new Date(rev.created_at).toLocaleDateString('fr-FR') }}</span>
                                </div>
                                <div class="text-warning small mb-1">
                                    <i v-for="s in 5" :key="`rv-${rev.id}-${s}`" class="bi" :class="s <= rev.rating ? 'bi-star-fill' : 'bi-star'"></i>
                                </div>
                                <p v-if="rev.comment" class="small text-dark mb-0">{{ rev.comment }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Produits similaires -->
            <div v-if="relatedProducts.length">
                <h2 class="h5 fw-bold mb-3">Pièces similaires</h2>
                <div class="row g-3">
                    <div v-for="rp in relatedProducts" :key="rp.id" class="col-6 col-md-3">
                        <MarketplaceProductCard
                            :product="rp"
                            @add-to-cart="addToCart($event.id, 1, false)"
                            @quick-view="quickProduct = $event"
                        />
                    </div>
                </div>
            </div>
        </div>

        <MarketplaceQuickViewModal :product="quickProduct" />
    </MarketplaceLayout>
</template>
