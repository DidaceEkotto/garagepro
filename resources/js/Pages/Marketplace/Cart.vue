<script setup>
import MarketplaceLayout from '@/Layouts/MarketplaceLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    cartItems: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
});

function updateQty(id, qty) {
    router.patch(`/panier/${id}`, { quantity: qty }, { preserveScroll: true });
}

function removeItem(id) {
    router.delete(`/panier/${id}`, { preserveScroll: true });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Mon Panier — Marketplace" />
    <MarketplaceLayout>
        <div class="container py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 fw-bold mb-0"><i class="bi bi-cart3 text-primary me-2"></i>Mon Panier</h1>
                <Link href="/marketplace" class="btn btn-outline-secondary btn-sm">Continuer mes achats</Link>
            </div>

            <div v-if="!cartItems.length" class="card border-0 shadow-sm rounded-4 p-5 text-center">
                <i class="bi bi-cart-x fs-1 text-muted mb-2"></i>
                <h2 class="h5 fw-bold">Votre panier est vide</h2>
                <p class="text-muted small mb-4">Parcourez le Marketplace pour trouver vos pièces automobiles.</p>
                <Link href="/marketplace" class="btn btn-primary mx-auto px-4">Explorer le Marketplace</Link>
            </div>

            <div v-else class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="list-group list-group-flush">
                            <div v-for="item in cartItems" :key="item.id" class="list-group-item p-3 p-md-4">
                                <div class="row align-items-center g-3">
                                    <div class="col-3 col-md-2">
                                        <img
                                            v-if="item.primary_image"
                                            :src="item.primary_image"
                                            :alt="item.title"
                                            class="rounded-3 border w-100"
                                            style="height: 70px; object-fit: cover;"
                                        />
                                        <div v-else class="bg-light border rounded-3 d-flex align-items-center justify-content-center" style="height: 70px;">
                                            <i class="bi bi-car-front text-muted"></i>
                                        </div>
                                    </div>
                                    <div class="col-9 col-md-4">
                                        <Link :href="`/marketplace/produit/${item.slug || item.id}`" class="fw-bold text-dark text-decoration-none">
                                            {{ item.title }}
                                        </Link>
                                        <div class="small text-muted">{{ item.seller?.name }}</div>
                                        <div class="small text-primary fw-semibold">{{ fmt(item.price) }}</div>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <div class="input-group input-group-sm" style="max-width: 120px;">
                                            <button type="button" class="btn btn-outline-secondary" @click="updateQty(item.id, item.quantity - 1)">-</button>
                                            <input type="text" class="form-control text-center bg-white" :value="item.quantity" readonly />
                                            <button type="button" class="btn btn-outline-secondary" @click="updateQty(item.id, item.quantity + 1)">+</button>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 text-end">
                                        <div class="fw-bold mb-1">{{ fmt(item.subtotal) }}</div>
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 text-decoration-none" @click="removeItem(item.id)">
                                            <i class="bi bi-trash me-1"></i>Retirer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h2 class="h5 fw-bold mb-3">Récapitulatif</h2>
                        <div class="d-flex justify-content-between mb-2 small text-muted">
                            <span>Articles ({{ cartItems.reduce((s, i) => s + i.quantity, 0) }})</span>
                            <span>{{ fmt(total) }}</span>
                        </div>
                        <hr />
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <span class="fw-bold">Total</span>
                            <span class="h4 fw-bold text-primary mb-0">{{ fmt(total) }}</span>
                        </div>
                        <Link href="/commander" class="btn btn-primary btn-lg w-100 fw-semibold">
                            Valider la commande <i class="bi bi-arrow-right ms-1"></i>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </MarketplaceLayout>
</template>
