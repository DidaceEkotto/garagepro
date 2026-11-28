<script setup>
import LocationAutocomplete from '@/Components/LocationAutocomplete.vue';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    cartItems: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    countries: { type: Array, default: () => [] },
});

const form = useForm({
    buyer_name: '',
    buyer_email: '',
    country_code: props.countries?.[0]?.code ?? 'CM',
    buyer_whatsapp: '',
    delivery_address: '',
    delivery_latitude: null,
    delivery_longitude: null,
    notes: '',
});

const deliveryGps = ref(null);

function onDeliveryLocationSelected(payload) {
    if (!payload) return;
    form.delivery_address = payload.formatted_location || payload.city || form.delivery_address;
    if (payload.latitude && payload.longitude) {
        form.delivery_latitude = Number(payload.latitude);
        form.delivery_longitude = Number(payload.longitude);
        deliveryGps.value = { lat: form.delivery_latitude, lon: form.delivery_longitude };
    }
}

const selectedDialCode = computed(() => {
    const found = props.countries.find((c) => c.code === form.country_code);
    return found ? found.dial_code : '+237';
});

const groupedBySeller = computed(() => {
    const groups = new Map();
    for (const item of props.cartItems) {
        const key = item.seller_id || 'unknown';
        if (!groups.has(key)) {
            groups.set(key, {
                seller_id: key,
                seller_name: item.seller_name || 'Garage vendeur',
                items: [],
                subtotal: 0,
            });
        }
        const g = groups.get(key);
        g.items.push(item);
        g.subtotal += Number(item.subtotal || 0);
    }
    return Array.from(groups.values());
});

function submitOrder() {
    form.post('/commander');
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Valider ma commande — Marketplace" />
    <MarketplaceLayout>
        <div class="container py-4">
            <div class="mb-4">
                <Link href="/panier" class="text-decoration-none small text-muted">
                    <i class="bi bi-arrow-left me-1"></i>Retour au panier
                </Link>
                <h1 class="h3 fw-bold mt-1 mb-0">Finaliser votre commande</h1>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h2 class="h5 fw-bold mb-3">Vos informations de livraison</h2>
                        <form @submit.prevent="submitOrder">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Nom complet <span class="text-danger">*</span></label>
                                    <input
                                        v-model="form.buyer_name"
                                        type="text"
                                        class="form-control"
                                        :class="{ 'is-invalid': form.errors.buyer_name }"
                                        required
                                    />
                                    <div v-if="form.errors.buyer_name" class="invalid-feedback">{{ form.errors.buyer_name }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Adresse e-mail <span class="text-danger">*</span></label>
                                    <input
                                        v-model="form.buyer_email"
                                        type="email"
                                        class="form-control"
                                        :class="{ 'is-invalid': form.errors.buyer_email }"
                                        required
                                    />
                                    <div v-if="form.errors.buyer_email" class="invalid-feedback">{{ form.errors.buyer_email }}</div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Pays <span class="text-danger">*</span></label>
                                    <select
                                        v-model="form.country_code"
                                        class="form-select"
                                        :class="{ 'is-invalid': form.errors.country_code }"
                                        required
                                    >
                                        <option v-for="c in countries" :key="c.code" :value="c.code">
                                            {{ c.name }} ({{ c.dial_code }})
                                        </option>
                                    </select>
                                    <div v-if="form.errors.country_code" class="invalid-feedback">{{ form.errors.country_code }}</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Téléphone WhatsApp <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text fw-semibold bg-light text-dark user-select-none">
                                            <i class="bi bi-whatsapp text-success me-1"></i>{{ selectedDialCode }}
                                        </span>
                                        <input
                                            v-model="form.buyer_whatsapp"
                                            type="tel"
                                            class="form-control"
                                            :class="{ 'is-invalid': form.errors.buyer_whatsapp }"
                                            placeholder="6XX XXX XXX"
                                            required
                                        />
                                        <div v-if="form.errors.buyer_whatsapp" class="invalid-feedback">{{ form.errors.buyer_whatsapp }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Où souhaitez-vous être livré ? (OpenStreetMap) <span class="text-danger">*</span></label>
                                <LocationAutocomplete
                                    v-model="form.delivery_address"
                                    :country="form.country_code"
                                    :required="true"
                                    placeholder="Ville, quartier, rue ou point de repère..."
                                    @select="onDeliveryLocationSelected"
                                />
                                <div v-if="deliveryGps" class="form-text text-success small">
                                    <i class="bi bi-check-circle-fill me-1"></i>Position OpenStreetMap confirmée ({{ deliveryGps.lat.toFixed(4) }}, {{ deliveryGps.lon.toFixed(4) }})
                                </div>
                                <div v-if="form.errors.delivery_address" class="text-danger small mt-1">{{ form.errors.delivery_address }}</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-semibold">Instructions complémentaires (optionnel)</label>
                                <textarea v-model="form.notes" rows="3" class="form-control"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold" :disabled="form.processing">
                                <i class="bi bi-check-circle me-2"></i>{{ form.processing ? 'Validation en cours...' : 'Valider la commande' }}
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h2 class="h5 fw-bold mb-3">Votre commande</h2>

                        <div v-if="groupedBySeller.length > 1" class="alert alert-info border-0 small py-2 mb-3">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            Votre panier contient des pièces de <strong>{{ groupedBySeller.length }} garages différents</strong>. Chaque garage recevra automatiquement sa propre commande séparée.
                        </div>

                        <div class="vstack gap-3 mb-3">
                            <div v-for="group in groupedBySeller" :key="group.seller_id" class="border rounded-3 p-3 bg-light bg-opacity-50">
                                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                                    <span class="fw-bold small text-primary">
                                        <i class="bi bi-shop me-1"></i>{{ group.seller_name }}
                                    </span>
                                    <span class="small fw-semibold text-dark">{{ fmt(group.subtotal) }}</span>
                                </div>
                                <div class="vstack gap-2">
                                    <div v-for="item in group.items" :key="item.id" class="d-flex justify-content-between align-items-center small">
                                        <div>
                                            <div class="fw-semibold">{{ item.title }}</div>
                                            <div class="text-muted">Qté : {{ item.quantity }}</div>
                                        </div>
                                        <div class="fw-bold">{{ fmt(item.subtotal) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr />
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold">Total à payer</span>
                            <span class="h4 fw-bold text-primary mb-0">{{ fmt(total) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MarketplaceLayout>
</template>
