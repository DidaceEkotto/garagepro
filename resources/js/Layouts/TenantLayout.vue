<script setup>
import BackToTop from '@/Components/BackToTop.vue';
import ConfirmStaticModal from '@/Components/ConfirmStaticModal.vue';
import FileDropzone from '@/Components/FileDropzone.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import StaticModal from '@/Components/StaticModal.vue';
import { useI18n } from '@/composables/useI18n';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const { t } = useI18n();
const page = usePage();
const tenant = computed(() => page.props.tenant);
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash);
const payrollAlert = computed(() => page.props.payrollAlert);
const affiliateAlert = computed(() => page.props.affiliateAlert ?? null);
const unreadMessagesCount = computed(() => page.props.unreadMessagesCount ?? 0);
const pendingMarketplaceOrdersCount = computed(() => page.props.pendingMarketplaceOrdersCount ?? 0);
const pendingAppointmentRequestsCount = computed(() => page.props.pendingAppointmentRequestsCount ?? 0);
const recentNotifications = computed(() => page.props.recentNotifications ?? []);
const currentUrl = computed(() => page.url);
const marketplaceMenuOpen = ref(currentUrl.value.startsWith('/marketplace-gestion'));

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
});

onBeforeUnmount(() => {
    if (timerId) clearInterval(timerId);
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
    const rawGroups = [
        {
            title: t('layout.dashboard'),
            items: [
                { label: t('layout.dashboard'), href: '/tableau-de-bord', icon: 'bi-speedometer2', permissions: [] },
                { label: 'Tutoriel & Guide', href: '/tutoriel', icon: 'bi-book', permissions: [] },
            ],
        },
        {
            title: t('layout.workshop'),
            items: [
                { label: t('layout.customers'), href: '/clients', icon: 'bi-people', permissions: ['clients.view', 'clients.manage'] },
                { label: 'Véhicules', href: '/vehicules', icon: 'bi-car-front', permissions: ['clients.view', 'clients.manage'] },
                { label: 'Flottes B2B', href: '/flottes', icon: 'bi-buildings', permissions: ['clients.view', 'clients.manage'] },
                { label: t('layout.appointments'), href: '/rendez-vous', icon: 'bi-calendar-check', permissions: ['appointments.view', 'appointments.manage'] },
                { label: 'Planning Atelier', href: '/planning-atelier', icon: 'bi-calendar3', permissions: ['appointments.view', 'repair_orders.view'] },
                { label: 'Rappels Entretien', href: '/rappels-entretien', icon: 'bi-bell', permissions: ['clients.view', 'clients.manage'] },
                { label: t('layout.receptions'), href: '/receptions', icon: 'bi-clipboard-check', permissions: ['receptions.manage'] },
                { label: 'Inspections (DVI)', href: '/inspections', icon: 'bi-camera', permissions: ['repair_orders.view', 'diagnostics.manage'] },
                { label: 'Visite Technique', href: '/visite-technique', icon: 'bi-clipboard2-check', permissions: ['clients.view', 'repair_orders.view', 'diagnostics.manage'] },
                { label: 'Assurances & Sinistres', href: '/assurances', icon: 'bi-shield-check', permissions: ['clients.view', 'invoices.view', 'repair_orders.view'] },
                { label: t('layout.repairOrders'), href: '/ordres-reparation', icon: 'bi-wrench-adjustable', permissions: ['repair_orders.view', 'repair_orders.manage'] },
                { label: 'Sous-traitance & Dépannage', href: '/sous-traitance', icon: 'bi-truck-front', permissions: ['repair_orders.view', 'repair_orders.manage'] },
                { label: t('layout.quotes'), href: '/devis', icon: 'bi-file-earmark-text', permissions: ['quotes.view', 'quotes.manage'] },
                { label: t('layout.workshop'), href: '/atelier/mes-or', icon: 'bi-tools', permissions: ['repair_orders.view', 'repair_orders.view_own', 'diagnostics.manage'] },
            ],
        },
        {
            title: t('layout.accounting'),
            items: [
                { label: t('layout.inventory'), href: '/stock', icon: 'bi-box-seam', permissions: ['stock.view', 'stock.manage'] },
                { label: 'Outillage & Vrac', href: '/outillage-vrac', icon: 'bi-droplet-half', permissions: ['stock.view', 'stock.manage'] },
                { label: t('layout.purchasing'), href: '/approvisionnement', icon: 'bi-truck', permissions: ['purchase_orders.manage', 'suppliers.manage'] },
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
                { label: t('layoutExtra.messaging'), href: '/messagerie', icon: 'bi-chat-dots', permissions: [] },
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
            items: group.items.filter((item) => hasAnyPermission(item.permissions)),
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

function confirmLogout() {
    window.bootstrap?.Modal.getInstance(document.getElementById('modalConfirmLogout'))?.hide();
    router.post('/deconnexion');
}

function resetDemo() {
    router.post('/demo/reinitialiser');
}
</script>

<template>
    <div class="min-vh-100 d-flex flex-column bg-light">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm px-3" style="z-index: 1030;">
            <div class="container-fluid">
                <div class="d-flex align-items-center gap-2">
                    <button
                        class="btn btn-outline-light btn-sm d-lg-none"
                        type="button"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#mobileSidebar"
                    >
                        <i class="bi bi-list"></i>
                    </button>
                    <Link class="navbar-brand fw-bold d-flex align-items-center gap-2 mb-0" href="/tableau-de-bord">
                        <img
                            v-if="tenant?.logoUrl"
                            :src="tenant.logoUrl"
                            alt="Logo"
                            class="rounded bg-white p-1"
                            style="height: 32px; width: 32px; object-fit: contain;"
                        />
                        <img
                            v-else
                            :src="page.props.app?.site?.logo_url || '/assets/images/logo.png'"
                            alt="MboaCar"
                            class="rounded bg-white px-2 py-1"
                            style="height: 32px; width: auto; object-fit: contain;"
                        />
                        <span>{{ tenant?.name ?? 'MboaCar' }}</span>
                    </Link>
                </div>

                <div class="d-flex align-items-center gap-2 text-white">
                    <span v-if="subscriptionCountdown" class="badge bg-warning text-dark d-none d-md-inline">
                        <i class="bi bi-hourglass-split me-1"></i>{{ subscriptionCountdown }}
                    </span>
                    <span class="badge bg-light text-primary d-none d-sm-inline">{{ tenant?.status }}</span>
                    <button
                        v-if="tenant?.slug === 'demo-douala'"
                        type="button"
                        class="btn btn-warning btn-sm text-dark fw-semibold"
                        @click="resetDemo"
                    >
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ t('layout.resetDemo') }}
                    </button>

                    <LanguageSwitcher variant="light" />

                    <!-- Icône Messages / Notifications avec compteur -->
                    <div class="dropdown">
                        <button
                            class="btn btn-outline-light btn-sm position-relative rounded-pill px-2"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            title="Messages & Notifications"
                        >
                            <i class="bi bi-chat-dots-fill"></i>
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

                    <!-- Menu déroulant Profil Utilisateur -->
                    <div class="dropdown">
                        <button
                            class="btn btn-link text-white text-decoration-none dropdown-toggle d-flex align-items-center gap-2 py-1 px-2"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            <img
                                v-if="auth?.user?.photoUrl"
                                :src="auth.user.photoUrl"
                                alt="Profil"
                                class="rounded-circle border border-light"
                                style="width: 32px; height: 32px; object-fit: cover;"
                            />
                            <span
                                v-else
                                class="rounded-circle bg-white text-primary fw-bold d-inline-flex align-items-center justify-content-center"
                                style="width: 32px; height: 32px; font-size: 0.8rem;"
                            >
                                {{ userInitials }}
                            </span>
                            <span class="small d-none d-md-inline">{{ auth?.user?.name }}</span>
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

        <div class="d-flex flex-grow-1">
            <!-- Desktop Vertical Sidebar -->
            <aside class="bg-white border-end shadow-sm d-none d-lg-flex flex-column p-3" style="width: 260px; min-width: 260px;">
                <div v-for="group in menuGroups" :key="group.title" class="mb-3">
                    <div class="text-uppercase text-muted fw-bold px-2 mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                        {{ group.title }}
                    </div>
                    <ul class="nav nav-pills flex-column gap-1">
                        <li v-for="item in group.items" :key="item.href" class="nav-item">
                            <Link
                                :href="item.href"
                                class="nav-link sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small"
                                :class="{ active: isActive(item.href) }"
                            >
                                <span class="d-flex align-items-center gap-2">
                                    <i :class="`bi ${item.icon}`"></i>
                                    <span>{{ item.label }}</span>
                                </span>
                                <span v-if="item.href === '/rendez-vous' && pendingAppointmentRequestsCount > 0" class="badge bg-warning text-dark rounded-pill">
                                    {{ pendingAppointmentRequestsCount }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Menu déroulant Marketplace -->
                <div v-if="!tenant?.isReadOnly" class="mb-3">
                    <button
                        type="button"
                        class="btn btn-light w-100 d-flex align-items-center justify-content-between py-2 px-3 small fw-semibold border"
                        @click="marketplaceMenuOpen = !marketplaceMenuOpen"
                    >
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-shop text-primary"></i>
                            <span>{{ t('layoutExtra.marketplace') }}</span>
                            <span v-if="pendingMarketplaceOrdersCount > 0" class="badge bg-danger rounded-pill">
                                {{ pendingMarketplaceOrdersCount }}
                            </span>
                        </span>
                        <i class="bi" :class="marketplaceMenuOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </button>
                    <ul v-show="marketplaceMenuOpen" class="nav nav-pills flex-column gap-1 mt-1 ps-2">
                        <li class="nav-item">
                            <Link
                                href="/marketplace-gestion/produits"
                                class="nav-link sidebar-link d-flex align-items-center gap-2 py-2 px-3 small"
                                :class="{ active: isActive('/marketplace-gestion/produits') }"
                            >
                                <i class="bi bi-box2-heart"></i><span>{{ t('layoutExtra.myProducts') }}</span>
                            </Link>
                        </li>
                        <li class="nav-item">
                            <Link
                                href="/marketplace-gestion/commandes"
                                class="nav-link sidebar-link d-flex align-items-center justify-content-between py-2 px-3 small"
                                :class="{ active: isActive('/marketplace-gestion/commandes') }"
                            >
                                <span class="d-flex align-items-center gap-2"><i class="bi bi-bag-check"></i><span>{{ t('layoutExtra.orders') }}</span></span>
                                <span v-if="pendingMarketplaceOrdersCount > 0" class="badge bg-danger rounded-pill">
                                    {{ pendingMarketplaceOrdersCount }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </aside>

            <!-- Mobile Offcanvas Sidebar -->
            <div id="mobileSidebar" class="offcanvas offcanvas-start" tabindex="-1">
                <div class="offcanvas-header border-bottom">
                    <h5 class="offcanvas-title fw-bold">{{ tenant?.name ?? 'GaragePro' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
                </div>
                <div class="offcanvas-body">
                    <div v-for="group in menuGroups" :key="group.title" class="mb-3">
                        <div class="text-uppercase text-muted fw-bold px-2 mb-1" style="font-size: 0.7rem;">
                            {{ group.title }}
                        </div>
                        <ul class="nav nav-pills flex-column gap-1">
                            <li v-for="item in group.items" :key="item.href" class="nav-item">
                                <Link
                                    :href="item.href"
                                    class="nav-link sidebar-link d-flex align-items-center gap-2 py-2 px-3 small"
                                    :class="{ active: isActive(item.href) }"
                                >
                                    <i :class="`bi ${item.icon}`"></i>
                                    <span>{{ item.label }}</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <main class="flex-grow-1 min-w-0 p-3 p-md-4" style="overflow-x: clip;">
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
                        <strong>Nouveau rendez-vous en ligne :</strong> Vous avez <strong>{{ pendingAppointmentRequestsCount }}</strong> demande(s) reçue(s) via lien sécurisé à valider !
                    </div>
                    <Link href="/rendez-vous" class="btn btn-warning btn-sm ms-2 text-nowrap fw-semibold text-dark">Examiner &amp; Valider</Link>
                </div>

                <div v-if="payrollAlert" class="alert alert-info d-flex justify-content-between align-items-center mb-3 py-2 small">
                    <div>
                        <i class="bi bi-bell-fill me-2"></i>
                        {{ t('layout.payrollAlert', { days: payrollAlert.daysRemaining, date: payrollAlert.payDate }) }}
                    </div>
                    <Link href="/rh" class="btn btn-primary btn-sm ms-2 text-nowrap">{{ t('layout.hr') }}</Link>
                </div>

                <div v-if="tenant?.slug === 'demo-douala'" class="alert alert-warning d-flex justify-content-between align-items-center mb-3 py-2">
                    <div class="small">
                        <i class="bi bi-play-circle me-1"></i>{{ t('layout.demoBanner') }}
                    </div>
                    <Link href="/inscription" class="btn btn-primary btn-sm ms-2 text-nowrap">{{ t('nav.register') }}</Link>
                </div>

                <div v-if="flash?.success" class="alert alert-success alert-dismissible fade show py-2 small">
                    {{ flash.success }}
                </div>
                <div v-if="flash?.error" class="alert alert-danger alert-dismissible fade show py-2 small">
                    {{ flash.error }}
                </div>

                <slot />
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
        <BackToTop />
    </div>
</template>
