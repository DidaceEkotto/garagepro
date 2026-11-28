<script setup>
import BackToTop from '@/Components/BackToTop.vue';
import ConfirmStaticModal from '@/Components/ConfirmStaticModal.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import ImageLightbox from '@/Components/ImageLightbox.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import OfflineStatusBar from '@/Components/OfflineStatusBar.vue';
import PageSkeleton from '@/Components/PageSkeleton.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useI18n } from '@/composables/useI18n';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { usePageLoading } from '@/composables/usePageLoading';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const { t } = useI18n();
const { isNavigating } = usePageLoading();
const {
    initOfflineSync,
    precacheMenuPages,
    clearAuthOfflineCaches,
    offlineNotice,
    isOnline: offlineIsOnline,
    pendingCount: offlinePendingCount,
    leaseDaysRemaining,
    leaseWarning,
    leaseExpired,
    clockTampered,
} = useOfflineSync();
const page = usePage();
const tenant = computed(() => page.props.tenant);

watch(
    () => tenant.value?.id,
    (id) => {
        if (id) {
            initOfflineSync(id);
            setTimeout(() => {
                const urls = (menuGroups.value || []).flatMap((g) => (g.items || []).map((it) => it.href));
                precacheMenuPages(urls);
            }, 500);
        }
    },
    { immediate: true }
);
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash);
const payrollAlert = computed(() => page.props.payrollAlert);
const affiliateAlert = computed(() => page.props.affiliateAlert ?? null);
const unreadMessagesCount = computed(() => liveBadges.value['/messagerie'] ?? (page.props.unreadMessagesCount ?? 0));
const pendingMarketplaceOrdersCount = computed(() => liveBadges.value['/marketplace-gestion/commandes'] ?? (page.props.pendingMarketplaceOrdersCount ?? 0));
const pendingAppointmentRequestsCount = computed(() => liveBadges.value['/rendez-vous'] ?? (page.props.pendingAppointmentRequestsCount ?? 0));
const openSosCount = computed(() => liveBadges.value['/depannage-sos'] ?? 0);
const unreadB2bCount = computed(() => liveBadges.value['/reseau-pro'] ?? 0);
const recentNotifications = computed(() => page.props.recentNotifications ?? []);
const currentUrl = computed(() => page.url);
const marketplaceMenuOpen = ref(currentUrl.value.startsWith('/marketplace-gestion'));

// Actualisation discrète toutes les 10 secondes des badges de la sidebar (sans bloquer la navigation)
const liveBadges = ref({});
let badgesPollTimer = null;
let badgesAbort = null;

async function fetchLiveBadges() {
    if (typeof document !== 'undefined' && document.hidden) return;
    if (!offlineIsOnline.value || !tenant.value?.id) return;
    try {
        if (badgesAbort) badgesAbort.abort();
        badgesAbort = new AbortController();
        const resp = await fetch('/api/tenant/live-badges', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: badgesAbort.signal,
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data?.ok && data?.badges) {
            liveBadges.value = data.badges;
        }
    } catch {
        // Silencieux : ne perturbe jamais la navigation
    }
}

function badgeCountFor(href) {
    if (href === '/messagerie') return unreadMessagesCount.value;
    if (href === '/rendez-vous') return pendingAppointmentRequestsCount.value;
    if (href === '/depannage-sos') return openSosCount.value;
    if (href === '/reseau-pro') return unreadB2bCount.value;
    if (href === '/marketplace-gestion/commandes') return pendingMarketplaceOrdersCount.value;
    return 0;
}

function badgeColorClass(href) {
    if (href === '/rendez-vous') return 'bg-warning text-dark';
    if (href === '/depannage-sos') return 'bg-danger text-white';
    if (href === '/messagerie') return 'bg-primary text-white';
    return 'bg-danger text-white';
}

const routeTitleMap = [
    ['/tableau-de-bord', 'Tableau de bord'],
    ['/assistant-ia', 'Assistant IA Diagnostic Auto'],
    ['/clients/', 'Fiche Client'],
    ['/clients', 'Clients'],
    ['/vehicules/', 'Fiche Véhicule'],
    ['/vehicules', 'Véhicules'],
    ['/rendez-vous', 'Rendez-vous'],
    ['/receptions/nouvelle', 'Nouvelle fiche de réception'],
    ['/receptions', 'Réceptions véhicules'],
    ['/ordres-reparation', 'Ordres de réparation'],
    ['/devis', 'Devis'],
    ['/stock', 'Stock & Pièces'],
    ['/approvisionnement', 'Approvisionnement & Fournisseurs'],
    ['/atelier/mes-or', 'Atelier — Mes OR du jour'],
    ['/inspections', 'Inspections Visuelles (DVI)'],
    ['/sous-traitance', 'Sous-traitance & Dépannage'],
    ['/outillage-vrac', 'Outillage & Consommables'],
    ['/planning-atelier', 'Planning Atelier'],
    ['/rappels-entretien', 'Rappels Entretien & Documents'],
    ['/flottes', 'Flottes Entreprises (B2B)'],
    ['/visite-technique', 'Visite Technique & PV'],
    ['/assurances', 'Assurances & Sinistres'],
    ['/factures', 'Facturation & Caisse'],
    ['/comptabilite', 'Comptabilité SYSCOHADA'],
    ['/rh', 'Pointage & Paie (RH)'],
    ['/apres-vente', 'Après-vente & Fidélité'],
    ['/rapports', 'Statistiques & Rapports'],
    ['/messagerie', 'Messagerie & Notifications'],
    ['/vitrine-prestations', 'Ma Vitrine & Prestations'],
    ['/depannage-sos', 'SOS Dépannage'],
    ['/marketplace-gestion/categories', 'Marketplace — Catégories'],
    ['/marketplace-gestion/produits', 'Marketplace — Mes Produits'],
    ['/marketplace-gestion/commandes', 'Marketplace — Commandes reçues'],
    ['/agences', 'Agences'],
    ['/professionnels', 'Professionnels & Équipe'],
    ['/mon-profil', 'Mon Profil'],
    ['/parametres-espace', 'Paramètres Espace'],
    ['/abonnement', 'Abonnement'],
    ['/affiliation', 'Affiliation & Parrainage'],
    ['/tutoriel', 'Tutoriel & Documentation'],
    ['/paiements/', 'Statut du paiement'],
];

const currentPageTitle = computed(() => {
    const cleanPath = (currentUrl.value || '').split('?')[0];
    for (const [prefix, label] of routeTitleMap) {
        if (cleanPath === prefix || (prefix.endsWith('/') && cleanPath.startsWith(prefix)) || cleanPath.startsWith(prefix + '/')) {
            return label;
        }
    }
    return 'Espace Professionnel';
});

function ensureDocumentTitle() {
    const appName = page.props.app?.name || 'MboaCar';
    const current = (document.title || '').trim();
    if (!current || current === appName || current === 'MboaCar' || current === 'Laravel') {
        document.title = `${currentPageTitle.value} - ${appName}`;
    }
}

watch(currentUrl, () => {
    setTimeout(ensureDocumentTitle, 0);
});
onMounted(ensureDocumentTitle);

const replyingNotification = ref(null);
const quickReplyForm = useForm({
    body: '',
    attachments: [],
});

function openQuickReply(notif) {
    replyingNotification.value = notif;
    quickReplyForm.reset('body', 'attachments');
}

function submitQuickReply() {
    if (!replyingNotification.value) return;
    quickReplyForm.post(`/messagerie/${replyingNotification.value.id}/repondre`, {
        forceFormData: true,
        onSuccess: () => {
            replyingNotification.value = null;
            quickReplyForm.reset();
            window.bootstrap?.Modal.getInstance(document.getElementById('modalQuickReplyNotification'))?.hide();
        },
    });
}

const nowTs = ref(Date.now());
let timerId = null;

onMounted(() => {
    timerId = setInterval(() => {
        nowTs.value = Date.now();
    }, 1000);
    fetchLiveBadges();
    badgesPollTimer = setInterval(fetchLiveBadges, 10000);
});

onBeforeUnmount(() => {
    if (timerId) clearInterval(timerId);
    if (badgesPollTimer) clearInterval(badgesPollTimer);
    if (badgesAbort) badgesAbort.abort();
});

const subscriptionCountdown = computed(() => {
    const t = tenant.value;
    if (!t) return null;

    const targetIso = t.status === 'trialing' ? t.trialEndsAt : t.subscriptionEndsAt;
    if (!targetIso) return null;

    const diffMs = new Date(targetIso).getTime() - nowTs.value;
    if (diffMs <= 0) {
        return t.status === 'trialing' ? 'Essai expiré' : 'Abonnement expiré';
    }

    const totalSec = Math.floor(diffMs / 1000);
    const days = Math.floor(totalSec / 86400);
    const hours = Math.floor((totalSec % 86400) / 3600);
    const mins = Math.floor((totalSec % 3600) / 60);
    const secs = totalSec % 60;
    const prefix = t.status === 'trialing' ? 'Essai :' : 'Abonnement :';

    return `${prefix} ${days}j ${String(hours).padStart(2, '0')}h ${String(mins).padStart(2, '0')}m ${String(secs).padStart(2, '0')}s`;
});

const userInitials = computed(() => {
    const name = auth.value?.user?.name ?? '';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'U';
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

const userPermissions = computed(() => auth.value?.user?.permissions ?? []);

function hasAnyPermission(perms) {
    if (!perms || perms.length === 0) return true;
    return perms.some((p) => userPermissions.value.includes(p));
}

const menuGroups = computed(() => {
    const currentType = tenant.value?.type || 'garage';

    if (tenant.value?.isReadOnly) {
        return [
            {
                title: t('layout.settings'),
                items: [
                    { label: t('layout.subscription'), href: '/abonnement', icon: 'bi-credit-card', permissions: [] },
                ],
            },
        ];
    }

    // Espace ultra-épuré dédié aux Vendeurs de Pièces Détachées (1 seule agence, 100 % vente de pièces)
    if (currentType === 'boutique_pieces') {
        const partsGroups = [
            {
                title: 'Ma Boutique Pièces',
                items: [
                    { label: t('layout.dashboard'), href: '/tableau-de-bord', icon: 'bi-speedometer2', permissions: [] },
                    { label: 'Mes Pièces (Produits)', href: '/marketplace-gestion/produits', icon: 'bi-box2-heart', permissions: ['stock.manage', 'settings.manage'] },
                    { label: 'Catégories & Sous-catégories', href: '/marketplace-gestion/categories', icon: 'bi-tags', permissions: ['stock.manage', 'settings.manage'] },
                    { label: 'Commandes reçues', href: '/marketplace-gestion/commandes', icon: 'bi-bag-check', permissions: ['stock.manage', 'settings.manage'] },
                ],
            },
            {
                title: 'Clients & Finances',
                items: [
                    { label: 'Clients & Garages', href: '/clients', icon: 'bi-people', permissions: ['clients.view', 'clients.manage'] },
                    { label: t('layout.invoices'), href: '/factures', icon: 'bi-receipt', permissions: ['invoices.view', 'invoices.manage', 'payments.manage'] },
                    { label: t('layout.accounting'), href: '/comptabilite', icon: 'bi-journal-bookmark', permissions: ['accounting.view', 'accounting.manage'] },
                    { label: t('layout.reports'), href: '/rapports', icon: 'bi-graph-up', permissions: ['reports.view'] },
                ],
            },
            {
                title: t('layout.settings'),
                items: [
                    { label: t('layoutExtra.messaging'), href: '/messagerie', icon: 'bi-chat-dots', permissions: [] },
                    { label: 'Ma Vitrine', href: '/vitrine-prestations', icon: 'bi-shop-window', permissions: [] },
                    { label: t('layout.professionals'), href: '/professionnels', icon: 'bi-person-plus', permissions: ['users.view', 'users.manage'] },
                    { label: t('layout.settings'), href: '/parametres-espace', icon: 'bi-gear', permissions: ['settings.manage'] },
                    { label: t('layoutExtra.affiliation'), href: '/affiliation', icon: 'bi-ticket-perforated', permissions: ['settings.manage', 'payments.manage'] },
                    { label: t('layout.subscription'), href: '/abonnement', icon: 'bi-credit-card', permissions: ['settings.manage', 'payments.manage'] },
                ],
            },
        ];

        return partsGroups
            .map((group) => ({
                ...group,
                items: group.items.filter((item) => hasAnyPermission(item.permissions)),
            }))
            .filter((group) => group.items.length > 0);
    }

    const rawGroups = [
        {
            title: t('layout.dashboard'),
            items: [
                { label: t('layout.dashboard'), href: '/tableau-de-bord', icon: 'bi-speedometer2', permissions: [] },
                { label: t('layoutExtra.aiAssistant'), href: '/assistant-ia', icon: 'bi-stars', permissions: [] },
            ],
        },
        {
            title: currentType === 'centre_technique' ? t('layoutExtra.technicalControlGroup') : (currentType === 'assurance' ? t('layoutExtra.insuranceGroup') : t('layout.workshop')),
            items: [
                { label: currentType === 'assurance' ? t('layoutExtra.insured') : t('layout.customers'), href: '/clients', icon: 'bi-people', permissions: ['clients.view', 'clients.manage'] },
                { label: t('layoutExtra.vehicles'), href: '/vehicules', icon: 'bi-car-front', permissions: ['clients.view', 'clients.manage'] },
                { label: t('layoutExtra.fleets'), href: '/flottes', icon: 'bi-buildings', permissions: ['clients.view', 'clients.manage'], types: ['garage', 'assurance'] },
                { label: t('layout.appointments'), href: '/rendez-vous', icon: 'bi-calendar-check', permissions: ['appointments.view', 'appointments.manage'] },
                { label: currentType === 'centre_technique' ? t('layoutExtra.planningLines') : t('layoutExtra.planningWorkshop'), href: '/planning-atelier', icon: 'bi-calendar3', permissions: ['appointments.view', 'repair_orders.view'], types: ['garage', 'centre_technique'] },
                { label: t('layoutExtra.maintenanceReminders'), href: '/rappels-entretien', icon: 'bi-bell', permissions: ['clients.view', 'clients.manage'] },
                { label: t('layout.receptions'), href: '/receptions', icon: 'bi-clipboard-check', permissions: ['receptions.manage'], types: ['garage'] },
                { label: t('layoutExtra.inspectionsDvi'), href: '/inspections', icon: 'bi-camera', permissions: ['repair_orders.view', 'diagnostics.manage'], types: ['garage'] },
                { label: t('layoutExtra.technicalInspection'), href: '/visite-technique', icon: 'bi-clipboard2-check', permissions: ['clients.view', 'repair_orders.view', 'diagnostics.manage'], types: ['centre_technique'] },
                { label: currentType === 'assurance' ? t('layoutExtra.policiesAndClaims') : t('layoutExtra.insuranceAndClaims'), href: '/assurances', icon: 'bi-shield-check', permissions: ['clients.view', 'invoices.view', 'repair_orders.view'], types: ['assurance', 'garage'] },
                { label: t('layout.repairOrders'), href: '/ordres-reparation', icon: 'bi-wrench-adjustable', permissions: ['repair_orders.view', 'repair_orders.manage'], types: ['garage'] },
                { label: t('layoutExtra.subcontracting'), href: '/sous-traitance', icon: 'bi-truck-front', permissions: ['repair_orders.view', 'repair_orders.manage'], types: ['garage'] },
                { label: t('layout.quotes'), href: '/devis', icon: 'bi-file-earmark-text', permissions: ['quotes.view', 'quotes.manage'], types: ['garage'] },
                { label: t('layout.workshop'), href: '/atelier/mes-or', icon: 'bi-tools', permissions: ['repair_orders.view', 'repair_orders.view_own', 'diagnostics.manage'], types: ['garage'] },
            ],
        },
        {
            title: t('layout.accounting'),
            items: [
                { label: t('layout.inventory'), href: '/stock', icon: 'bi-box-seam', permissions: ['stock.view', 'stock.manage'], types: ['garage'] },
                { label: t('layoutExtra.toolsAndBulk'), href: '/outillage-vrac', icon: 'bi-droplet-half', permissions: ['stock.view', 'stock.manage'], types: ['garage'] },
                { label: t('layout.purchasing'), href: '/approvisionnement', icon: 'bi-truck', permissions: ['purchase_orders.manage', 'suppliers.manage'], types: ['garage'] },
                { label: t('layout.invoices'), href: '/factures', icon: 'bi-receipt', permissions: ['invoices.view', 'invoices.manage', 'payments.manage'] },
                { label: t('layout.accounting'), href: '/comptabilite', icon: 'bi-journal-bookmark', permissions: ['accounting.view', 'accounting.manage'] },
                { label: t('layout.reports'), href: '/rapports', icon: 'bi-graph-up', permissions: ['reports.view'] },
            ],
        },
        {
            title: t('layout.settings'),
            items: [
                { label: t('layoutExtra.agencies'), href: '/agences', icon: 'bi-building', permissions: ['agencies.view', 'agencies.manage'] },
                { label: t('layout.professionals'), href: '/professionnels', icon: 'bi-person-plus', permissions: ['users.view', 'users.manage'] },
                { label: t('layout.hr'), href: '/rh', icon: 'bi-person-badge', permissions: ['hr.manage'] },
                { label: t('layoutExtra.messaging'), href: '/messagerie', icon: 'bi-chat-dots', permissions: [], badge: unreadMessagesCount.value },
                { label: 'Ma Vitrine & Prestations', href: '/vitrine-prestations', icon: 'bi-shop-window', permissions: [] },
                { label: 'SOS Dépannage', href: '/depannage-sos', icon: 'bi-geo-alt-fill', permissions: [] },
                { label: t('layoutExtra.afterSales'), href: '/apres-vente', icon: 'bi-award', permissions: ['after_sales.manage'] },
                { label: t('layout.settings'), href: '/parametres-espace', icon: 'bi-gear', permissions: ['settings.manage'] },
                { label: t('layoutExtra.affiliation'), href: '/affiliation', icon: 'bi-ticket-perforated', permissions: ['settings.manage', 'payments.manage'] },
                { label: t('layout.subscription'), href: '/abonnement', icon: 'bi-credit-card', permissions: ['settings.manage', 'payments.manage'] },
            ],
        },
    ];

    if (tenant.value?.isReadOnly) {
        return [
            {
                title: t('layout.settings'),
                items: [
                    { label: t('layout.subscription'), href: '/abonnement', icon: 'bi-credit-card', permissions: [] },
                ],
            },
        ];
    }

    return rawGroups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => (!item.types || item.types.includes(currentType)) && hasAnyPermission(item.permissions)
            ),
        }))
        .filter((group) => group.items.length > 0);
});

function isActive(href) {
    if (href === '/tableau-de-bord') {
        return currentUrl.value === href;
    }

    return currentUrl.value === href
        || currentUrl.value.startsWith(href + '/')
        || currentUrl.value.startsWith(href + '?');
}

async function confirmLogout() {
    window.bootstrap?.Modal.getInstance(document.getElementById('modalConfirmLogout'))?.hide();
    await clearAuthOfflineCaches();
    router.post('/deconnexion');
}

function resetDemo() {
    router.post('/demo/reinitialiser');
}
</script>

<template>
    <div class="tenant-shell min-vh-100 d-flex bg-light">
        <!-- Desktop Vertical Sidebar (FIXE à gauche) -->
        <aside class="tenant-sidebar-fixed d-none d-lg-flex flex-column">
            <!-- Marque / Établissement en haut de la Sidebar -->
            <div class="tenant-sidebar-brand px-3 py-3 border-bottom border-white border-opacity-10">
                <Link class="d-flex align-items-center gap-2 text-decoration-none text-white" href="/tableau-de-bord">
                    <img
                        v-if="tenant?.logoUrl"
                        :src="tenant.logoUrl"
                        alt="Logo"
                        class="rounded-3 bg-white p-1 flex-shrink-0"
                        style="height: 36px; width: 36px; object-fit: contain;"
                    />
                    <img
                        v-else
                        :src="page.props.app?.site?.logo_url || '/assets/images/logo.png'"
                        alt="MboaCar"
                        class="rounded-3 bg-white px-2 py-1 flex-shrink-0"
                        style="height: 36px; width: auto; max-width: 96px; object-fit: contain;"
                    />
                    <div class="overflow-hidden">
                        <div class="fw-bold text-truncate" style="font-size: 0.92rem; line-height: 1.2;">{{ tenant?.name ?? 'MboaCar' }}</div>
                        <div class="text-white-50 text-truncate" style="font-size: 0.7rem;">Espace Professionnel</div>
                    </div>
                </Link>
            </div>

            <!-- Navigation scrollable interne -->
            <div class="tenant-sidebar-scroll flex-grow-1 px-3 py-3">
                <div v-for="group in menuGroups" :key="group.title" class="mb-3">
                    <div class="tenant-sidebar-group-title px-2 mb-1">
                        {{ group.title }}
                    </div>
                    <ul class="nav flex-column gap-1">
                        <li v-for="item in group.items" :key="item.href" class="nav-item">
                            <Link
                                :href="item.href"
                                class="tenant-sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small text-decoration-none"
                                :class="{ 'is-active': isActive(item.href) }"
                            >
                                <span class="d-flex align-items-center gap-2 text-truncate">
                                    <i :class="`bi ${item.icon}`"></i>
                                    <span class="text-truncate">{{ item.label }}</span>
                                </span>
                                <span
                                    v-if="badgeCountFor(item.href) > 0"
                                    class="badge rounded-pill flex-shrink-0 ms-2"
                                    :class="badgeColorClass(item.href)"
                                    style="font-size: 0.68rem;"
                                >
                                    {{ badgeCountFor(item.href) }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Menu déroulant Marketplace -->
                <div v-if="!tenant?.isReadOnly && (tenant?.type === 'garage' || !tenant?.type)" class="mb-3">
                    <button
                        type="button"
                        class="tenant-sidebar-link w-100 border-0 bg-transparent d-flex align-items-center justify-content-between py-2 px-3 small fw-semibold"
                        @click="marketplaceMenuOpen = !marketplaceMenuOpen"
                    >
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-shop text-info"></i>
                            <span>{{ t('layoutExtra.marketplace') }}</span>
                            <span v-if="pendingMarketplaceOrdersCount > 0" class="badge bg-danger rounded-pill" style="font-size: 0.68rem;">
                                {{ pendingMarketplaceOrdersCount }}
                            </span>
                        </span>
                        <i class="bi" :class="marketplaceMenuOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </button>
                    <ul v-show="marketplaceMenuOpen" class="nav flex-column gap-1 mt-1 ps-2">
                        <li class="nav-item">
                            <Link
                                href="/marketplace-gestion/produits"
                                class="tenant-sidebar-link d-flex align-items-center gap-2 py-2 px-3 small text-decoration-none"
                                :class="{ 'is-active': isActive('/marketplace-gestion/produits') }"
                            >
                                <i class="bi bi-box2-heart"></i><span>{{ t('layoutExtra.myProducts') }}</span>
                            </Link>
                        </li>
                        <li class="nav-item">
                            <Link
                                href="/marketplace-gestion/commandes"
                                class="tenant-sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small text-decoration-none"
                                :class="{ 'is-active': isActive('/marketplace-gestion/commandes') }"
                            >
                                <span class="d-flex align-items-center gap-2"><i class="bi bi-bag-check"></i><span>{{ t('layoutExtra.orders') }}</span></span>
                                <span v-if="pendingMarketplaceOrdersCount > 0" class="badge bg-danger rounded-pill" style="font-size: 0.68rem;">
                                    {{ pendingMarketplaceOrdersCount }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Pied de Sidebar : Tutoriel -->
            <div v-if="!tenant?.isReadOnly" class="px-3 py-2 border-top border-white border-opacity-10">
                <Link
                    href="/tutoriel"
                    class="tenant-sidebar-link d-flex align-items-center gap-2 py-2 px-3 small text-decoration-none"
                    :class="{ 'is-active': isActive('/tutoriel') }"
                >
                    <i class="bi bi-book"></i>
                    <span>{{ t('layoutExtra.tutorial') }}</span>
                </Link>
            </div>
        </aside>

        <!-- Colonne principale droite : Navbar NON-FIXE + Contenu -->
        <div class="tenant-main-column d-flex flex-column flex-grow-1" style="min-width: 0;">
            <!-- Top Navbar moderne (NON FIXE : défile naturellement avec la page) -->
            <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-3 px-md-4 py-2">
                <div class="container-fluid px-0">
                    <div class="d-flex align-items-center gap-2">
                        <button
                            class="btn btn-outline-secondary btn-sm d-lg-none rounded-3"
                            type="button"
                            data-bs-toggle="offcanvas"
                            data-bs-target="#mobileSidebar"
                        >
                            <i class="bi bi-list fs-5"></i>
                        </button>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark" style="font-size: 0.98rem;">
                                {{ currentPageTitle }}
                            </span>
                            <span v-if="tenant?.status" class="badge bg-primary-subtle text-primary rounded-pill d-none d-sm-inline" style="font-size: 0.7rem;">
                                {{ tenant.status }}
                            </span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <span v-if="subscriptionCountdown" class="badge bg-warning-subtle text-dark border border-warning-subtle rounded-pill px-2 py-1 d-none d-xl-inline small">
                            <i class="bi bi-hourglass-split me-1 text-warning"></i>{{ subscriptionCountdown }}
                        </span>
                        <button
                            v-if="tenant?.slug === 'demo-douala'"
                            type="button"
                            class="btn btn-warning btn-sm text-dark fw-semibold rounded-pill px-3"
                            @click="resetDemo"
                        >
                            <i class="bi bi-arrow-clockwise me-1"></i>{{ t('layout.resetDemo') }}
                        </button>

                        <LanguageSwitcher variant="dark" />

                        <!-- Icône Messages / Notifications avec compteur temps réel -->
                        <div class="dropdown">
                            <button
                                class="btn btn-light border btn-sm position-relative rounded-circle p-0 d-inline-flex align-items-center justify-content-center"
                                style="width: 36px; height: 36px;"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                title="Messages & Notifications"
                            >
                                <i class="bi bi-bell-fill text-dark"></i>
                                <span
                                    v-if="unreadMessagesCount > 0"
                                    class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                    style="font-size: 0.65rem;"
                                >
                                    {{ unreadMessagesCount }}
                                </span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width: 340px; max-width: 92vw;">
                                <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light rounded-top">
                                    <span class="fw-bold small text-dark">Notifications &amp; Messages</span>
                                    <span v-if="unreadMessagesCount > 0" class="badge bg-danger">{{ unreadMessagesCount }} nouveau(x)</span>
                                </div>
                                <div v-if="!recentNotifications.length" class="p-3 text-center text-muted small">
                                    Aucune nouvelle notification.
                                </div>
                                <div v-else class="list-group list-group-flush" style="max-height: 290px; overflow-y: auto;">
                                    <div
                                        v-for="notif in recentNotifications"
                                        :key="notif.id"
                                        class="list-group-item list-group-item-action p-3"
                                        :class="{ 'bg-primary-subtle bg-opacity-25': !notif.is_read }"
                                    >
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-bold small text-dark">
                                                {{ notif.sender_name }}
                                                <span v-if="notif.sender_type === 'admin'" class="badge bg-dark ms-1" style="font-size: 0.62rem;">Admin</span>
                                            </span>
                                            <span v-if="!notif.is_read" class="badge bg-primary rounded-pill" style="font-size: 0.6rem;">Nouveau</span>
                                        </div>
                                        <div class="small fw-semibold text-primary text-truncate">{{ notif.subject }}</div>
                                        <div class="small text-muted text-truncate mb-2">{{ notif.body }}</div>
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm py-0 px-2"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalQuickReplyNotification"
                                            @click="openQuickReply(notif)"
                                        >
                                            <i class="bi bi-reply me-1"></i>Répondre
                                        </button>
                                    </div>
                                </div>
                                <div class="p-2 border-top text-center bg-light rounded-bottom">
                                    <Link href="/messagerie" class="btn btn-link btn-sm text-decoration-none fw-semibold">
                                        Ouvrir toute la messagerie <i class="bi bi-arrow-right ms-1"></i>
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Statut Réseau & Mode Hors Ligne -->
                        <OfflineStatusBar />

                        <!-- Menu déroulant Profil Utilisateur -->
                        <div class="dropdown">
                            <button
                                class="btn btn-light border rounded-pill dropdown-toggle d-flex align-items-center gap-2 py-1 px-2 text-dark"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                            >
                                <img
                                    v-if="auth?.user?.photoUrl"
                                    :src="auth.user.photoUrl"
                                    alt="Profil"
                                    class="rounded-circle border"
                                    style="width: 28px; height: 28px; object-fit: cover;"
                                />
                                <span
                                    v-else
                                    class="rounded-circle bg-primary text-white fw-bold d-inline-flex align-items-center justify-content-center"
                                    style="width: 28px; height: 28px; font-size: 0.75rem;"
                                >
                                    {{ userInitials }}
                                </span>
                                <span class="small fw-semibold d-none d-md-inline">{{ auth?.user?.name }}</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li>
                                    <Link class="dropdown-item small" href="/mon-profil">
                                        <i class="bi bi-person-circle me-2"></i>{{ t('layout.profile') }}
                                    </Link>
                                </li>
                                <li>
                                    <Link class="dropdown-item small" href="/parametres-espace">
                                        <i class="bi bi-gear me-2"></i>{{ t('layout.settings') }}
                                    </Link>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item small text-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalConfirmLogout"
                                    >
                                        <i class="bi bi-box-arrow-right me-2"></i>{{ t('layout.logout') }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Bannière d'alerte Mode Hors Ligne / Synchro / Bail 14 jours -->
            <div
                v-if="!offlineIsOnline || offlineNotice"
                class="px-3 py-2 small fw-semibold d-flex flex-wrap align-items-center justify-content-between gap-2"
                :class="
                    leaseExpired
                        ? 'bg-danger text-white'
                        : offlineNotice?.type === 'success'
                            ? 'bg-success text-white'
                            : offlineNotice?.type === 'danger'
                                ? 'bg-danger text-white'
                                : 'bg-warning text-dark'
                "
            >
                <div>
                    <i
                        class="bi me-2"
                        :class="
                            leaseExpired
                                ? 'bi-lock-fill'
                                : offlineNotice?.type === 'success'
                                    ? 'bi-cloud-check-fill'
                                    : offlineNotice?.type === 'danger'
                                        ? 'bi-exclamation-triangle-fill'
                                        : 'bi-wifi-off'
                        "
                    ></i>
                    <span v-if="leaseExpired">
                        {{
                            clockTampered
                                ? 'Horloge système modifiée : reconnectez-vous à Internet pour réactiver les saisies hors ligne.'
                                : 'Période hors ligne (14 jours) expirée — Mode lecture seule. Connectez-vous à Internet pour synchroniser et prolonger.'
                        }}
                    </span>
                    <span v-else-if="offlineNotice">{{ offlineNotice.message }}</span>
                    <span v-else-if="leaseWarning">
                        Mode Hors Ligne actif — Attention : il reste {{ leaseDaysRemaining }} jour(s) avant reconnexion obligatoire
                        <template v-if="offlinePendingCount > 0"> ({{ offlinePendingCount }} en attente)</template>.
                    </span>
                    <span v-else>
                        Mode Hors Ligne actif (tous modules sauf IA Auto • WhatsApp envoyé au retour du réseau • bail {{ leaseDaysRemaining }} j)
                        <template v-if="offlinePendingCount > 0"> — {{ offlinePendingCount }} saisie(s) en attente</template>.
                    </span>
                </div>
                <button
                    type="button"
                    class="btn btn-sm py-0 px-2 fw-semibold"
                    :class="leaseExpired || offlineNotice?.type === 'success' || offlineNotice?.type === 'danger' ? 'btn-light' : 'btn-dark'"
                    data-bs-toggle="modal"
                    data-bs-target="#modalOfflineSyncCenter"
                >
                    Centre de synchro
                </button>
            </div>

            <!-- Mobile Offcanvas Sidebar -->
            <div id="mobileSidebar" class="offcanvas offcanvas-start bg-dark text-white" tabindex="-1">
                <div class="offcanvas-header border-bottom border-secondary">
                    <h5 class="offcanvas-title fw-bold">{{ tenant?.name ?? 'MboaCar' }}</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
                </div>
                <div class="offcanvas-body">
                    <div v-for="group in menuGroups" :key="group.title" class="mb-3">
                        <div class="tenant-sidebar-group-title px-2 mb-1">
                            {{ group.title }}
                        </div>
                        <ul class="nav flex-column gap-1">
                            <li v-for="item in group.items" :key="item.href" class="nav-item">
                                <Link
                                    :href="item.href"
                                    class="tenant-sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small text-decoration-none"
                                    :class="{ 'is-active': isActive(item.href) }"
                                >
                                    <span class="d-flex align-items-center gap-2">
                                        <i :class="`bi ${item.icon}`"></i>
                                        <span>{{ item.label }}</span>
                                    </span>
                                    <span
                                        v-if="badgeCountFor(item.href) > 0"
                                        class="badge rounded-pill"
                                        :class="badgeColorClass(item.href)"
                                        style="font-size: 0.68rem;"
                                    >
                                        {{ badgeCountFor(item.href) }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <div v-if="!tenant?.isReadOnly && (tenant?.type === 'garage' || !tenant?.type)" class="mb-3">
                        <div class="tenant-sidebar-group-title px-2 mb-1">
                            {{ t('layoutExtra.marketplace') }}
                        </div>
                        <ul class="nav flex-column gap-1">
                            <li class="nav-item">
                                <Link href="/marketplace-gestion/produits" class="tenant-sidebar-link d-flex align-items-center gap-2 py-2 px-3 small text-decoration-none" :class="{ 'is-active': isActive('/marketplace-gestion/produits') }">
                                    <i class="bi bi-box2-heart"></i><span>{{ t('layoutExtra.myProducts') }}</span>
                                </Link>
                            </li>
                            <li class="nav-item">
                                <Link href="/marketplace-gestion/commandes" class="tenant-sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small text-decoration-none" :class="{ 'is-active': isActive('/marketplace-gestion/commandes') }">
                                    <span class="d-flex align-items-center gap-2"><i class="bi bi-bag-check"></i><span>{{ t('layoutExtra.orders') }}</span></span>
                                    <span v-if="pendingMarketplaceOrdersCount > 0" class="badge bg-danger rounded-pill" style="font-size: 0.68rem;">
                                        {{ pendingMarketplaceOrdersCount }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <div v-if="!tenant?.isReadOnly" class="pt-2 border-top border-secondary">
                        <ul class="nav flex-column gap-1">
                            <li class="nav-item">
                                <Link href="/tutoriel" class="tenant-sidebar-link d-flex align-items-center gap-2 py-2 px-3 small text-decoration-none" :class="{ 'is-active': isActive('/tutoriel') }">
                                    <i class="bi bi-book"></i><span>{{ t('layoutExtra.tutorial') }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <main class="flex-grow-1 p-3 p-md-4" style="min-width: 0; max-width: 100%; overflow-x: clip;">
                <div v-if="tenant?.isReadOnly" class="alert alert-danger border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-lock-fill me-2"></i>
                        <strong>Mode lecture seule :</strong> Votre période d'essai ou abonnement a expiré. Vous ne pouvez plus enregistrer, modifier ou supprimer. Passez au plan payant pour réactiver votre espace.
                    </div>
                    <Link href="/abonnement" class="btn btn-danger btn-sm ms-2 text-nowrap fw-semibold">Abonnement</Link>
                </div>
                <div v-else-if="tenant?.isInTrialGrace" class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Période d'essai terminée (2 jours de grâce) :</strong> Passez au plan payant avant la fin du délai de grâce pour éviter le passage en lecture seule.
                    </div>
                    <Link href="/abonnement" class="btn btn-warning btn-sm ms-2 text-nowrap fw-semibold text-dark">Passer au plan payant</Link>
                </div>
                <div v-else-if="tenant?.isInSubscriptionGrace" class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-hourglass-split me-2"></i>
                        <strong>Mode grâce actif :</strong> Votre abonnement est arrivé à échéance. Renouvelez votre abonnement dans les 5 jours pour éviter le passage en lecture seule.
                    </div>
                    <Link href="/abonnement" class="btn btn-warning btn-sm ms-2 text-nowrap fw-semibold text-dark">Renouveler</Link>
                </div>

                <div v-if="affiliateAlert" class="alert alert-danger border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-shield-exclamation me-2"></i>
                        <strong>Alerte Affiliation :</strong> Votre lien d'affiliation (<code>{{ affiliateAlert.code }}</code>) a été bloqué par l'administration.
                        <span v-if="affiliateAlert.reason">Motif : {{ affiliateAlert.reason }}</span>
                    </div>
                    <Link href="/affiliation" class="btn btn-outline-danger btn-sm ms-2 text-nowrap">Détails</Link>
                </div>

                <div v-if="pendingMarketplaceOrdersCount > 0" class="alert alert-success border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-bag-check-fill me-2"></i>
                        <strong>Marketplace :</strong> Vous avez <strong>{{ pendingMarketplaceOrdersCount }}</strong> nouvelle(s) commande(s) de pièces à traiter !
                    </div>
                    <Link href="/marketplace-gestion/commandes" class="btn btn-success btn-sm ms-2 text-nowrap">Voir les commandes</Link>
                </div>

                <div v-if="pendingAppointmentRequestsCount > 0" class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-calendar-heart-fill me-2"></i>
                        {{ t('layoutExtra.newAppointmentRequestsBanner', { count: pendingAppointmentRequestsCount }) }}
                    </div>
                    <Link href="/rendez-vous" class="btn btn-warning btn-sm ms-2 text-nowrap fw-semibold text-dark">{{ t('layoutExtra.reviewAndValidate') }}</Link>
                </div>

                <div v-if="payrollAlert" class="alert alert-info d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-bell-fill me-2"></i>
                        {{ t('layout.payrollAlert', { days: payrollAlert.daysRemaining, date: payrollAlert.payDate }) }}
                    </div>
                    <Link href="/rh" class="btn btn-primary btn-sm ms-2 text-nowrap">{{ t('layout.hr') }}</Link>
                </div>

                <div v-if="tenant?.slug === 'demo-douala'" class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 py-2">
                    <div class="small">
                        <i class="bi bi-play-circle me-1"></i>{{ t('layout.demoBanner') }}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="/demo/quitter?to=login" class="btn btn-outline-dark btn-sm text-nowrap">{{ t('nav.login') }}</a>
                        <a href="/demo/quitter?to=register" class="btn btn-primary btn-sm text-nowrap">{{ t('nav.register') }}</a>
                    </div>
                </div>

                <div v-if="flash?.success" class="alert alert-success alert-dismissible fade show py-2 small">
                    {{ flash.success }}
                </div>
                <div v-if="flash?.error" class="alert alert-danger alert-dismissible fade show py-2 small">
                    {{ flash.error }}
                </div>

                <PageSkeleton v-if="isNavigating" />
                <slot v-else />
            </main>
        </div>

        <!-- Static Modal de confirmation de déconnexion -->
        <StaticModal id="modalConfirmLogout" :title="t('layout.logoutTitle')">
            <p class="mb-4">{{ t('layout.logoutConfirm') }} <strong>{{ tenant?.name }}</strong></p>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ t('layout.cancel') }}</button>
                <button type="button" class="btn btn-danger px-4" @click="confirmLogout">
                    <i class="bi bi-box-arrow-right me-1"></i>{{ t('layout.confirm') }}
                </button>
            </div>
        </StaticModal>

        <!-- Static Modal de réponse rapide depuis les notifications -->
        <StaticModal id="modalQuickReplyNotification" title="Répondre au message">
            <form @submit.prevent="submitQuickReply">
                <div v-if="replyingNotification" class="alert alert-light border small mb-3">
                    <div class="fw-bold">{{ replyingNotification.sender_name }} — {{ replyingNotification.subject }}</div>
                    <div class="text-muted mt-1">{{ replyingNotification.body }}</div>
                    <div v-if="replyingNotification.sender_type === 'admin'" class="text-info mt-1 fw-semibold">
                        <i class="bi bi-shield-lock me-1"></i>Message interne Admin (aucun e-mail envoyé)
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Votre réponse</label>
                    <textarea v-model="quickReplyForm.body" rows="4" class="form-control" required></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold">Pièces jointes (image, pdf, docx, excel, txt)</label>
                    <FileDropzone
                        v-model="quickReplyForm.attachments"
                        :multiple="true"
                        accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,image/*"
                        label="Glisser-déposer un ou plusieurs fichiers"
                    />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary px-4" :disabled="quickReplyForm.processing">
                        <i class="bi bi-send me-1"></i>Envoyer la réponse
                    </button>
                </div>
            </form>
        </StaticModal>

        <ConfirmStaticModal />
        <ImageLightbox />
        <BackToTop />
    </div>
</template>

<style scoped>
/* Desktop Vertical Sidebar FIXE à gauche */
.tenant-sidebar-fixed {
    position: fixed;
    top: 0;
    left: 0;
    width: 264px;
    height: 100vh;
    z-index: 1030;
    background: linear-gradient(180deg, #0f172a 0%, #111c33 100%);
    color: #e2e8f0;
    border-right: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 4px 0 24px rgba(15, 23, 42, 0.14);
}

.tenant-sidebar-scroll {
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
}

.tenant-sidebar-scroll::-webkit-scrollbar {
    width: 5px;
}

.tenant-sidebar-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.35);
    border-radius: 999px;
}

.tenant-sidebar-group-title {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: rgba(148, 163, 184, 0.78);
}

.tenant-sidebar-link {
    color: #cbd5e1;
    border-radius: 10px;
    font-weight: 500;
    transition: background-color 0.16s ease, color 0.16s ease;
}

.tenant-sidebar-link:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}

.tenant-sidebar-link.is-active {
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
}

/* Décalage de la colonne principale droite sur desktop pour laisser place à la Sidebar fixe */
@media (min-width: 992px) {
    .tenant-main-column {
        margin-left: 264px;
    }
}
</style>
