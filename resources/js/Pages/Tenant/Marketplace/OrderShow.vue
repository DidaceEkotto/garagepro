<script setup>
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    orderItem: { type: Object, required: true },
    tenantLocation: { type: Object, default: () => ({}) },
});

const status = ref(props.orderItem.seller_status);
const deliveryCoords = ref(null);
const geocodingFallback = ref(false);
const departProcessing = ref(false);
const copiedTracking = ref(false);

const chatMessages = ref([...(props.orderItem.messages || [])]);
const chatBody = ref('');
const chatSendWhatsapp = ref(true);
const chatSending = ref(false);
let chatPollTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function updateStatus(nextStatus) {
    status.value = nextStatus;
    router.patch(`/marketplace-gestion/commandes/${props.orderItem.id}/statut`, { seller_status: nextStatus }, { preserveScroll: true });
}

async function resolveDeliveryCoords() {
    const order = props.orderItem.order;
    if (!order) return;

    if (order.delivery_latitude && order.delivery_longitude) {
        deliveryCoords.value = { lat: Number(order.delivery_latitude), lon: Number(order.delivery_longitude) };
        return;
    }

    if (order.delivery_address) {
        geocodingFallback.value = true;
        try {
            const params = new URLSearchParams({
                q: order.delivery_address,
                country: order.country_code || '',
            });
            const resp = await fetch(`/api/geocode/search?${params.toString()}`, { headers: { Accept: 'application/json' } });
            const data = await resp.json();
            const first = data?.results?.[0];
            if (first?.lat && first?.lon) {
                deliveryCoords.value = { lat: Number(first.lat), lon: Number(first.lon) };
            }
        } catch {
            // non-blocking
        } finally {
            geocodingFallback.value = false;
        }
    }
}

async function fetchMessages() {
    if (document.hidden) return;
    try {
        const resp = await fetch(`/marketplace-gestion/commandes/${props.orderItem.id}/messages`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data?.ok) {
            chatMessages.value = data.messages || [];
        }
    } catch {}
}

function triggerDepart() {
    if (departProcessing.value) return;
    departProcessing.value = true;
    router.post(`/marketplace-gestion/commandes/${props.orderItem.id}/depart`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            status.value = 'expediee';
            fetchMessages();
        },
        onFinish: () => {
            departProcessing.value = false;
        },
    });
}

async function sendSellerMessage() {
    if (!chatBody.value.trim() || chatSending.value) return;
    chatSending.value = true;
    try {
        const resp = await fetch(`/marketplace-gestion/commandes/${props.orderItem.id}/messages`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                body: chatBody.value.trim(),
                send_whatsapp: chatSendWhatsapp.value,
            }),
        });
        const data = await resp.json();
        if (data?.ok && data?.message) {
            chatMessages.value.push(data.message);
            chatBody.value = '';
        }
    } finally {
        chatSending.value = false;
    }
}

async function copyTrackingUrl() {
    if (!props.orderItem.tracking_url) return;
    try {
        await navigator.clipboard.writeText(props.orderItem.tracking_url);
        copiedTracking.value = true;
        setTimeout(() => { copiedTracking.value = false; }, 2200);
    } catch {}
}

onMounted(() => {
    resolveDeliveryCoords();
    chatPollTimer = setInterval(fetchMessages, 5000);
});

onBeforeUnmount(() => {
    if (chatPollTimer) clearInterval(chatPollTimer);
});

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head :title="`Commande ${orderItem.order?.order_number} — Détails`" />
    <TenantLayout>
        <!-- En-tête -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="mb-1">
                    <Link href="/marketplace-gestion/commandes" class="text-decoration-none small fw-semibold text-primary">
                        <i class="bi bi-arrow-left me-1"></i>Retour aux commandes
                    </Link>
                </div>
                <h1 class="h4 fw-bold text-dark mb-0">
                    Commande {{ orderItem.order?.order_number }} — {{ orderItem.order?.buyer_name }}
                </h1>
                <div class="small text-muted">
                    Passée le {{ orderItem.created_at_formatted }} • {{ orderItem.product_title }} &times; {{ orderItem.quantity }} ({{ fmt(orderItem.subtotal) }})
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <select
                    :value="status"
                    class="form-select form-select-sm rounded-pill px-3 fw-semibold"
                    style="width: auto;"
                    @change="updateStatus($event.target.value)"
                >
                    <option value="nouvelle">Nouvelle</option>
                    <option value="confirmee">Confirmée</option>
                    <option value="expediee">En route / Expédiée</option>
                    <option value="livree">Livrée</option>
                    <option value="annulee">Annulée</option>
                </select>

                <button
                    type="button"
                    class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm"
                    :disabled="departProcessing"
                    @click="triggerDepart"
                >
                    <span v-if="departProcessing" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="bi bi-truck me-1"></i>Départ (Notifier WhatsApp)
                </button>

                <button
                    v-if="orderItem.tracking_url"
                    type="button"
                    class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                    @click="copyTrackingUrl"
                >
                    <i class="bi me-1" :class="copiedTracking ? 'bi-check2 text-success' : 'bi-link-45deg'"></i>
                    {{ copiedTracking ? 'Lien copié' : 'Copier lien suivi client' }}
                </button>
            </div>
        </div>

        <div class="row g-4 align-items-start">
            <!-- Colonne gauche (7 cols) : Fiche commande + Carte itinéraire OpenStreetMap -->
            <div class="col-lg-7 d-flex flex-column gap-4">
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <h2 class="h6 fw-bold mb-3">
                        <i class="bi bi-receipt text-primary me-2"></i>Informations client &amp; livraison
                    </h2>
                    <div class="row g-3 small">
                        <div class="col-md-6">
                            <div class="text-muted">Article &amp; quantité</div>
                            <div class="fw-bold text-dark fs-6">{{ orderItem.product_title }} &times; {{ orderItem.quantity }}</div>
                            <div class="fw-bold text-primary">{{ fmt(orderItem.subtotal) }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted">Client</div>
                            <div class="fw-bold text-dark">{{ orderItem.order?.buyer_name }}</div>
                            <div class="text-muted">{{ orderItem.order?.buyer_email }}</div>
                            <div class="text-success fw-semibold mt-1">
                                <i class="bi bi-whatsapp me-1"></i>{{ orderItem.order?.dial_code }} {{ orderItem.order?.buyer_whatsapp }}
                            </div>
                        </div>
                        <div class="col-12 pt-3 border-top">
                            <div class="text-muted">Adresse de livraison</div>
                            <div class="fw-semibold text-dark">
                                <i class="bi bi-geo-alt-fill text-primary me-1"></i>{{ orderItem.order?.delivery_address }}
                            </div>
                            <div v-if="orderItem.order?.notes" class="mt-2 p-2 px-3 bg-light rounded-3 border text-muted">
                                <i class="bi bi-sticky me-1"></i>Note du client : {{ orderItem.order.notes }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Carte Itinéraire OpenStreetMap -->
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="fw-bold small text-dark">
                            <i class="bi bi-sign-turn-right-fill text-primary me-2"></i>Itinéraire tracé (Établissement → Client)
                        </span>
                        <a
                            v-if="deliveryCoords"
                            :href="`https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${tenantLocation?.latitude || deliveryCoords.lat}%2C${tenantLocation?.longitude || deliveryCoords.lon}%3B${deliveryCoords.lat}%2C${deliveryCoords.lon}`"
                            target="_blank"
                            rel="noopener"
                            class="btn btn-outline-primary btn-sm rounded-pill px-3"
                        >
                            Ouvrir dans OpenStreetMap <i class="bi bi-box-arrow-up-right ms-1"></i>
                        </a>
                    </div>
                    <div v-if="geocodingFallback" class="p-5 text-center text-muted small">
                        <span class="spinner-border spinner-border-sm me-2"></span>Recherche de la position de livraison...
                    </div>
                    <OsmRouteMap
                        v-else-if="deliveryCoords"
                        :target-lat="deliveryCoords.lat"
                        :target-lon="deliveryCoords.lon"
                        :target-label="`Livraison : ${orderItem.order?.buyer_name || 'Client'}`"
                        :origin-lat="tenantLocation?.latitude ?? null"
                        :origin-lon="tenantLocation?.longitude ?? null"
                        :origin-label="tenantLocation?.name || 'Mon établissement'"
                        height="380px"
                    />
                    <div v-else class="p-5 text-center text-muted small">
                        <i class="bi bi-geo-alt fs-2 d-block mb-2 opacity-50"></i>
                        Position GPS introuvable pour cette adresse. Vérifiez aussi la localisation de votre établissement dans Paramètres.
                    </div>
                </div>
            </div>

            <!-- Colonne droite (5 cols) : Chat en direct Client <-> Vendeur + WhatsApp -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden d-flex flex-column" style="height: 620px;">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold small text-dark">
                                <i class="bi bi-chat-dots-fill text-primary me-2"></i>Discussion en direct avec le client
                            </div>
                            <div class="text-muted" style="font-size: 0.72rem;">Actualisation automatique toutes les 5s</div>
                        </div>
                        <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">En direct</span>
                    </div>

                    <div class="card-body p-3 bg-light flex-grow-1 overflow-auto d-flex flex-column gap-2">
                        <div v-if="!chatMessages.length" class="text-center text-muted small my-auto py-4">
                            <i class="bi bi-chat-square-text fs-2 d-block mb-2 opacity-50"></i>
                            Aucun message échangé pour le moment.<br />
                            Cliquez sur <strong>Départ</strong> ou écrivez ci-dessous pour démarrer la conversation avec le client.
                        </div>
                        <div
                            v-for="m in chatMessages"
                            :key="m.id"
                            class="d-flex"
                            :class="m.sender_type === 'seller' ? 'justify-content-end' : 'justify-content-start'"
                        >
                            <div
                                class="p-2 px-3 rounded-3 small shadow-sm"
                                style="max-width: 85%;"
                                :class="m.sender_type === 'seller' ? 'bg-primary text-white' : 'bg-white border text-dark'"
                            >
                                <div class="fw-bold mb-1" style="font-size: 0.7rem; opacity: 0.85;">
                                    {{ m.sender_name }} • {{ m.created_at }}
                                </div>
                                <div style="white-space: pre-wrap;">{{ m.body }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="card-footer bg-white p-3 border-top">
                        <form @submit.prevent="sendSellerMessage">
                            <div class="input-group mb-2">
                                <input
                                    v-model="chatBody"
                                    type="text"
                                    class="form-control"
                                    placeholder="Écrire un message au client..."
                                    :disabled="chatSending"
                                />
                                <button type="submit" class="btn btn-primary px-3" :disabled="chatSending || !chatBody.trim()">
                                    <span v-if="chatSending" class="spinner-border spinner-border-sm"></span>
                                    <i v-else class="bi bi-send-fill"></i>
                                </button>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input id="chkPageSendWhatsapp" v-model="chatSendWhatsapp" class="form-check-input" type="checkbox" />
                                <label class="form-check-label small text-muted" for="chkPageSendWhatsapp">
                                    <i class="bi bi-whatsapp text-success me-1"></i>Envoyer aussi ce message sur WhatsApp
                                </label>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
