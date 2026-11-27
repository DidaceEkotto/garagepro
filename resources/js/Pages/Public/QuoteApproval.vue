<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    quote: { type: Object, required: true },
    garage: { type: Object, default: null },
    customer: { type: Object, default: null },
    vehicle: { type: Object, default: null },
    trackingUrl: { type: String, default: null },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);
const siteName = computed(() => page.props.app?.site?.name || 'MboaCar');
const siteLogo = computed(() => page.props.app?.site?.logo_url || '/assets/images/logo.png');

const decisionMode = ref('accept'); // 'accept' | 'reject'
const canvasRef = ref(null);
const isDrawing = ref(false);
const hasSignature = ref(false);

// Validation partielle : toutes les lignes cochées par défaut ; les lignes "urgent" restent obligatoires
const selectedItemIds = ref(
    (props.quote.items || [])
        .filter((it) => it.is_accepted !== false || it.urgency === 'urgent')
        .map((it) => it.id)
);

function isItemSelected(id) {
    return selectedItemIds.value.includes(id);
}

function toggleItemSelection(item) {
    if (!props.quote.can_respond || item.urgency === 'urgent') return;
    if (selectedItemIds.value.includes(item.id)) {
        selectedItemIds.value = selectedItemIds.value.filter((x) => x !== item.id);
    } else {
        selectedItemIds.value = [...selectedItemIds.value, item.id];
    }
}

const liveTotals = computed(() => {
    const items = props.quote.items || [];
    const accepted = props.quote.can_respond
        ? items.filter((it) => it.urgency === 'urgent' || selectedItemIds.value.includes(it.id))
        : items.filter((it) => it.is_accepted !== false);

    const subtotal = accepted.reduce((sum, it) => sum + Number(it.total_price || 0), 0);
    const discount = Number(props.quote.discount_amount || 0);
    const netHt = Math.max(0, subtotal - discount);
    const vat = Math.round((netHt * Number(props.quote.vat_rate || 0)) / 100);
    const ttc = netHt + vat;
    return {
        subtotal,
        discount,
        vat,
        ttc,
        acceptedCount: accepted.length,
        totalCount: items.length,
        isPartial: accepted.length < items.length,
    };
});

const responseForm = useForm({
    decision: 'accept',
    signed_by_name: props.customer?.full_name || '',
    signature_data: '',
    rejection_reason: '',
    accepted_item_ids: [],
});

function getPointerPos(event) {
    const canvas = canvasRef.value;
    if (!canvas) return { x: 0, y: 0 };
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (event.clientX - rect.left) * scaleX,
        y: (event.clientY - rect.top) * scaleY,
    };
}

function startDrawing(event) {
    const canvas = canvasRef.value;
    if (!canvas) return;
    canvas.setPointerCapture?.(event.pointerId);
    const ctx = canvas.getContext('2d');
    const pos = getPointerPos(event);
    ctx.strokeStyle = '#0f172a';
    ctx.lineWidth = 2.6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.beginPath();
    ctx.moveTo(pos.x, pos.y);
    isDrawing.value = true;
}

function draw(event) {
    if (!isDrawing.value || !canvasRef.value) return;
    const ctx = canvasRef.value.getContext('2d');
    const pos = getPointerPos(event);
    ctx.lineTo(pos.x, pos.y);
    ctx.stroke();
    hasSignature.value = true;
}

function stopDrawing() {
    isDrawing.value = false;
}

function clearSignature() {
    const canvas = canvasRef.value;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasSignature.value = false;
    responseForm.signature_data = '';
}

onMounted(() => {
    if (canvasRef.value) {
        const ctx = canvasRef.value.getContext('2d');
        ctx.clearRect(0, 0, canvasRef.value.width, canvasRef.value.height);
    }
});

function submitAccept() {
    responseForm.decision = 'accept';
    responseForm.rejection_reason = '';
    responseForm.accepted_item_ids = [...selectedItemIds.value];
    if (hasSignature.value && canvasRef.value) {
        responseForm.signature_data = canvasRef.value.toDataURL('image/png');
    } else {
        responseForm.signature_data = '';
    }
    responseForm.post(`/devis-public/${props.quote.public_token}/repondre`, {
        preserveScroll: true,
    });
}

function submitReject() {
    responseForm.decision = 'reject';
    responseForm.signature_data = '';
    responseForm.post(`/devis-public/${props.quote.public_token}/repondre`, {
        preserveScroll: true,
    });
}

function formatMoney(v) {
    return `${Number(v || 0).toLocaleString('fr-FR')} FCFA`;
}

function statusMeta(status) {
    if (status === 'accepte' || status === 'accepte_partiel') {
        return { label: 'Validé & Signé', cls: 'bg-success text-white', icon: 'bi-check-circle-fill' };
    }
    if (status === 'refuse') {
        return { label: 'Refusé — Réajustement demandé', cls: 'bg-danger text-white', icon: 'bi-x-circle-fill' };
    }
    if (status === 'expire') {
        return { label: 'Expiré', cls: 'bg-secondary text-white', icon: 'bi-clock-history' };
    }
    return { label: 'En attente de votre validation', cls: 'bg-warning text-dark', icon: 'bi-hourglass-split' };
}

function urgencyBadge(u) {
    if (u === 'urgent') return { label: 'Urgent', cls: 'bg-danger-subtle text-danger' };
    if (u === 'recommande') return { label: 'Recommandé', cls: 'bg-warning-subtle text-warning-emphasis' };
    return { label: 'Optionnel', cls: 'bg-light text-muted border' };
}
</script>

<template>
    <Head :title="`Devis ${quote.quote_number} (v${quote.version}) — Validation`" />

    <div class="qa-shell min-vh-100 d-flex flex-column">
        <!-- Topbar -->
        <header class="qa-topbar">
            <div class="container qa-container d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <img :src="siteLogo" :alt="siteName" class="qa-logo" />
                    <div class="lh-1">
                        <div class="fw-bold text-white">{{ garage?.name || siteName }}</div>
                        <div class="small text-white-50" style="font-size: 0.74rem;">Validation de devis en ligne sécurisée</div>
                    </div>
                </div>
                <Link href="/mon-espace-client" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="bi bi-person-badge me-1"></i>Mon Espace Client
                </Link>
            </div>
        </header>

        <main class="flex-grow-1 py-4 py-md-5">
            <div class="container qa-container">
                <div v-if="flashSuccess" class="alert alert-success border-0 shadow-sm rounded-4 mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i>{{ flashSuccess }}
                </div>
                <div v-if="flashError" class="alert alert-danger border-0 shadow-sm rounded-4 mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ flashError }}
                </div>

                <!-- En-tête du devis -->
                <div class="qa-hero mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge rounded-pill px-3 py-2 fw-bold" :class="statusMeta(quote.status).cls">
                                    <i class="bi me-1" :class="statusMeta(quote.status).icon"></i>
                                    {{ statusMeta(quote.status).label }}
                                </span>
                                <span class="badge bg-white bg-opacity-10 text-white border border-white border-opacity-25 rounded-pill px-3 py-2">
                                    Version v{{ quote.version }}
                                </span>
                            </div>
                            <h1 class="h3 fw-bold text-white mb-1">Devis {{ quote.quote_number }}</h1>
                            <div class="small text-white-50 d-flex flex-wrap gap-3">
                                <span v-if="customer"><i class="bi bi-person me-1"></i>{{ customer.full_name }}</span>
                                <span v-if="vehicle">
                                    <i class="bi bi-car-front me-1"></i>
                                    {{ vehicle.license_plate }} — {{ vehicle.brand }} {{ vehicle.model }}
                                </span>
                                <span v-if="quote.valid_until"><i class="bi bi-calendar-event me-1"></i>Valable jusqu'au {{ quote.valid_until }}</span>
                            </div>
                        </div>

                        <div class="text-md-end">
                            <div class="small text-white-50 text-uppercase">
                                {{ liveTotals.isPartial && quote.can_respond ? 'Total sélectionné TTC' : 'Total TTC' }}
                            </div>
                            <div class="display-6 fw-bold text-white lh-1">{{ formatMoney(liveTotals.ttc) }}</div>
                            <div v-if="quote.deposit_required > 0" class="small mt-1" style="color: #93c5fd;">
                                Acompte demandé : <strong>{{ formatMoney(quote.deposit_required) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Détail des prestations -->
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                    <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="h6 fw-bold mb-0"><i class="bi bi-list-check text-primary me-2"></i>Détail des prestations &amp; pièces</h2>
                            <div v-if="quote.can_respond" class="text-muted" style="font-size: 0.76rem;">
                                Vous pouvez décocher les lignes <strong>Recommandées</strong> ou <strong>Optionnelles</strong> ; les lignes <strong>Urgentes</strong> restent incluses.
                            </div>
                        </div>
                        <span class="small text-muted">{{ liveTotals.acceptedCount }} / {{ liveTotals.totalCount }} retenue(s)</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="width: 46px;">Choix</th>
                                    <th>Désignation</th>
                                    <th>Type / Priorité</th>
                                    <th class="text-center">Qté</th>
                                    <th class="text-end">P.U.</th>
                                    <th class="text-end pe-4">Total HT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in quote.items"
                                    :key="item.id"
                                    :class="
                                        (quote.can_respond ? !isItemSelected(item.id) : item.is_accepted === false)
                                            ? 'opacity-50 text-decoration-line-through bg-light'
                                            : ''
                                    "
                                >
                                    <td class="ps-4">
                                        <input
                                            v-if="quote.can_respond"
                                            type="checkbox"
                                            class="form-check-input"
                                            :checked="item.urgency === 'urgent' || isItemSelected(item.id)"
                                            :disabled="item.urgency === 'urgent'"
                                            :title="item.urgency === 'urgent' ? 'Prestation urgente obligatoire' : 'Inclure ou retirer cette ligne'"
                                            @change="toggleItemSelection(item)"
                                        />
                                        <i
                                            v-else
                                            class="bi"
                                            :class="item.is_accepted !== false ? 'bi-check-circle-fill text-success' : 'bi-dash-circle text-muted'"
                                        ></i>
                                    </td>
                                    <td class="fw-semibold text-dark">{{ item.description }}</td>
                                    <td>
                                        <span class="badge bg-light text-dark border me-1">
                                            {{ item.type === 'piece' ? 'Pièce' : 'Main d\'œuvre' }}
                                        </span>
                                        <span class="badge" :class="urgencyBadge(item.urgency).cls">
                                            {{ urgencyBadge(item.urgency).label }}
                                        </span>
                                    </td>
                                    <td class="text-center">{{ item.quantity }}</td>
                                    <td class="text-end">{{ formatMoney(item.unit_price) }}</td>
                                    <td class="text-end pe-4 fw-bold">{{ formatMoney(item.total_price) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Récapitulatif financier -->
                    <div class="bg-light p-4 border-top">
                        <div class="row justify-content-end">
                            <div class="col-md-5 vstack gap-1 small">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Sous-total HT</span>
                                    <span class="fw-semibold">{{ formatMoney(liveTotals.subtotal) }}</span>
                                </div>
                                <div v-if="liveTotals.discount > 0" class="d-flex justify-content-between text-success">
                                    <span>Remise accordée</span>
                                    <span class="fw-semibold">- {{ formatMoney(liveTotals.discount) }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">TVA ({{ quote.vat_rate }}%)</span>
                                    <span class="fw-semibold">{{ formatMoney(liveTotals.vat) }}</span>
                                </div>
                                <hr class="my-2" />
                                <div class="d-flex justify-content-between fs-6 fw-bold text-dark">
                                    <span>Net à payer TTC</span>
                                    <span class="text-primary">{{ formatMoney(liveTotals.ttc) }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="quote.notes" class="mt-3 pt-3 border-top small text-muted">
                            <strong class="text-dark">Note du garage :</strong> {{ quote.notes }}
                        </div>
                    </div>
                </div>

                <!-- ÉTAT 1 : DÉJÀ VALIDÉ & SIGNÉ -->
                <div v-if="quote.status === 'accepte' || quote.status === 'accepte_partiel'" class="card border-0 shadow-sm rounded-4 border-start border-4 border-success">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <h3 class="h5 fw-bold text-success mb-1">
                                    <i class="bi bi-patch-check-fill me-2"></i>Devis validé et signé électroniquement
                                </h3>
                                <p class="small text-muted mb-2">
                                    Signé par <strong>{{ quote.signed_by_name || customer?.full_name }}</strong>
                                    <span v-if="quote.signed_at"> le {{ quote.signed_at }}</span>.
                                </p>
                                <a v-if="trackingUrl" :href="trackingUrl" class="btn btn-primary btn-sm rounded-pill px-3">
                                    <i class="bi bi-broadcast me-1"></i>Suivre la réparation en direct
                                </a>
                            </div>
                            <div v-if="quote.signature_url" class="bg-light border rounded-3 p-2 text-center">
                                <img :src="quote.signature_url" alt="Signature client" style="max-height: 84px; max-width: 240px;" />
                                <div class="text-muted" style="font-size: 0.7rem;">Signature manuscrite enregistrée</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ÉTAT 2 : REFUSÉ (EN ATTENTE DE RÉAJUSTEMENT) -->
                <div v-else-if="quote.status === 'refuse'" class="card border-0 shadow-sm rounded-4 border-start border-4 border-danger">
                    <div class="card-body p-4">
                        <h3 class="h5 fw-bold text-danger mb-2">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Devis refusé — En attente de réajustement
                        </h3>
                        <p class="small text-muted mb-2">
                            Vous avez refusé cette version (v{{ quote.version }})<span v-if="quote.rejected_at"> le {{ quote.rejected_at }}</span>.
                            Le garage <strong>{{ garage?.name }}</strong> a été notifié et pourra vous envoyer une version réajustée sur WhatsApp.
                        </p>
                        <div v-if="quote.rejection_reason" class="bg-light rounded-3 p-3 small">
                            <strong>Votre motif / demande :</strong> « {{ quote.rejection_reason }} »
                        </div>
                    </div>
                </div>

                <!-- ÉTAT 3 : EN ATTENTE DE DÉCISION CLIENT (SIGNER OU REFUSER) -->
                <div v-else-if="quote.can_respond" class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white p-3 border-bottom">
                        <div class="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="btn rounded-pill px-4 fw-bold"
                                :class="decisionMode === 'accept' ? 'btn-success' : 'btn-outline-secondary'"
                                @click="decisionMode = 'accept'"
                            >
                                <i class="bi bi-pen-fill me-2"></i>Valider &amp; Signer le devis
                            </button>
                            <button
                                type="button"
                                class="btn rounded-pill px-4 fw-bold"
                                :class="decisionMode === 'reject' ? 'btn-danger' : 'btn-outline-secondary'"
                                @click="decisionMode = 'reject'"
                            >
                                <i class="bi bi-x-circle me-2"></i>Refuser / Demander un réajustement
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <!-- Mode Accepter + Signature tactile -->
                        <form v-if="decisionMode === 'accept'" @submit.prevent="submitAccept">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Votre nom complet (Signataire)</label>
                                <input
                                    v-model="responseForm.signed_by_name"
                                    type="text"
                                    class="form-control"
                                    placeholder="Nom et prénom"
                                    required
                                />
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0">
                                        Signature manuscrite (au doigt ou à la souris)
                                    </label>
                                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0" @click="clearSignature">
                                        <i class="bi bi-eraser me-1"></i>Effacer
                                    </button>
                                </div>
                                <div class="qa-signature-box">
                                    <canvas
                                        ref="canvasRef"
                                        width="640"
                                        height="180"
                                        class="qa-signature-canvas"
                                        @pointerdown="startDrawing"
                                        @pointermove="draw"
                                        @pointerup="stopDrawing"
                                        @pointerleave="stopDrawing"
                                    ></canvas>
                                    <span v-if="!hasSignature" class="qa-signature-placeholder">
                                        Signez ici pour confirmer « Bon pour accord »
                                    </span>
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-success btn-lg w-100 rounded-3 fw-bold shadow-sm"
                                :disabled="responseForm.processing"
                            >
                                <span v-if="responseForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                <i v-else class="bi bi-check2-circle me-2"></i>
                                Confirmer « Bon pour accord »{{ liveTotals.isPartial ? ' (validation partielle)' : '' }} — {{ formatMoney(liveTotals.ttc) }}
                            </button>
                        </form>

                        <!-- Mode Refuser + Motif -->
                        <form v-else @submit.prevent="submitReject">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">
                                    Pourquoi refusez-vous ou que souhaitez-vous réajuster ? <span class="text-danger">*</span>
                                </label>
                                <textarea
                                    v-model="responseForm.rejection_reason"
                                    rows="3"
                                    class="form-control"
                                    placeholder="Ex : Le tarif de certaines pièces dépasse mon budget, pouvez-vous proposer une pièce adaptable ou retirer la ligne optionnelle ?"
                                    required
                                ></textarea>
                                <div v-if="responseForm.errors.rejection_reason" class="text-danger small mt-1">
                                    {{ responseForm.errors.rejection_reason }}
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-danger btn-lg w-100 rounded-3 fw-bold shadow-sm"
                                :disabled="responseForm.processing"
                            >
                                <span v-if="responseForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                <i v-else class="bi bi-send me-2"></i>
                                Refuser et demander un réajustement au garage
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.qa-shell {
    background: #f1f5f9;
}

.qa-container {
    max-width: 860px;
}

.qa-topbar {
    background: #0f172a;
    padding: 0.85rem 0;
}

.qa-logo {
    height: 36px;
    width: auto;
    border-radius: 8px;
    background: #ffffff;
    padding: 2px 6px;
    object-fit: contain;
}

.qa-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border-radius: 20px;
    padding: 1.75rem;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
}

.qa-signature-box {
    position: relative;
    border: 2px dashed #cbd5e1;
    border-radius: 14px;
    background: #ffffff;
    overflow: hidden;
    touch-action: none;
}

.qa-signature-canvas {
    display: block;
    width: 100%;
    height: 170px;
    cursor: crosshair;
    touch-action: none;
}

.qa-signature-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    color: #94a3b8;
    font-size: 0.88rem;
}
</style>
