<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    orderItems: Object,
});

function updateStatus(id, status) {
    router.patch(`/marketplace-gestion/commandes/${id}/statut`, { seller_status: status });
}
</script>

<template>
    <Head title="Marketplace — Commandes reçues" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Commandes reçues du Marketplace</h1>
            <p class="text-muted small mb-0">Suivez et traitez les commandes passées par les clients en ligne</p>
        </div>

        <div class="card border-0 shadow-sm">
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
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!orderItems.data?.length">
                            <td colspan="6" class="text-center text-muted py-4 small">Aucune commande Marketplace pour le moment.</td>
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
                            <td class="small">{{ item.order?.delivery_address }}</td>
                            <td class="small">
                                <strong>{{ item.product_title }}</strong> &times; {{ item.quantity }}
                            </td>
                            <td class="text-end fw-bold text-primary">
                                {{ new Intl.NumberFormat('fr-FR').format(item.subtotal) }} FCFA
                            </td>
                            <td>
                                <select
                                    :value="item.seller_status"
                                    class="form-select form-select-sm"
                                    @change="updateStatus(item.id, $event.target.value)"
                                >
                                    <option value="nouvelle">Nouvelle</option>
                                    <option value="confirmee">Confirmée</option>
                                    <option value="expediee">Expédiée</option>
                                    <option value="livree">Livrée</option>
                                    <option value="annulee">Annulée</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </TenantLayout>
</template>
