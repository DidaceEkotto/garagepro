<script setup>
import BackToTop from '@/Components/BackToTop.vue';
import ConfirmStaticModal from '@/Components/ConfirmStaticModal.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import PageSkeleton from '@/Components/PageSkeleton.vue';
import { useI18n } from '@/composables/useI18n';
import { useMarketplaceCart } from '@/composables/useMarketplaceCart';
import { usePageLoading } from '@/composables/usePageLoading';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { t } = useI18n();
const { isNavigating } = usePageLoading();
const page = usePage();
const flash = computed(() => page.props.flash);
const appName = computed(() => page.props.app?.name ?? 'MboaCar');
const megaCategories = computed(() => page.props.marketplaceCategories ?? []);
const megaMenuOpen = ref(false);

const { cart, toast, addToCart, updateQuantity, removeItem, hideToast } = useMarketplaceCart();

// Recherche Full-Screen Static Modal (min 3 caractères + autocomplétion)
const searchQuery = ref('');
const searchResults = ref([]);
const searchLoading = ref(false);
const hasSearched = ref(false);
let searchDebounce = null;

function onSearchInput() {
    const q = searchQuery.value.trim();
    if (searchDebounce) clearTimeout(searchDebounce);

    if (q.length < 3) {
        searchResults.value = [];
        hasSearched.value = false;
        searchLoading.value = false;
        return;
    }

    searchLoading.value = true;
    searchDebounce = setTimeout(async () => {
        try {
            const res = await fetch(`/marketplace/api/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            searchResults.value = data.items || [];
            hasSearched.value = true;
        } finally {
            searchLoading.value = false;
        }
    }, 250);
}

function closeSearchModal() {
    window.bootstrap?.Modal.getInstance(document.getElementById('modalMarketplaceSearch'))?.hide();
}

function goToCartRoute(url) {
    const el = document.getElementById('offcanvasCartDrawer');
    if (el && window.bootstrap) {
        window.bootstrap.Offcanvas.getOrCreateInstance(el).hide();
    }
    document.querySelectorAll('.offcanvas-backdrop').forEach((b) => b.remove());
    document.body.classList.remove('overflow-hidden');
    document.body.style.removeProperty('padding-right');
    router.visit(url);
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <div class="min-vh-100 d-flex flex-column bg-light">
        <nav class="navbar navbar-expand-lg navbar-dark sticky-top shadow-sm" style="background: rgba(15, 23, 42, 0.96); backdrop-filter: blur(10px); z-index: 1030;">
            <div class="container py-1">
                <Link class="navbar-brand fw-bold d-flex align-items-center gap-2" href="/">
                    <img
                        :src="page.props.app?.site?.logo_url || '/assets/images/logo.png'"
                        :alt="appName"
                        class="bg-white rounded-3 px-2 py-1 shadow-sm"
                        style="height: 38px; width: auto; object-fit: contain;"
                    />
                </Link>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mpNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div id="mpNav" class="collapse navbar-collapse mobile-nav-collapse">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                        <li class="nav-item"><Link class="nav-link text-white" href="/">{{ t('mp.home') }}</Link></li>
                        <li class="nav-item"><Link class="nav-link text-warning fw-semibold" href="/marketplace"><i class="bi bi-shop me-1"></i>{{ t('mp.marketplace') }}</Link></li>
                    </ul>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <!-- Icône Recherche (avant Connexion & Panier) -->
                        <button
                            type="button"
                            class="btn btn-outline-light btn-sm rounded-circle d-inline-flex align-items-center justify-content-center"
                            style="width: 36px; height: 36px;"
                            title="Rechercher une pièce"
                            data-bs-toggle="modal"
                            data-bs-target="#modalMarketplaceSearch"
                        >
                            <i class="bi bi-search"></i>
                        </button>

                        <LanguageSwitcher variant="light" />
                        <Link href="/connexion" class="btn btn-outline-light btn-sm rounded-pill px-3">{{ t('mp.login') }}</Link>

                        <!-- Icône Panier (après le bouton Connexion) -> ouvre le menu latéral -->
                        <button
                            type="button"
                            class="btn btn-warning btn-sm rounded-circle position-relative d-inline-flex align-items-center justify-content-center text-dark fw-bold"
                            style="width: 38px; height: 38px;"
                            title="Mon panier"
                            data-bs-toggle="offcanvas"
                            data-bs-target="#offcanvasCartDrawer"
                        >
                            <i class="bi bi-cart3 fs-6"></i>
                            <span
                                v-if="cart.count > 0"
                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                style="font-size: 0.65rem;"
                            >
                                {{ cart.count }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Sous-barre blanche Catégories (Mega-Menu au clic) + Bons plans à l'extrême droite -->
        <div v-if="megaCategories.length" class="bg-white border-bottom shadow-sm position-relative" style="z-index: 1010;">
            <div class="container py-2 d-flex align-items-center justify-content-between gap-3 small fw-semibold">
                <div class="d-flex align-items-center gap-3 overflow-auto text-nowrap">
                    <button
                        type="button"
                        class="btn btn-light btn-sm border fw-bold d-inline-flex align-items-center gap-2 rounded-pill px-3"
                        @click="megaMenuOpen = !megaMenuOpen"
                    >
                        <i class="bi bi-grid-3x3-gap text-primary"></i>
                        <span>{{ t('mp.categories') }}</span>
                        <i class="bi" :class="megaMenuOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </button>
                    <Link href="/marketplace" class="text-decoration-none text-dark" @click="megaMenuOpen = false">
                        {{ t('mp.allParts') }}
                    </Link>
                    <Link
                        v-for="cat in megaCategories.slice(0, 4)"
                        :key="cat.id"
                        :href="`/marketplace/categorie/${cat.slug}`"
                        class="text-decoration-none text-muted d-none d-lg-inline"
                        @click="megaMenuOpen = false"
                    >
                        {{ cat.name }}
                    </Link>
                </div>

                <!-- Extrême droite : Bons plans (pièces les plus vendues) -->
                <Link
                    href="/marketplace/bons-plans"
                    class="text-decoration-none text-danger fw-bold d-inline-flex align-items-center gap-2 text-nowrap ms-auto"
                    @click="megaMenuOpen = false"
                >
                    <span
                        class="rounded-circle border border-danger d-inline-flex align-items-center justify-content-center"
                        style="width: 24px; height: 24px; font-size: 0.72rem;"
                    >
                        %
                    </span>
                    <span>{{ t('mp.deals') }}</span>
                </Link>
            </div>

            <!-- Mega-Menu déroulant -->
            <div
                v-if="megaMenuOpen"
                class="position-absolute start-0 end-0 bg-white shadow-lg border-top border-bottom py-4"
                style="z-index: 1015;"
            >
                <div class="container">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                        <span class="fw-bold text-dark"><i class="bi bi-grid text-primary me-2"></i>{{ t('mp.allCategoriesTitle') }}</span>
                        <button type="button" class="btn-close btn-sm" aria-label="Fermer" @click="megaMenuOpen = false"></button>
                    </div>
                    <div class="row g-4">
                        <div v-for="cat in megaCategories" :key="cat.id" class="col-6 col-md-4 col-lg-3">
                            <Link
                                :href="`/marketplace/categorie/${cat.slug}`"
                                class="d-flex align-items-center gap-2 fw-bold text-dark text-decoration-none mb-2"
                                @click="megaMenuOpen = false"
                            >
                                <i :class="`bi ${cat.icon} text-primary`"></i>
                                <span>{{ cat.name }}</span>
                            </Link>
                            <ul class="list-unstyled small vstack gap-1 mb-0 ps-4">
                                <li v-for="sub in cat.sub_categories" :key="sub.id">
                                    <Link
                                        :href="`/marketplace/categorie/${cat.slug}/${sub.slug}`"
                                        class="text-muted text-decoration-none"
                                        @click="megaMenuOpen = false"
                                    >
                                        {{ sub.name }}
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast flottant AJAX (Ajout / Doublon panier avec bouton +1) -->
        <div v-if="toast.visible" class="position-fixed top-0 end-0 p-3" style="z-index: 1085; margin-top: 64px; max-width: 400px;">
            <div class="alert shadow-lg border-0 d-flex flex-column gap-2 mb-0" :class="`alert-${toast.type}`">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="small fw-semibold">{{ toast.message }}</div>
                    <button type="button" class="btn-close btn-sm" @click="hideToast"></button>
                </div>
                <div v-if="toast.duplicateProductId" class="d-flex gap-2">
                    <button
                        type="button"
                        class="btn btn-dark btn-sm fw-semibold"
                        @click="addToCart(toast.duplicateProductId, 1, true)"
                    >
                        <i class="bi bi-plus-circle me-1"></i>Augmenter la quantité (+1)
                    </button>
                </div>
            </div>
        </div>

        <div v-if="flash?.success || flash?.error || flash?.info" class="container mt-3">
            <div v-if="flash?.success" class="alert alert-success alert-dismissible fade show py-2 small shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i>{{ flash.success }}
            </div>
            <div v-if="flash?.error" class="alert alert-danger alert-dismissible fade show py-2 small shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ flash.error }}
            </div>
            <div v-if="flash?.info" class="alert alert-info alert-dismissible fade show py-2 small shadow-sm">
                <i class="bi bi-info-circle-fill me-2"></i>{{ flash.info }}
            </div>
        </div>

        <main class="flex-grow-1">
            <PageSkeleton v-if="isNavigating" variant="marketplace" />
            <slot v-else />
        </main>

        <!-- Full-Screen Static Modal de Recherche avec Autocomplétion (min 3 caractères) -->
        <div
            id="modalMarketplaceSearch"
            class="modal fade"
            data-bs-backdrop="static"
            data-bs-keyboard="false"
            tabindex="-1"
            aria-hidden="true"
            style="z-index: 1060;"
        >
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content text-white border-0" style="background-color: #0b0f19;">
                    <div class="modal-header text-white border-bottom border-secondary py-3" style="background-color: #05080f;">
                        <div class="container d-flex justify-content-between align-items-center">
                            <h5 class="modal-title fw-bold">
                                <i class="bi bi-search text-warning me-2"></i>{{ t('mp.searchTitle') }}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                    </div>
                    <div class="modal-body py-4" style="background-color: #0b0f19;">
                        <div class="container" style="max-width: 860px;">
                            <div class="input-group input-group-lg shadow-sm mb-3">
                                <span class="input-group-text bg-dark border-secondary text-warning border-end-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    class="form-control bg-dark text-white border-secondary border-start-0 search-dark-input"
                                    :placeholder="t('mp.searchPlaceholder')"
                                    autofocus
                                    @input="onSearchInput"
                                />
                            </div>

                            <div v-if="searchQuery.trim().length < 3" class="alert bg-dark border border-secondary small shadow-sm text-light">
                                <i class="bi bi-info-circle text-warning me-2"></i>{{ t('mp.searchMinChars') }}
                            </div>

                            <div v-else-if="searchLoading" class="text-center py-5 text-light opacity-75">
                                <div class="spinner-border text-warning mb-2" role="status"></div>
                                <div class="small">{{ t('mp.searchLoading') }}</div>
                            </div>

                            <div v-else-if="hasSearched && !searchResults.length" class="alert bg-dark border border-warning text-warning shadow-sm text-center py-4">
                                <i class="bi bi-search fs-3 d-block mb-2"></i>
                                <strong>{{ t('mp.searchEmpty') }}</strong> (« {{ searchQuery }} »)
                            </div>

                            <div v-else-if="searchResults.length" class="list-group shadow-sm rounded-4 overflow-hidden border border-secondary">
                                <div
                                    v-for="item in searchResults"
                                    :key="item.id"
                                    class="list-group-item bg-dark text-white border-secondary p-3 d-flex align-items-center justify-content-between gap-3"
                                >
                                    <Link
                                        :href="`/marketplace/produit/${item.slug || item.id}`"
                                        class="d-flex align-items-center gap-3 text-decoration-none text-white flex-grow-1"
                                        @click="closeSearchModal"
                                    >
                                        <img
                                            v-if="item.primary_image"
                                            :src="item.primary_image"
                                            alt=""
                                            class="rounded-3 border border-secondary"
                                            style="width: 56px; height: 56px; object-fit: cover;"
                                        />
                                        <div v-else class="bg-black border border-secondary rounded-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                            <i class="bi bi-car-front text-secondary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-white">{{ item.title }}</div>
                                            <div class="small text-light opacity-75">
                                                {{ item.category?.name }} • {{ item.country_name }} • <span class="text-warning fw-semibold">{{ fmt(item.price) }}</span>
                                            </div>
                                        </div>
                                    </Link>
                                    <button type="button" class="btn btn-warning btn-sm fw-semibold text-dark px-3 text-nowrap" @click="addToCart(item.id, 1, false)">
                                        <i class="bi bi-cart-plus me-1"></i>{{ t('mp.add') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menu Latéral Panier (Offcanvas) -->
        <div id="offcanvasCartDrawer" class="offcanvas offcanvas-end" tabindex="-1" aria-labelledby="offcanvasCartLabel">
            <div class="offcanvas-header bg-dark text-white">
                <h5 id="offcanvasCartLabel" class="offcanvas-title fw-bold">
                    <i class="bi bi-cart3 text-warning me-2"></i>{{ t('mp.myCart') }} ({{ cart.count }})
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fermer"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column p-3">
                <div v-if="!cart.items?.length" class="text-center text-muted my-auto py-5">
                    <i class="bi bi-cart-x fs-1 d-block mb-2"></i>
                    <div class="small">{{ t('mp.cartEmpty') }}</div>
                </div>

                <div v-else class="vstack gap-3 overflow-auto flex-grow-1 pe-1">
                    <div v-for="item in cart.items" :key="item.id" class="card border shadow-sm rounded-3 p-2 overflow-hidden">
                        <div class="d-flex gap-2 align-items-center">
                            <img
                                v-if="item.primary_image"
                                :src="item.primary_image"
                                alt=""
                                class="rounded border flex-shrink-0"
                                style="width: 54px; height: 54px; object-fit: cover;"
                            />
                            <div v-else class="bg-light border rounded d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px;">
                                <i class="bi bi-car-front text-muted"></i>
                            </div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="fw-bold small text-truncate">{{ item.title }}</div>
                                <div class="small text-primary fw-semibold">{{ fmt(item.price) }}</div>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-1">
                                    <div class="input-group input-group-sm" style="width: 92px;">
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" @click="updateQuantity(item.id, item.quantity - 1)">-</button>
                                        <input type="text" class="form-control text-center bg-white py-0 px-1" :value="item.quantity" readonly />
                                        <button type="button" class="btn btn-outline-secondary py-0 px-2" @click="updateQuantity(item.id, item.quantity + 1)">+</button>
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 text-nowrap" :title="t('mp.delete')" @click="removeItem(item.id)">
                                        <i class="bi bi-trash me-1"></i>{{ t('mp.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3 mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-semibold text-muted">{{ t('mp.total') }}</span>
                        <span class="h5 fw-bold text-primary mb-0">{{ fmt(cart.total) }}</span>
                    </div>
                    <div class="vstack gap-2">
                        <button
                            v-if="cart.items?.length"
                            type="button"
                            class="btn btn-primary fw-semibold w-100"
                            @click="goToCartRoute('/commander')"
                        >
                            <i class="bi bi-bag-check me-1"></i>{{ t('mp.checkout') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-dark w-100"
                            @click="goToCartRoute('/panier')"
                        >
                            <i class="bi bi-cart3 me-1"></i>{{ t('mp.viewCart') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <footer class="text-white mt-auto" style="background-color: #0f172a;">
            <!-- Bandeau de confiance -->
            <div class="border-bottom border-secondary border-opacity-25" style="background-color: #0b1120;">
                <div class="container py-4">
                    <div class="row g-3 text-center text-md-start">
                        <div class="col-6 col-lg-3">
                            <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                                <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                    <i class="bi bi-shield-check fs-5"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold small text-white">{{ t('mp.footerVerified') }}</div>
                                    <div class="text-white-50" style="font-size: 0.78rem;">{{ t('mp.footerVerifiedSub') }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                                <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                    <i class="bi bi-truck fs-5"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold small text-white">{{ t('mp.footerDelivery') }}</div>
                                    <div class="text-white-50" style="font-size: 0.78rem;">{{ t('mp.footerDeliverySub') }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                                <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                    <i class="bi bi-whatsapp fs-5"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold small text-white">{{ t('mp.footerTracking') }}</div>
                                    <div class="text-white-50" style="font-size: 0.78rem;">{{ t('mp.footerTrackingSub') }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="d-flex flex-column flex-md-row align-items-center gap-3">
                                <span class="rounded-circle bg-primary bg-opacity-25 text-warning d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                    <i class="bi bi-headset fs-5"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold small text-white">{{ t('mp.footerSupport') }}</div>
                                    <div class="text-white-50" style="font-size: 0.78rem;">{{ t('mp.footerSupportSub') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Corps principal du pied de page -->
            <div class="container py-5">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <Link class="text-decoration-none text-white fw-bold fs-5 d-inline-flex align-items-center gap-2 mb-3" href="/marketplace">
                            <img
                                :src="page.props.app?.site?.logo_url || '/assets/images/logo.png'"
                                :alt="appName"
                                class="bg-white rounded-3 px-2 py-1 shadow-sm"
                                style="height: 38px; width: auto; object-fit: contain;"
                            />
                            <span class="small text-warning">Marketplace</span>
                        </Link>
                        <p class="text-white-50 small mb-3" style="line-height: 1.65;">
                            La plateforme de référence pour trouver et commander vos pièces détachées automobiles neuves, d'occasion ou adaptables auprès de garages professionnels vérifiés.
                        </p>
                        <div class="d-flex gap-2">
                            <span class="badge bg-dark border border-secondary text-white-50 px-2 py-1">
                                <i class="bi bi-geo-alt text-warning me-1"></i>Afrique
                            </span>
                            <span class="badge bg-dark border border-secondary text-white-50 px-2 py-1">
                                <i class="bi bi-lock text-success me-1"></i>100% Sécurisé
                            </span>
                        </div>
                    </div>

                    <div class="col-6 col-lg-3">
                        <h6 class="fw-bold text-white mb-3">{{ t('mp.footerPopularCats') }}</h6>
                        <ul class="list-unstyled small vstack gap-2 mb-0">
                            <li v-for="cat in megaCategories.slice(0, 6)" :key="`ft-${cat.id}`">
                                <Link :href="`/marketplace/categorie/${cat.slug}`" class="text-white-50 text-decoration-none d-inline-flex align-items-center gap-2">
                                    <i class="bi bi-chevron-right text-warning" style="font-size: 0.65rem;"></i>
                                    <span>{{ cat.name }}</span>
                                </Link>
                            </li>
                            <li v-if="!megaCategories.length">
                                <Link href="/marketplace" class="text-white-50 text-decoration-none">{{ t('mp.allParts') }}</Link>
                            </li>
                        </ul>
                    </div>

                    <div class="col-6 col-lg-2">
                        <h6 class="fw-bold text-white mb-3">{{ t('mp.footerNav') }}</h6>
                        <ul class="list-unstyled small vstack gap-2 mb-0">
                            <li><Link href="/" class="text-white-50 text-decoration-none">{{ t('mp.home') }}</Link></li>
                            <li><Link href="/marketplace" class="text-white-50 text-decoration-none">{{ t('mp.marketplace') }}</Link></li>
                            <li><Link href="/marketplace/bons-plans" class="text-warning text-decoration-none fw-semibold">{{ t('mp.deals') }}</Link></li>
                            <li><Link href="/panier" class="text-white-50 text-decoration-none">{{ t('mp.myCart') }}</Link></li>
                            <li><Link href="/contact" class="text-white-50 text-decoration-none">{{ t('nav.contact') }}</Link></li>
                        </ul>
                    </div>

                    <div class="col-lg-3">
                        <h6 class="fw-bold text-white mb-3">{{ t('mp.footerProSpace') }}</h6>
                        <p class="text-white-50 small mb-3">
                            {{ t('mp.footerVerifiedSub') }}
                        </p>
                        <div class="d-grid gap-2">
                            <Link href="/inscription" class="btn btn-warning btn-sm fw-semibold text-dark">
                                <i class="bi bi-shop me-1"></i>{{ t('mp.footerSell') }}
                            </Link>
                            <Link href="/connexion" class="btn btn-outline-light btn-sm">
                                <i class="bi bi-box-arrow-in-right me-1"></i>{{ t('mp.footerProLogin') }}
                            </Link>
                        </div>
                    </div>
                </div>

                <hr class="border-secondary border-opacity-25 my-4" />

                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small text-white-50">
                    <span>&copy; {{ new Date().getFullYear() }} {{ appName }} Marketplace — Pièces Automobiles en Afrique</span>
                    <div class="d-flex flex-wrap gap-3">
                        <Link href="/marketplace" class="text-white-50 text-decoration-none">{{ t('mp.marketplace') }}</Link>
                        <Link href="/marketplace/bons-plans" class="text-white-50 text-decoration-none">{{ t('mp.deals') }}</Link>
                        <Link href="/conditions-utilisation" class="text-white-50 text-decoration-none">{{ t('footer.terms') }}</Link>
                        <Link href="/politique-confidentialite" class="text-white-50 text-decoration-none">{{ t('footer.privacy') }}</Link>
                        <Link href="/contact" class="text-white-50 text-decoration-none">{{ t('nav.contact') }}</Link>
                    </div>
                </div>
            </div>
        </footer>

        <ConfirmStaticModal />
        <BackToTop />
    </div>
</template>
