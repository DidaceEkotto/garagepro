<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    orderItems: Object,
});

function updateStatus(id, status) {
    router.patch(`/marketplace-gestion/commandes/${id}/statut`, { seller_status: status }, { preserveScroll: true });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Marketplace — Commandes reçues" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Commandes reçues du Marketplace</h1>
            <p class="text-muted small mb-0">Suivez vos commandes, ouvrez la page détails pour tracer l'itinéraire, signaler le départ sur WhatsApp et discuter avec le client</p>
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
                            <td>
                                <select
                                    :value="item.seller_status"
                                    class="form-select form-select-sm"
                                    style="min-width: 130px;"
                                    @change="updateStatus(item.id, $event.target.value)"
                                >
                                    <option value="nouvelle">Nouvelle</option>
                                    <option value="confirmee">Confirmée</option>
                                    <option value="expediee">En route / Expédiée</option>
                                    <option value="livree">Livrée</option>
                                    <option value="annulee">Annulée</option>
                                </select>
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
