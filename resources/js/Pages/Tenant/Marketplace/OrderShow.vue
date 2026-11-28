<script setup>
import OsmRouteMap from '@/Components/OsmRouteMap.vue';
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { useConfirm } from '@/composables/useConfirm';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    orderItem: { type: Object, required: true },
    tenantLocation: { type: Object, default: () => ({}) },
});

const { askConfirm } = useConfirm();

const status = ref(props.orderItem.seller_status);
watch(() => props.orderItem.seller_status, (val) => {
    status.value = val;
});

const isDelivered = computed(() => status.value === 'livree');
const isCancelled = computed(() => status.value === 'annulee');
const isLocked = computed(() => isDelivered.value || isCancelled.value);

const deliveryCoords = ref(null);
const geocodingFallback = ref(false);
const actionProcessing = ref(false);
const departProcessing = ref(false);
const copiedTracking = ref(false);

const chatMessages = ref([...(props.orderItem.messages || [])]);
const chatBody = ref('');
const chatSendWhatsapp = ref(true);
const chatSending = ref(false);
let chatPollTimer = null;

const statusSteps = [
    { key: 'nouvelle', label: 'Nouvelle', icon: 'bi-clock-history' },
    { key: 'confirmee', label: 'Confirmée', icon: 'bi-check-circle' },
    { key: 'expediee', label: 'En livraison', icon: 'bi-truck' },
    { key: 'livree', label: 'Livrée & Vendue', icon: 'bi-patch-check-fill' },
];

const currentStepIndex = computed(() => {
    const idx = statusSteps.findIndex((s) => s.key === status.value);
    return idx >= 0 ? idx : -1;
});

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function submitStatusChange(nextStatus) {
    if (isLocked.value || actionProcessing.value) return;
    actionProcessing.value = true;
    router.patch(`/marketplace-gestion/commandes/${props.orderItem.id}/statut`, { seller_status: nextStatus }, {
        preserveScroll: true,
        onSuccess: () => {
            status.value = nextStatus;
            fetchMessages();
        },
        onFinish: () => {
            actionProcessing.value = false;
        },
    });
}

function confirmOrder() {
    submitStatusChange('confirmee');
}

function requestMarkDelivered() {
    askConfirm({
        title: 'Confirmer la livraison & finaliser la vente',
        message: `Marquer la commande ${props.orderItem.order?.order_number} comme livrée et vendue ? Un message WhatsApp de remerciement sera envoyé au client, la vente (${fmt(props.orderItem.subtotal)}) sera ajoutée aux statistiques de la boutique et la commande sera définitivement verrouillée.`,
        confirmLabel: 'Oui, marquer comme livrée',
        confirmClass: 'btn-success',
        onConfirm: () => submitStatusChange('livree'),
    });
}

function requestCancelOrder() {
    askConfirm({
        title: 'Annuler cette commande',
        message: `Voulez-vous vraiment annuler la commande ${props.orderItem.order?.order_number} ? La quantité commandée (+${props.orderItem.quantity} unité(s)) sera automatiquement remise en stock sur le produit « ${props.orderItem.product_title} » et la commande sera définitivement clôturée.`,
        confirmLabel: 'Oui, annuler et restaurer le stock',
        confirmClass: 'btn-danger',
        onConfirm: () => submitStatusChange('annulee'),
    });
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
    if (isLocked.value || departProcessing.value) return;
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
    if (isLocked.value || !chatBody.value.trim() || chatSending.value) return;
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
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
                <!-- Actions disponibles uniquement si la commande n'est ni livrée ni annulée -->
                <template v-if="!isLocked">
                    <button
                        v-if="status === 'nouvelle'"
                        type="button"
                        class="btn btn-primary btn-sm rounded-pill px-3 fw-bold shadow-sm"
                        :disabled="actionProcessing"
                        @click="confirmOrder"
                    >
                        <span v-if="actionProcessing" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="bi bi-check-circle me-1"></i>Confirmer la commande
                    </button>

                    <button
                        v-if="status === 'nouvelle' || status === 'confirmee'"
                        type="button"
                        class="btn btn-info text-white btn-sm rounded-pill px-3 fw-bold shadow-sm"
                        :disabled="departProcessing"
                        @click="triggerDepart"
                    >
                        <span v-if="departProcessing" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="bi bi-truck me-1"></i>Départ (Notifier WhatsApp)
                    </button>

                    <button
                        type="button"
                        class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm"
                        :disabled="actionProcessing"
                        @click="requestMarkDelivered"
                    >
                        <i class="bi bi-patch-check-fill me-1"></i>Marquer comme livrée
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold"
                        :disabled="actionProcessing"
                        @click="requestCancelOrder"
                    >
                        <i class="bi bi-x-circle me-1"></i>Annuler
                    </button>
                </template>

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

        <!-- Bannière verrouillée si Livrée ou Annulée -->
        <div v-if="isDelivered" class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-patch-check-fill fs-3"></i>
            <div>
                <div class="fw-bold">Commande livrée &amp; marquée comme vendue (verrouillée)</div>
                <div class="small">
                    Le client a reçu son message de remerciement sur WhatsApp, la vente ({{ fmt(orderItem.subtotal) }}) est comptabilisée dans les statistiques de votre boutique et aucune modification n'est plus possible.
                </div>
            </div>
        </div>

        <div v-else-if="isCancelled" class="alert alert-danger border-0 shadow-sm rounded-4 d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-x-octagon-fill fs-3"></i>
            <div>
                <div class="fw-bold">Commande annulée (verrouillée)</div>
                <div class="small">
                    Cette commande a été annulée et la quantité commandée (+{{ orderItem.quantity }} unité(s)) a été remise en stock sur le produit. Aucune modification n'est plus possible.
                </div>
            </div>
        </div>

        <!-- Barre de progression du statut -->
        <div v-else class="card border-0 shadow-sm rounded-4 p-3 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div
                    v-for="(step, idx) in statusSteps"
                    :key="step.key"
                    class="d-flex align-items-center gap-2 small fw-semibold"
                    :class="idx <= currentStepIndex ? 'text-primary' : 'text-muted opacity-50'"
                >
                    <span
                        class="rounded-circle d-inline-flex align-items-center justify-content-center"
                        style="width: 28px; height: 28px;"
                        :class="idx <= currentStepIndex ? 'bg-primary text-white' : 'bg-light border text-muted'"
                    >
                        <i class="bi" :class="step.icon"></i>
                    </span>
                    <span>{{ step.label }}</span>
                </div>
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
                        <span class="badge rounded-pill px-2 py-1" :class="isLocked ? 'bg-secondary-subtle text-secondary' : 'bg-success-subtle text-success'">
                            {{ isLocked ? 'Clôturée' : 'En direct' }}
                        </span>
                    </div>

                    <div class="card-body p-3 bg-light flex-grow-1 overflow-auto d-flex flex-column gap-2">
                        <div v-if="!chatMessages.length" class="text-center text-muted small my-auto py-4">
                            <i class="bi bi-chat-square-text fs-2 d-block mb-2 opacity-50"></i>
                            Aucun message échangé pour le moment.<br />
                            Confirmez la commande, cliquez sur <strong>Départ</strong> ou écrivez ci-dessous pour discuter avec le client.
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
                        <div v-if="isLocked" class="text-center small text-muted py-2">
                            <i class="bi bi-lock-fill me-1"></i>
                            Commande {{ isDelivered ? 'livrée & vendue' : 'annulée' }} — la discussion est en lecture seule.
                        </div>
                        <form v-else @submit.prevent="sendSellerMessage">
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
