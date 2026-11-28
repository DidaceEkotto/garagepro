<script setup>
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
import StaticModal from '@/Components/StaticModal.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    orderItems: Object,
    tenantLocation: { type: Object, default: () => ({}) },
});

const selectedItem = ref(null);
const deliveryCoords = ref(null);
const geocodingFallback = ref(false);
const departProcessing = ref(false);
const copiedTracking = ref(false);

// Chat en direct Vendeur <-> Client dans le modal Détails
const chatMessages = ref([]);
const chatBody = ref('');
const chatSendWhatsapp = ref(true);
const chatSending = ref(false);
let chatPollTimer = null;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function updateStatus(id, status) {
    router.patch(`/marketplace-gestion/commandes/${id}/statut`, { seller_status: status }, { preserveScroll: true });
}

async function resolveDeliveryCoords(order) {
    deliveryCoords.value = null;
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

async function fetchOrderMessages(itemId) {
    try {
        const resp = await fetch(`/marketplace-gestion/commandes/${itemId}/messages`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!resp.ok) return;
        const data = await resp.json();
        if (data?.ok) {
            chatMessages.value = data.messages || [];
        }
    } catch {
        // silencieux
    }
}

function stopChatPolling() {
    if (chatPollTimer) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }
}

async function openOrderDetails(item) {
    selectedItem.value = item;
    chatMessages.value = (item.messages || []).map((m) => ({
        id: m.id,
        sender_type: m.sender_type,
        sender_name: m.sender_name,
        body: m.body,
        created_at: m.created_at ? new Date(m.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '',
    }));
    chatBody.value = '';
    copiedTracking.value = false;

    const modalEl = document.getElementById('modalOrderDetails');
    window.bootstrap?.Modal.getOrCreateInstance(modalEl)?.show();
    modalEl?.addEventListener('hidden.bs.modal', stopChatPolling, { once: true });

    resolveDeliveryCoords(item.order);
    await fetchOrderMessages(item.id);
    stopChatPolling();
    chatPollTimer = setInterval(() => {
        if (selectedItem.value && !document.hidden) {
            fetchOrderMessages(selectedItem.value.id);
        }
    }, 5000);
}

function triggerDepart() {
    if (!selectedItem.value || departProcessing.value) return;
    departProcessing.value = true;
    const itemId = selectedItem.value.id;
    router.post(`/marketplace-gestion/commandes/${itemId}/depart`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedItem.value) {
                selectedItem.value.seller_status = 'expediee';
            }
            fetchOrderMessages(itemId);
        },
        onFinish: () => {
            departProcessing.value = false;
        },
    });
}

async function sendSellerMessage() {
    if (!selectedItem.value || !chatBody.value.trim() || chatSending.value) return;
    chatSending.value = true;
    try {
        const resp = await fetch(`/marketplace-gestion/commandes/${selectedItem.value.id}/messages`, {
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
    if (!selectedItem.value?.tracking_url) return;
    try {
        await navigator.clipboard.writeText(selectedItem.value.tracking_url);
        copiedTracking.value = true;
        setTimeout(() => { copiedTracking.value = false; }, 2000);
    } catch {}
}

onBeforeUnmount(stopChatPolling);

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Marketplace — Commandes reçues" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Commandes reçues du Marketplace</h1>
            <p class="text-muted small mb-0">Suivez les commandes, visualisez l'itinéraire de livraison, signalez votre départ sur WhatsApp et discutez avec vos clients</p>
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
                                    style="min-width: 120px;"
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
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm rounded-pill px-3 position-relative"
                                    @click="openOrderDetails(item)"
                                >
                                    <i class="bi bi-eye me-1"></i>Détails
                                    <span
                                        v-if="item.unread_buyer_messages > 0"
                                        class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                        style="font-size: 0.65rem;"
                                    >
                                        {{ item.unread_buyer_messages }}
                                    </span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Static Modal Détails de la commande + Itinéraire OSM + Départ WhatsApp + Chat Client -->
        <StaticModal
            id="modalOrderDetails"
            :title="selectedItem ? `Commande ${selectedItem.order?.order_number} — ${selectedItem.order?.buyer_name}` : 'Détails de la commande'"
            size="modal-xl"
        >
            <div v-if="selectedItem" class="row g-4">
                <!-- Colonne gauche : Infos + Itinéraire tracé + Bouton Départ -->
                <div class="col-lg-7 d-flex flex-column gap-3">
                    <div class="p-3 rounded-4 bg-light border">
                        <div class="row g-2 small">
                            <div class="col-md-6">
                                <div class="text-muted">Article commandé</div>
                                <div class="fw-bold text-dark">{{ selectedItem.product_title }} &times; {{ selectedItem.quantity }}</div>
                                <div class="fw-bold text-primary">{{ fmt(selectedItem.subtotal) }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted">Client &amp; WhatsApp</div>
                                <div class="fw-bold text-dark">{{ selectedItem.order?.buyer_name }}</div>
                                <div class="text-success fw-semibold">
                                    <i class="bi bi-whatsapp me-1"></i>{{ selectedItem.order?.dial_code }} {{ selectedItem.order?.buyer_whatsapp }}
                                </div>
                            </div>
                            <div class="col-12 pt-2 border-top">
                                <div class="text-muted">Adresse de livraison</div>
                                <div class="fw-semibold text-dark">
                                    <i class="bi bi-geo-alt-fill text-primary me-1"></i>{{ selectedItem.order?.delivery_address }}
                                </div>
                                <div v-if="selectedItem.order?.notes" class="text-muted mt-1">
                                    <i class="bi bi-sticky me-1"></i>Note client : {{ selectedItem.order.notes }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bouton Départ WhatsApp + Lien suivi client -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 rounded-4 border bg-white shadow-sm">
                        <div>
                            <div class="fw-bold small text-dark">Livraison en cours ?</div>
                            <div class="text-muted" style="font-size: 0.75rem;">
                                Cliquez sur <strong>Départ</strong> pour envoyer automatiquement un WhatsApp au client et lui signaler que vous êtes en chemin.
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
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
                                v-if="selectedItem.tracking_url"
                                type="button"
                                class="btn btn-outline-secondary btn-sm rounded-pill px-3"
                                @click="copyTrackingUrl"
                            >
                                <i class="bi me-1" :class="copiedTracking ? 'bi-check2 text-success' : 'bi-link-45deg'"></i>
                                {{ copiedTracking ? 'Lien copié' : 'Lien suivi client' }}
                            </button>
                        </div>
                    </div>

                    <!-- Carte Itinéraire OpenStreetMap (Établissement -> Client) -->
                    <div class="card border rounded-4 overflow-hidden shadow-sm">
                        <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold text-dark">
                                <i class="bi bi-sign-turn-right-fill text-primary me-1"></i>Itinéraire de livraison (Établissement → Client)
                            </span>
                            <a
                                v-if="deliveryCoords"
                                :href="`https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=${tenantLocation?.latitude || deliveryCoords.lat}%2C${tenantLocation?.longitude || deliveryCoords.lon}%3B${deliveryCoords.lat}%2C${deliveryCoords.lon}`"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-outline-primary btn-sm py-0 px-2 rounded-pill"
                                style="font-size: 0.74rem;"
                            >
                                Ouvrir OSM <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        </div>
                        <div v-if="geocodingFallback" class="p-4 text-center text-muted small">
                            <span class="spinner-border spinner-border-sm me-2"></span>Localisation de l'adresse de livraison...
                        </div>
                        <OsmRouteMap
                            v-else-if="deliveryCoords"
                            :target-lat="deliveryCoords.lat"
                            :target-lon="deliveryCoords.lon"
                            :target-label="`Livraison : ${selectedItem.order?.buyer_name || 'Client'}`"
                            :origin-lat="tenantLocation?.latitude ?? null"
                            :origin-lon="tenantLocation?.longitude ?? null"
                            :origin-label="tenantLocation?.name || 'Mon établissement'"
                            height="280px"
                        />
                        <div v-else class="p-4 text-center text-muted small">
                            <i class="bi bi-geo-alt fs-4 d-block mb-1 opacity-50"></i>
                            Coordonnées GPS non disponibles pour cette adresse. Configurez aussi la localisation de votre établissement dans Paramètres.
                        </div>
                    </div>
                </div>

                <!-- Colonne droite : Chat en direct Vendeur <-> Client + Envoi WhatsApp -->
                <div class="col-lg-5 d-flex flex-column">
                    <div class="card border rounded-4 shadow-sm flex-grow-1 d-flex flex-column overflow-hidden" style="min-height: 430px;">
                        <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                            <span class="small fw-bold text-dark">
                                <i class="bi bi-chat-dots-fill text-primary me-1"></i>Discussion directe avec le client
                            </span>
                            <span class="badge bg-success-subtle text-success rounded-pill" style="font-size: 0.68rem;">En direct</span>
                        </div>

                        <div class="card-body p-3 bg-light flex-grow-1 overflow-auto d-flex flex-column gap-2" style="max-height: 320px;">
                            <div v-if="!chatMessages.length" class="text-center text-muted small my-auto py-4">
                                <i class="bi bi-chat-square-text fs-3 d-block mb-1 opacity-50"></i>
                                Aucun message échangé pour le moment.<br />
                                Envoyez un message ou cliquez sur <strong>Départ</strong> pour prévenir le client.
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
                                <div class="input-group input-group-sm mb-2">
                                    <input
                                        v-model="chatBody"
                                        type="text"
                                        class="form-control"
                                        placeholder="Écrire au client..."
                                        :disabled="chatSending"
                                    />
                                    <button type="submit" class="btn btn-primary px-3" :disabled="chatSending || !chatBody.trim()">
                                        <span v-if="chatSending" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="bi bi-send-fill"></i>
                                    </button>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input id="chkOrderSendWhatsapp" v-model="chatSendWhatsapp" class="form-check-input" type="checkbox" />
                                    <label class="form-check-label small text-muted" for="chkOrderSendWhatsapp" style="font-size: 0.74rem;">
                                        <i class="bi bi-whatsapp text-success me-1"></i>Notifier aussi le client sur WhatsApp
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </StaticModal>
    </TenantLayout>
</template>
