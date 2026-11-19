<script setup>
import { useI18n } from '@/composables/useI18n';
import { Link } from '@inertiajs/vue3';

const { t } = useI18n();

defineProps({
    product: { type: Object, required: true },
    badgeText: { type: String, default: '' },
    badgeClass: { type: String, default: '' },
});

defineEmits(['add-to-cart', 'quick-view']);

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}

function defaultBadgeClass(condition) {
    if (condition === 'neuf') return 'bg-primary';
    if (condition === 'occasion') return 'bg-warning text-dark';
    return 'bg-secondary';
}
</script>

<template>
    <div class="card h-100 rounded-4 bg-white overflow-hidden mp-product-card">
        <div
            class="position-relative p-2 text-center border-bottom overflow-hidden"
            style="height: 195px; background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);"
        >
            <span
                class="badge rounded-pill position-absolute top-0 start-0 m-2 px-2 py-1 shadow-sm"
                style="z-index: 2; font-size: 0.72rem;"
                :class="badgeClass || defaultBadgeClass(product.condition)"
            >
                {{ badgeText || product.condition }}
            </span>

            <span
                v-if="product.country_name"
                class="badge rounded-pill bg-white text-dark border position-absolute top-0 end-0 m-2 px-2 py-1 shadow-sm"
                style="z-index: 2; font-size: 0.7rem;"
            >
                <i class="bi bi-geo-alt-fill text-primary me-1"></i>{{ product.country_name }}
            </span>

            <Link :href="`/marketplace/produit/${product.slug || product.id}`" class="d-block w-100 h-100 overflow-hidden rounded-3">
                <img
                    v-if="product.primary_image"
                    :src="product.primary_image"
                    :alt="product.title"
                    class="w-100 h-100 rounded-3 mp-card-img"
                    style="object-fit: cover;"
                    loading="lazy"
                />
                <div v-else class="w-100 h-100 d-flex align-items-center justify-content-center text-muted bg-light rounded-3">
                    <i class="bi bi-car-front fs-1"></i>
                </div>
            </Link>
        </div>

        <div class="card-body d-flex flex-column p-3">
            <div v-if="product.category?.name" class="text-uppercase text-primary fw-bold mb-1 text-truncate" style="font-size: 0.68rem; letter-spacing: 0.04em;">
                {{ product.category.name }}
            </div>

            <Link
                :href="`/marketplace/produit/${product.slug || product.id}`"
                class="text-decoration-none text-dark fw-bold small mb-1"
                style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.5rem;"
            >
                {{ product.title }}
            </Link>

            <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.72rem;">
                <span class="text-warning">
                    <i
                        v-for="s in 5"
                        :key="s"
                        class="bi"
                        :class="s <= Math.round(product.rating_avg || 0) ? 'bi-star-fill' : 'bi-star'"
                    ></i>
                </span>
                <span v-if="product.reviews_count > 0" class="text-dark fw-semibold">{{ product.rating_avg }}</span>
                <span class="text-muted text-truncate">
                    <template v-if="product.reviews_count > 0">({{ product.reviews_count }})</template>
                    • {{ product.brand || product.seller?.name }}
                </span>
            </div>

            <div class="small mb-2" style="font-size: 0.76rem;">
                <span v-if="product.stock_quantity > 0" class="text-success fw-semibold">
                    <i class="bi bi-check-circle-fill me-1"></i>{{ t('mp.inStock') }} ({{ product.stock_quantity }})
                </span>
                <span v-else class="text-danger fw-semibold">
                    <i class="bi bi-x-circle-fill me-1"></i>{{ t('mp.outOfStock') }}
                </span>
            </div>

            <div class="h5 fw-bold text-dark mt-auto mb-3">{{ fmt(product.price) }}</div>

            <div class="d-flex align-items-center gap-2">
                <button
                    type="button"
                    class="btn btn-primary btn-sm flex-grow-1 fw-semibold py-2 rounded-3 shadow-sm"
                    :disabled="product.stock_quantity <= 0"
                    @click="$emit('add-to-cart', product)"
                >
                    <i class="bi bi-cart-plus me-1"></i>{{ t('mp.add') }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0"
                    style="width: 38px; height: 38px;"
                    :title="t('mp.quickView')"
                    data-bs-toggle="modal"
                    data-bs-target="#modalProductQuickView"
                    @click="$emit('quick-view', product)"
                >
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>
    </div>
</template>
