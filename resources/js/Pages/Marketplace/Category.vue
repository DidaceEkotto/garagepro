<script setup>
import MarketplaceProductCard from '@/Components/MarketplaceProductCard.vue';
import MarketplaceQuickViewModal from '@/Components/MarketplaceQuickViewModal.vue';
import { useI18n } from '@/composables/useI18n';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout.vue';
import { useMarketplaceCart } from '@/composables/useMarketplaceCart';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref, watch } from 'vue';

const { t } = useI18n();

const props = defineProps({
    category: Object,
    subCategory: { type: Object, default: null },
    products: Object,
    countries: { type: Array, default: () => [] },
    selectedCountry: { type: String, default: '' },
    priceBounds: { type: Object, default: () => ({ min: 0, max: 500000 }) },
    filters: { type: Object, default: () => ({}) },
});

const { addToCart } = useMarketplaceCart();
const quickProduct = ref(null);

const f = reactive({
    country: props.filters?.country ?? props.selectedCountry ?? '',
    in_stock: Boolean(props.filters?.in_stock ?? false),
    condition: props.filters?.condition ?? '',
    min_price: Number(props.filters?.min_price ?? props.priceBounds?.min ?? 0),
    max_price: Number(props.filters?.max_price ?? props.priceBounds?.max ?? 500000),
    sort: props.filters?.sort ?? 'recent',
});

watch(
    () => [props.category?.id, props.subCategory?.id, props.filters, props.priceBounds],
    () => {
        f.country = props.filters?.country ?? props.selectedCountry ?? '';
        f.in_stock = Boolean(props.filters?.in_stock ?? false);
        f.condition = props.filters?.condition ?? '';
        f.min_price = Number(props.filters?.min_price ?? props.priceBounds?.min ?? 0);
        f.max_price = Number(props.filters?.max_price ?? props.priceBounds?.max ?? 500000);
        f.sort = props.filters?.sort ?? 'recent';
        nextTick(() => {
            if (window.$ && window.$.fn?.select2) {
                window.$('.s2-cat-filter').each(function () {
                    window.$(this).trigger('change.select2');
                });
            }
        });
    },
    { deep: true },
);

const baseUrl = computed(() => {
    return props.subCategory
        ? `/marketplace/categorie/${props.category.slug}/${props.subCategory.slug}`
        : `/marketplace/categorie/${props.category.slug}`;
});

const hasActiveFilters = computed(() => {
    const minB = Number(props.priceBounds?.min ?? 0);
    const maxB = Number(props.priceBounds?.max ?? 500000);
    return (
        Boolean(f.country) ||
        f.in_stock ||
        Boolean(f.condition) ||
        f.min_price > minB ||
        f.max_price < maxB ||
        (f.sort && f.sort !== 'recent')
    );
});

const priceStep = computed(() => {
    const span = Math.max(1, (props.priceBounds?.max ?? 500000) - (props.priceBounds?.min ?? 0));
    return span > 50000 ? 1000 : 500;
});

function onMinRangeInput() {
    if (Number(f.min_price) > Number(f.max_price)) {
        f.min_price = Number(f.max_price);
    }
}

function onMaxRangeInput() {
    if (Number(f.max_price) < Number(f.min_price)) {
        f.max_price = Number(f.min_price);
    }
}

function applyFilters() {
    const minB = Number(props.priceBounds?.min ?? 0);
    const maxB = Number(props.priceBounds?.max ?? 500000);

    router.get(
        baseUrl.value,
        {
            country: f.country || undefined,
            in_stock: f.in_stock ? 1 : undefined,
            condition: f.condition || undefined,
            min_price: f.min_price > minB ? f.min_price : undefined,
            max_price: f.max_price < maxB ? f.max_price : undefined,
            sort: f.sort && f.sort !== 'recent' ? f.sort : undefined,
        },
        { preserveState: true, replace: true, preserveScroll: true },
    );
}

function resetFilters() {
    f.country = '';
    f.in_stock = false;
    f.condition = '';
    f.min_price = Number(props.priceBounds?.min ?? 0);
    f.max_price = Number(props.priceBounds?.max ?? 500000);
    f.sort = 'recent';
    nextTick(() => {
        if (window.$ && window.$.fn?.select2) {
            window.$('.s2-cat-filter').val('').trigger('change.select2');
            window.$('#catSortSelect').val('recent').trigger('change.select2');
        }
    });
    router.get(baseUrl.value, {}, { preserveState: true, replace: true, preserveScroll: true });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head :title="`${subCategory ? subCategory.name : category.name} — Marketplace`" />
    <MarketplaceLayout>
        <div class="bg-white border-bottom py-4">
            <div class="container">
                <nav aria-label="breadcrumb" class="small mb-2">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><Link href="/marketplace">Marketplace</Link></li>
                        <li class="breadcrumb-item" :class="{ active: !subCategory }">
                            <Link v-if="subCategory" :href="`/marketplace/categorie/${category.slug}`">{{ category.name }}</Link>
                            <span v-else>{{ category.name }}</span>
                        </li>
                        <li v-if="subCategory" class="breadcrumb-item active">{{ subCategory.name }}</li>
                    </ol>
                </nav>
                <div>
                    <h1 class="h3 fw-bold mb-1">
                        <i :class="`bi ${category.icon} text-primary me-2`"></i>{{ subCategory ? subCategory.name : category.name }}
                    </h1>
                    <p v-if="category.description" class="text-muted small mb-0">{{ category.description }}</p>
                </div>

                <!-- Sous-catégories -->
                <div v-if="category.sub_categories?.length" class="d-flex flex-wrap gap-2 mt-3">
                    <Link
                        :href="`/marketplace/categorie/${category.slug}`"
                        class="btn btn-sm rounded-pill px-3"
                        :class="!subCategory ? 'btn-primary' : 'btn-outline-secondary'"
                    >
                        Tout dans {{ category.name }}
                    </Link>
                    <Link
                        v-for="sc in category.sub_categories"
                        :key="sc.id"
                        :href="`/marketplace/categorie/${category.slug}/${sc.slug}`"
                        class="btn btn-sm rounded-pill px-3"
                        :class="subCategory?.id === sc.id ? 'btn-primary' : 'btn-outline-primary'"
                    >
                        {{ sc.name }}
                    </Link>
                </div>
            </div>
        </div>

        <div class="container py-4">
            <div class="row g-4">
                <!-- Menu vertical des filtres (côté gauche) -->
                <aside class="col-lg-3">
                    <div class="card border-0 shadow-sm rounded-4 p-3 sticky-lg-top" style="top: 80px; z-index: 1005;">
                        <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                            <h2 class="h6 fw-bold mb-0">
                                <i class="bi bi-sliders text-primary me-2"></i>{{ t('mp.filters') }}
                            </h2>
                            <button
                                v-if="hasActiveFilters"
                                type="button"
                                class="btn btn-link btn-sm text-decoration-none p-0 small"
                                @click="resetFilters"
                            >
                                {{ t('mp.reset') }}
                            </button>
                        </div>

                        <div class="vstack gap-3">
                            <!-- Pays (Select2) -->
                            <div>
                                <label class="form-label small fw-semibold mb-1">{{ t('mp.country') }}</label>
                                <select
                                    v-model="f.country"
                                    class="form-select form-select-sm s2-cat-filter"
                                    @change="applyFilters"
                                >
                                    <option value="">{{ t('mp.allCountries') }}</option>
                                    <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
                                </select>
                            </div>

                            <!-- Disponibilité : En stock -->
                            <div class="border-top pt-3">
                                <label class="form-label small fw-semibold mb-2 d-block">{{ t('mp.availability') }}</label>
                                <div class="form-check form-switch">
                                    <input
                                        id="filterInStock"
                                        v-model="f.in_stock"
                                        class="form-check-input"
                                        type="checkbox"
                                        @change="applyFilters"
                                    />
                                    <label class="form-check-label small" for="filterInStock">
                                        {{ t('mp.inStockOnly') }}
                                    </label>
                                </div>
                            </div>

                            <!-- État de la pièce (Select2) -->
                            <div class="border-top pt-3">
                                <label class="form-label small fw-semibold mb-1">{{ t('mp.condition') }}</label>
                                <select
                                    v-model="f.condition"
                                    class="form-select form-select-sm s2-cat-filter"
                                    @change="applyFilters"
                                >
                                    <option value="">{{ t('mp.allConditions') }}</option>
                                    <option value="neuf">{{ t('mp.condNew') }}</option>
                                    <option value="occasion">{{ t('mp.condUsed') }}</option>
                                    <option value="adaptable">{{ t('mp.condAdaptable') }}</option>
                                </select>
                            </div>

                            <!-- Slide (Range) Prix -->
                            <div class="border-top pt-3">
                                <label class="form-label small fw-semibold mb-1 d-flex justify-content-between">
                                    <span>{{ t('mp.budgetPrice') }}</span>
                                </label>
                                <div class="small text-primary fw-semibold mb-2">
                                    {{ fmt(f.min_price) }} — {{ fmt(f.max_price) }}
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small text-muted mb-0" style="font-size: 0.75rem;">
                                        {{ t('mp.minPrice') }} : {{ fmt(f.min_price) }}
                                    </label>
                                    <input
                                        v-model.number="f.min_price"
                                        type="range"
                                        class="form-range"
                                        :min="priceBounds.min"
                                        :max="priceBounds.max"
                                        :step="priceStep"
                                        @input="onMinRangeInput"
                                        @change="applyFilters"
                                    />
                                </div>

                                <div>
                                    <label class="form-label small text-muted mb-0" style="font-size: 0.75rem;">
                                        {{ t('mp.maxPrice') }} : {{ fmt(f.max_price) }}
                                    </label>
                                    <input
                                        v-model.number="f.max_price"
                                        type="range"
                                        class="form-range"
                                        :min="priceBounds.min"
                                        :max="priceBounds.max"
                                        :step="priceStep"
                                        @input="onMaxRangeInput"
                                        @change="applyFilters"
                                    />
                                </div>
                            </div>

                            <!-- Trier par (Select2) -->
                            <div class="border-top pt-3">
                                <label class="form-label small fw-semibold mb-1">{{ t('mp.sortBy') }}</label>
                                <select
                                    id="catSortSelect"
                                    v-model="f.sort"
                                    class="form-select form-select-sm s2-cat-filter"
                                    @change="applyFilters"
                                >
                                    <option value="recent">{{ t('mp.sortRecent') }}</option>
                                    <option value="price_asc">{{ t('mp.sortPriceAsc') }}</option>
                                    <option value="price_desc">{{ t('mp.sortPriceDesc') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- Grille des produits (côté droit) -->
                <div class="col-lg-9">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="small text-muted fw-medium">
                            {{ products.total ?? products.data?.length ?? 0 }} pièce(s) trouvée(s)
                        </span>
                    </div>

                    <div v-if="!products.data?.length" class="card border-0 shadow-sm rounded-4 p-5 text-center text-muted">
                        <i class="bi bi-box-seam fs-1 text-primary mb-2"></i>
                        <div class="fw-semibold mb-2">Aucun produit disponible pour ces critères.</div>
                        <div v-if="hasActiveFilters">
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" @click="resetFilters">
                                Réinitialiser les filtres
                            </button>
                        </div>
                    </div>

                    <div v-else class="row g-3">
                        <div v-for="p in products.data" :key="p.id" class="col-sm-6 col-md-4">
                            <MarketplaceProductCard
                                :product="p"
                                @add-to-cart="addToCart($event.id, 1, false)"
                                @quick-view="quickProduct = $event"
                            />
                        </div>
                    </div>

                    <!-- Pagination -->
                    <nav v-if="products.links?.length > 3" class="mt-4 d-flex justify-content-center">
                        <ul class="pagination pagination-sm mb-0">
                            <li
                                v-for="(link, idx) in products.links"
                                :key="idx"
                                class="page-item"
                                :class="{ active: link.active, disabled: !link.url }"
                            >
                                <Link v-if="link.url" :href="link.url" class="page-link" v-html="link.label" />
                                <span v-else class="page-link" v-html="link.label" />
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
        <MarketplaceQuickViewModal :product="quickProduct" />
    </MarketplaceLayout>
</template>
