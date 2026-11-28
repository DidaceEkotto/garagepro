<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    orderItems: Object,
    shopStats: {
        type: Object,
        default: () => ({
            pendingOrders: 0,
            deliveredOrders: 0,
            cancelledOrders: 0,
            soldUnits: 0,
            soldRevenue: 0,
        }),
    },
});

const statusMeta = {
    nouvelle: { label: 'Nouvelle', badge: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle', icon: 'bi-clock-history' },
    confirmee: { label: 'Confirmée', badge: 'bg-primary-subtle text-primary border border-primary-subtle', icon: 'bi-check-circle' },
    expediee: { label: 'En route / Expédiée', badge: 'bg-info-subtle text-info-emphasis border border-info-subtle', icon: 'bi-truck' },
    livree: { label: 'Livrée & Vendue', badge: 'bg-success text-white', icon: 'bi-patch-check-fill' },
    annulee: { label: 'Annulée', badge: 'bg-danger-subtle text-danger border border-danger-subtle', icon: 'bi-x-circle' },
};

function getStatus(code) {
    return statusMeta[code] || { label: code || 'Inconnu', badge: 'bg-secondary-subtle text-secondary', icon: 'bi-circle' };
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Marketplace — Commandes reçues" />
    <TenantLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-0">Commandes reçues du Marketplace</h1>
                <p class="text-muted small mb-0">
                    Ouvrez les détails d'une commande pour la confirmer, notifier le départ sur WhatsApp, la marquer comme livrée ou l'annuler.
                </p>
            </div>
            <Link href="/marketplace-gestion/produits" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                <i class="bi bi-box2-heart me-1"></i>Gérer mes produits
            </Link>
        </div>

        <!-- Statistiques boutique Marketplace -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                    <div class="small text-muted">Commandes en cours</div>
                    <div class="h4 fw-bold text-warning mb-0">{{ shopStats.pendingOrders }}</div>
                    <div class="small text-muted">Nouvelles, confirmées ou en route</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                    <div class="small text-muted">Commandes livrées &amp; vendues</div>
                    <div class="h4 fw-bold text-success mb-0">{{ shopStats.deliveredOrders }}</div>
                    <div class="small text-muted">{{ shopStats.soldUnits }} unité(s) vendue(s)</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                    <div class="small text-muted">Chiffre d'affaires encaissé</div>
                    <div class="h4 fw-bold text-primary mb-0">{{ fmt(shopStats.soldRevenue) }}</div>
                    <div class="small text-muted">Uniquement les ventes livrées</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                    <div class="small text-muted">Commandes annulées</div>
                    <div class="h4 fw-bold text-danger mb-0">{{ shopStats.cancelledOrders }}</div>
                    <div class="small text-muted">Stock automatiquement restauré</div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Commande</th>
                            <th>Client &amp; WhatsApp</th>
                            <th>Livraison</th>
                            <th>Article &amp; Qté</th>
                            <th class="text-end">Sous-total</th>
                            <th>Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!orderItems.data?.length">
                            <td colspan="7" class="text-center text-muted py-4 small">Aucune commande Marketplace pour le moment.</td>
                        </tr>
                        <tr v-for="item in orderItems.data" :key="item.id">
                            <td>
                                <div class="fw-bold">{{ item.order?.order_number }}</div>
                                <div class="small text-muted">{{ new Date(item.created_at).toLocaleString('fr-FR') }}</div>
                            </td>
                            <td class="small">
                                <div class="fw-semibold">{{ item.order?.buyer_name }}</div>
                                <div>{{ item.order?.buyer_email }}</div>
                                <div class="text-success fw-semibold">
                                    <i class="bi bi-whatsapp me-1"></i>{{ item.order?.dial_code }} {{ item.order?.buyer_whatsapp }}
                                </div>
                            </td>
                            <td class="small" style="max-width: 220px;">
                                <div class="text-truncate" :title="item.order?.delivery_address">{{ item.order?.delivery_address }}</div>
                            </td>
                            <td class="small">
                                <strong>{{ item.product_title }}</strong> &times; {{ item.quantity }}
                            </td>
                            <td class="text-end fw-bold text-primary text-nowrap">
                                {{ fmt(item.subtotal) }}
                            </td>
                            <td class="text-nowrap">
                                <span class="badge rounded-pill px-3 py-2 fw-semibold" :class="getStatus(item.seller_status).badge">
                                    <i class="bi me-1" :class="getStatus(item.seller_status).icon"></i>
                                    {{ getStatus(item.seller_status).label }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <Link
                                    :href="`/marketplace-gestion/commandes/${item.id}`"
                                    class="btn btn-outline-primary btn-sm rounded-pill px-3 position-relative"
                                >
                                    <i class="bi bi-eye me-1"></i>Détails
                                    <span
                                        v-if="item.unread_buyer_messages > 0"
                                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                        style="font-size: 0.65rem;"
                                    >
                                        {{ item.unread_buyer_messages }}
                                    </span>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </TenantLayout>
</template>
