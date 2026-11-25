<script setup>
import MarketplaceProductCard from '@/Components/MarketplaceProductCard.vue';
import MarketplaceQuickViewModal from '@/Components/MarketplaceQuickViewModal.vue';
import { useI18n } from '@/composables/useI18n';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout.vue';
import { useMarketplaceCart } from '@/composables/useMarketplaceCart';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const { t } = useI18n();

const props = defineProps({
    products: Object,
    bestSellers: { type: Array, default: () => [] },
    newProducts: { type: Array, default: () => [] },
    banners: { type: Object, default: () => ({}) },
    hasActiveFilters: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
    isBestSellers: { type: Boolean, default: false },
    filters: Object,
});

const { addToCart } = useMarketplaceCart();

const quickProduct = ref(null);
const currentYear = new Date().getFullYear();
const yearOptions = Array.from({ length: Math.max(1, currentYear - 1960 + 1) }, (_, i) => currentYear - i);

const vehicleFilter = reactive({
    vehicle_brand: props.filters?.vehicle_brand || '',
    vehicle_model: props.filters?.vehicle_model || '',
    vehicle_year: props.filters?.vehicle_year || '',
});

function applyVehicleFilter() {
    router.get('/marketplace', {
        ...props.filters,
        vehicle_brand: vehicleFilter.vehicle_brand || undefined,
        vehicle_model: vehicleFilter.vehicle_model || undefined,
        vehicle_year: vehicleFilter.vehicle_year || undefined,
    }, { preserveScroll: true });
}

const topBanner = computed(() => {
    if (props.banners?.top) {
        return props.banners.top;
    }

    return {
        title: 'Offres Spéciales Pièces & Entretien Auto',
        subtitle: 'Profitez des meilleurs prix sur les pièces d\'origine et adaptables livrées rapidement partout en Afrique.',
        image_url: '/images/hero-garage.jpg',
        gradient_from: '#0f172a',
        gradient_to: '#1d4ed8',
        button_text: 'Découvrir les bons plans',
        button_url: '/marketplace/bons-plans',
        is_external: false,
    };
});

function openQuickView(product) {
    quickProduct.value = product;
}

function bannerStyle(banner) {
    const from = banner.gradient_from || '#0f172a';
    const to = banner.gradient_to || '#2563eb';
    const imgPart = banner.image_url ? `, url('${banner.image_url}') center/cover no-repeat` : '';
    return `background: linear-gradient(120deg, ${from}ee 0%, ${to}cc 100%)${imgPart};`;
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head :title="isBestSellers ? 'Bons plans — Pièces les plus vendues' : 'Marketplace Pièces Automobiles'" />
    <MarketplaceLayout>
        <!-- Bannière Header épurée (collée directement au menu) -->
        <section
            class="text-white py-4"
            style="background: linear-gradient(125deg, #0f172a 0%, #1e3a8a 65%, #2563eb 100%);"
        >
            <div class="container py-2">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <span class="badge rounded-pill px-3 py-1 mb-2 fw-semibold" :class="isBestSellers ? 'bg-danger text-white' : 'bg-warning text-dark'">
                            <i class="bi me-1" :class="isBestSellers ? 'bi-fire' : 'bi-shop'"></i>
                            {{ isBestSellers ? 'Bons plans • Top des ventes' : 'Marketplace Pièces Automobiles' }}
                        </span>
                        <h1 class="h2 fw-bold mb-1">
                            {{ isBestSellers ? 'Les pièces les plus vendues du moment' : 'Trouvez la bonne pièce auto partout en Afrique' }}
                        </h1>
                        <p class="text-white-50 small mb-0">
                            {{ isBestSellers
                                ? 'Découvrez les pièces automobiles les plus commandées par les automobilistes et ateliers.'
                                : 'Pièces neuves, d\'occasion ou adaptables proposées directement par des garages professionnels vérifiés.' }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <div class="container py-4">
            <!-- Recherche par véhicule (Marque / Modèle / Année) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-3">
                    <form class="row g-2 align-items-end" @submit.prevent="applyVehicleFilter">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1"><i class="bi bi-car-front text-primary me-1"></i>Marque</label>
                            <input v-model="vehicleFilter.vehicle_brand" type="text" class="form-control form-control-sm" placeholder="Ex: Toyota, Peugeot..." />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Modèle</label>
                            <input v-model="vehicleFilter.vehicle_model" type="text" class="form-control form-control-sm" placeholder="Ex: Corolla, 308..." />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold mb-1">{{ t('layoutExtra.year') }}</label>
                            <select v-model="vehicleFilter.vehicle_year" class="form-select form-select-sm">
                                <option value="">{{ t('layoutExtra.allYears') }}</option>
                                <option v-for="y in yearOptions" :key="y" :value="String(y)">{{ y }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                <i class="bi bi-search me-1"></i>Filtrer par véhicule
                            </button>
                            <Link v-if="hasActiveFilters" href="/marketplace" class="btn btn-outline-secondary btn-sm">
                                Réinitialiser
                            </Link>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bannière publicitaire principale (gérée par l'Admin ou par défaut) -->
            <div v-if="topBanner" class="rounded-4 text-white p-4 p-md-5 mb-4 shadow-sm overflow-hidden" :style="bannerStyle(topBanner)">
                <div class="row align-items-center g-3">
                    <div class="col-lg-8">
                        <span class="badge bg-warning text-dark rounded-pill px-3 py-1 mb-2 fw-semibold">
                            <i class="bi bi-megaphone-fill me-1"></i>Sponsorisé • Bon plan
                        </span>
                        <h2 class="h3 fw-bold mb-2">{{ topBanner.title }}</h2>
                        <p v-if="topBanner.subtitle" class="text-white-50 mb-0">{{ topBanner.subtitle }}</p>
                    </div>
                    <div v-if="topBanner.button_url" class="col-lg-4 text-lg-end">
                        <a
                            v-if="topBanner.is_external"
                            :href="topBanner.button_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btn btn-warning btn-lg fw-semibold text-dark px-4 shadow"
                        >
                            {{ topBanner.button_text }} <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                        </a>
                        <Link v-else :href="topBanner.button_url" class="btn btn-warning btn-lg fw-semibold text-dark px-4 shadow">
                            {{ topBanner.button_text }} <i class="bi bi-arrow-right ms-1"></i>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- MODE FILTRÉ OU BONS PLANS -->
            <template v-if="hasActiveFilters || isBestSellers">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 fw-bold mb-0">{{ isBestSellers ? 'Top des ventes (Bons plans)' : 'Résultats de la recherche' }}</h2>
                    <span class="small text-muted">{{ products.total ?? products.data?.length ?? 0 }} pièce(s)</span>
                </div>

                <div v-if="!products.data?.length" class="card border shadow-sm p-5 text-center text-muted">
                    <i class="bi bi-box-seam fs-1 text-primary mb-2"></i>
                    <div class="fw-semibold">Aucune pièce trouvée pour ces critères.</div>
                </div>

                <div v-else class="row g-3">
                    <div v-for="p in products.data" :key="p.id" class="col-sm-6 col-md-4 col-lg-3">
                        <MarketplaceProductCard
                            :product="p"
                            @add-to-cart="addToCart($event.id, 1, false)"
                            @quick-view="openQuickView"
                        />
                    </div>
                </div>
            </template>

            <!-- MODE 3 LIGNES + 2 BANNIÈRES PUBLICITAIRES ADMIN -->
            <template v-else>
                <!-- LIGNE 1 : Les produits les plus vendus / les plus consultés -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-fire text-danger me-2"></i>{{ t('mp.bestSellers') }}</h2>
                    <Link href="/marketplace/bons-plans" class="small fw-semibold text-decoration-none">{{ t('mp.seeAll') }} <i class="bi bi-arrow-right"></i></Link>
                </div>
                <div class="row g-3 mb-4">
                    <div v-for="p in bestSellers" :key="`bs-${p.id}`" class="col-sm-6 col-md-4 col-lg-3">
                        <MarketplaceProductCard
                            :product="p"
                            badge-text="Top"
                            badge-class="bg-danger"
                            @add-to-cart="addToCart($event.id, 1, false)"
                            @quick-view="openQuickView"
                        />
                    </div>
                </div>

                <!-- BANNIÈRE PUBLICITAIRE 1 (gérée par l'Admin) -->
                <div v-if="banners?.row_1" class="rounded-4 text-white p-4 p-md-5 mb-5 shadow-sm overflow-hidden" :style="bannerStyle(banners.row_1)">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8">
                            <h3 class="h3 fw-bold mb-2">{{ banners.row_1.title }}</h3>
                            <p v-if="banners.row_1.subtitle" class="text-white-50 mb-0">{{ banners.row_1.subtitle }}</p>
                        </div>
                        <div v-if="banners.row_1.button_url" class="col-lg-4 text-lg-end">
                            <a
                                v-if="banners.row_1.is_external"
                                :href="banners.row_1.button_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-warning btn-lg fw-semibold text-dark px-4 shadow"
                            >
                                {{ banners.row_1.button_text }} <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                            </a>
                            <Link v-else :href="banners.row_1.button_url" class="btn btn-warning btn-lg fw-semibold text-dark px-4 shadow">
                                {{ banners.row_1.button_text }} <i class="bi bi-arrow-right ms-1"></i>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- LIGNE 2 : Nouveaux produits -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-stars text-primary me-2"></i>{{ t('mp.newProducts') }}</h2>
                </div>
                <div class="row g-3 mb-4">
                    <div v-for="p in newProducts" :key="`new-${p.id}`" class="col-sm-6 col-md-4 col-lg-3">
                        <MarketplaceProductCard
                            :product="p"
                            badge-text="Nouveau"
                            badge-class="bg-success"
                            @add-to-cart="addToCart($event.id, 1, false)"
                            @quick-view="openQuickView"
                        />
                    </div>
                </div>

                <!-- BANNIÈRE PUBLICITAIRE 2 (gérée par l'Admin) -->
                <div v-if="banners?.row_2" class="rounded-4 text-white p-4 p-md-5 mb-5 shadow-sm overflow-hidden" :style="bannerStyle(banners.row_2)">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8">
                            <h3 class="h3 fw-bold mb-2">{{ banners.row_2.title }}</h3>
                            <p v-if="banners.row_2.subtitle" class="text-white-50 mb-0">{{ banners.row_2.subtitle }}</p>
                        </div>
                        <div v-if="banners.row_2.button_url" class="col-lg-4 text-lg-end">
                            <a
                                v-if="banners.row_2.is_external"
                                :href="banners.row_2.button_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-light btn-lg fw-semibold text-dark px-4 shadow"
                            >
                                {{ banners.row_2.button_text }} <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                            </a>
                            <Link v-else :href="banners.row_2.button_url" class="btn btn-light btn-lg fw-semibold text-dark px-4 shadow">
                                {{ banners.row_2.button_text }} <i class="bi bi-arrow-right ms-1"></i>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- LIGNE 3 : Les autres produits -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 fw-bold mb-0"><i class="bi bi-grid-fill text-secondary me-2"></i>{{ t('mp.otherProducts') }}</h2>
                    <span class="small text-muted">{{ products.total ?? products.data?.length ?? 0 }} pièce(s)</span>
                </div>
                <div class="row g-3">
                    <div v-for="p in products.data" :key="`all-${p.id}`" class="col-sm-6 col-md-4 col-lg-3">
                        <MarketplaceProductCard
                            :product="p"
                            @add-to-cart="addToCart($event.id, 1, false)"
                            @quick-view="openQuickView"
                        />
                    </div>
                </div>
            </template>
        </div>

        <MarketplaceQuickViewModal :product="quickProduct" />
    </MarketplaceLayout>
</template>
