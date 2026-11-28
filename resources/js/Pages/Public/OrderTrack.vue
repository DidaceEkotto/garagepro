<script setup>
import LandingFooter from '@/Components/LandingFooter.vue';
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
});

const page = usePage();
const siteLogo = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

const itemStates = reactive({});
for (const it of props.order.items || []) {
    itemStates[it.id] = {
        seller_status: it.seller_status,
        messages: [...(it.messages || [])],
        draft: '',
        sending: false,
    };
}

let pollTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function pollItem(itemId) {
    if (document.hidden) return;
    try {
        const resp = await fetch(`/suivi-commande/${props.order.tracking_token}/articles/${itemId}/messages`, {
            headers: { Accept: 'application/json' },
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data?.ok) {
            itemStates[itemId].seller_status = data.seller_status;
            itemStates[itemId].messages = data.messages || [];
        }
    } catch {}
}

async function sendBuyerMessage(itemId) {
    const st = itemStates[itemId];
    if (!st || !st.draft.trim() || st.sending) return;
    st.sending = true;
    try {
        const resp = await fetch(`/suivi-commande/${props.order.tracking_token}/articles/${itemId}/messages`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ body: st.draft.trim() }),
        });
        const data = await resp.json();
        if (data?.ok && data?.message) {
            st.messages.push(data.message);
            st.draft = '';
        }
    } finally {
        st.sending = false;
    }
}

onMounted(() => {
    pollTimer = setInterval(() => {
        for (const it of props.order.items || []) {
            pollItem(it.id);
        }
    }, 5000);
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});

function statusLabel(st) {
    const map = {
        nouvelle: 'Commande reçue',
        confirmee: 'Confirmée par le vendeur',
        expediee: 'En chemin vers vous 🚚',
        livree: 'Livrée ✅',
        annulee: 'Annulée',
    };
    return map[st] || st;
}

function statusBadgeClass(st) {
    if (st === 'livree') return 'bg-success text-white';
    if (st === 'expediee') return 'bg-primary text-white';
    if (st === 'confirmee') return 'bg-info text-dark';
    if (st === 'annulee') return 'bg-secondary text-white';
    return 'bg-warning text-dark';
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head :title="`Suivi commande ${order.order_number} | MboaCar`" />

    <div class="min-vh-100 d-flex flex-column bg-light">
        <nav class="navbar navbar-light bg-white border-bottom py-2">
            <div class="container">
                <Link href="/" class="navbar-brand d-flex align-items-center gap-2">
                    <img :src="siteLogo" alt="MboaCar" style="height: 36px; width: auto; object-fit: contain;" />
                </Link>
                <Link href="/marketplace" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
                    <i class="bi bi-shop me-1"></i>Marketplace
                </Link>
            </div>
        </nav>

        <main class="container py-4 flex-grow-1" style="max-width: 1020px;">
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 mb-2">Suivi de commande en direct</span>
                        <h1 class="h4 fw-bold mb-1">Commande {{ order.order_number }}</h1>
                        <div class="small text-muted">
                            Client : <strong>{{ order.buyer_name }}</strong> • Passée le {{ order.created_at }}
                        </div>
                        <div class="small text-dark mt-1">
                            <i class="bi bi-geo-alt-fill text-primary me-1"></i>Livraison : <strong>{{ order.delivery_address }}</strong>
                        </div>
                    </div>
                    <div class="text-md-end">
                        <div class="small text-muted">Montant total</div>
                        <div class="h4 fw-bold text-primary mb-0">{{ fmt(order.total_amount) }}</div>
                    </div>
                </div>
            </div>

            <div v-for="item in order.items" :key="item.id" class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-bold text-dark">{{ item.product_title }} &times; {{ item.quantity }}</div>
                        <div class="small text-muted">
                            <i class="bi bi-shop text-primary me-1"></i>Vendeur : <strong>{{ item.seller?.name }}</strong>
                            <span v-if="item.seller?.formatted_location"> • {{ item.seller.formatted_location }}</span>
                        </div>
                    </div>
                    <span class="badge rounded-pill px-3 py-2" :class="statusBadgeClass(itemStates[item.id]?.seller_status)">
                        {{ statusLabel(itemStates[item.id]?.seller_status) }}
                    </span>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <!-- Carte itinéraire si GPS dispo -->
                        <div v-if="order.delivery_latitude && order.delivery_longitude" class="col-lg-6">
                            <div class="small fw-bold text-dark mb-2">
                                <i class="bi bi-map-fill text-primary me-1"></i>Itinéraire vendeur → votre adresse
                            </div>
                            <div class="rounded-4 overflow-hidden border">
                                <OsmRouteMap
                                    :target-lat="order.delivery_latitude"
                                    :target-lon="order.delivery_longitude"
                                    :target-label="`Votre livraison : ${order.delivery_address}`"
                                    :origin-lat="item.seller?.latitude ?? null"
                                    :origin-lon="item.seller?.longitude ?? null"
                                    :origin-label="item.seller?.name || 'Boutique'"
                                    height="270px"
                                />
                            </div>
                        </div>

                        <!-- Chat en direct Client -> Vendeur -->
                        <div :class="order.delivery_latitude && order.delivery_longitude ? 'col-lg-6' : 'col-12'">
                            <div class="small fw-bold text-dark mb-2 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-chat-dots-fill text-primary me-1"></i>Discuter avec {{ item.seller?.name }}</span>
                                <span class="badge bg-success-subtle text-success rounded-pill" style="font-size: 0.68rem;">En direct</span>
                            </div>
                            <div class="border rounded-4 overflow-hidden d-flex flex-column bg-white">
                                <div class="p-3 bg-light overflow-auto d-flex flex-column gap-2" style="height: 230px;">
                                    <div v-if="!itemStates[item.id]?.messages?.length" class="text-center text-muted small my-auto">
                                        Posez une question ou donnez une précision sur le point de rendez-vous au vendeur.
                                    </div>
                                    <div
                                        v-for="m in itemStates[item.id]?.messages"
                                        :key="m.id"
                                        class="d-flex"
                                        :class="m.sender_type === 'buyer' ? 'justify-content-end' : 'justify-content-start'"
                                    >
                                        <div
                                            class="p-2 px-3 rounded-3 small shadow-sm"
                                            style="max-width: 85%;"
                                            :class="m.sender_type === 'buyer' ? 'bg-primary text-white' : 'bg-white border text-dark'"
                                        >
                                            <div class="fw-bold mb-1" style="font-size: 0.7rem; opacity: 0.85;">
                                                {{ m.sender_name }} • {{ m.created_at }}
                                            </div>
                                            <div style="white-space: pre-wrap;">{{ m.body }}</div>
                                        </div>
                                    </div>
                                </div>
                                <form class="p-2 border-top bg-white" @submit.prevent="sendBuyerMessage(item.id)">
                                    <div class="input-group input-group-sm">
                                        <input
                                            v-model="itemStates[item.id].draft"
                                            type="text"
                                            class="form-control"
                                            placeholder="Écrire au vendeur (ex: Je suis devant la pharmacie)..."
                                            :disabled="itemStates[item.id].sending"
                                        />
                                        <button type="submit" class="btn btn-primary px-3" :disabled="itemStates[item.id].sending || !itemStates[item.id].draft.trim()">
                                            <span v-if="itemStates[item.id].sending" class="spinner-border spinner-border-sm"></span>
                                            <i v-else class="bi bi-send-fill"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <LandingFooter />
    </div>
</template>
