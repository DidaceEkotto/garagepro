<script setup>
import ConfirmStaticModal from '@/Components/ConfirmStaticModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    order: Object,
    garage: Object,
    quotes: Array,
});

const flashSuccess = computed(() => usePage().props.flash?.success);

const steps = [
    { key: 'en_attente', label: 'Réceptionné', icon: 'bi-clipboard-check' },
    { key: 'diagnostic', label: 'Diagnostic', icon: 'bi-search' },
    { key: 'en_cours', label: 'En réparation', icon: 'bi-wrench-adjustable' },
    { key: 'termine', label: 'Prêt / Terminé', icon: 'bi-check-circle' },
];

const currentStepIndex = computed(() => {
    const s = props.order?.status;
    if (s === 'livre' || s === 'termine') return 3;
    if (s === 'en_cours' || s === 'controle_qualite') return 2;
    if (s === 'diagnostic' || s === 'en_attente_validation') return 1;
    return 0;
});

const { askConfirm } = useConfirm();

function respondQuote(quoteId, decision) {
    const isAccept = decision === 'accept';
    askConfirm({
        title: isAccept ? 'Accepter le devis' : 'Refuser le devis',
        message: isAccept
            ? 'Confirmez-vous l\'acceptation de ce devis ? Le garage pourra démarrer les réparations.'
            : 'Confirmez-vous le refus de ce devis ?',
        confirmLabel: isAccept ? 'Accepter le devis' : 'Refuser',
        confirmClass: isAccept ? 'btn-success' : 'btn-danger',
        onConfirm: () => {
            router.post(`/suivi/${props.order.tracking_token}/devis/${quoteId}/repondre`, { decision }, {
                preserveScroll: true,
            });
        },
    });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0);
}
</script>

<template>
    <Head :title="`Suivi ${order.order_number} — ${garage?.name ?? 'MboaCar'}`" />
    <div class="min-vh-100 bg-light py-4">
        <div class="container" style="max-width: 860px;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <Link href="/" class="text-decoration-none d-inline-flex align-items-center gap-2">
                    <img src="/assets/images/logo.png" alt="MboaCar" class="bg-white rounded-3 px-2 py-1 shadow-sm" style="height: 36px; width: auto; object-fit: contain;" />
                </Link>
                <a v-if="garage?.phone" :href="`tel:${garage.phone}`" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-telephone me-1"></i>Appeler {{ garage.name }}
                </a>
            </div>

            <div v-if="flashSuccess" class="alert alert-success border-0 shadow-sm mb-3">
                <i class="bi bi-check-circle-fill me-2"></i>{{ flashSuccess }}
            </div>

            <!-- En-tête Suivi OR -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
                        <div>
                            <span class="badge bg-primary bg-opacity-10 text-primary mb-2">Suivi atelier en direct</span>
                            <h1 class="h4 fw-bold mb-1">
                                {{ order.vehicle?.license_plate }} · {{ order.vehicle?.brand }} {{ order.vehicle?.model }}
                            </h1>
                            <div class="text-muted small">
                                Dossier <strong>{{ order.order_number }}</strong> · Garage : <strong>{{ garage?.name }}</strong>
                                <span v-if="order.mechanic_name"> · Mécanicien : {{ order.mechanic_name }}</span>
                            </div>
                        </div>
                        <span class="badge bg-primary fs-6">{{ order.status }}</span>
                    </div>

                    <!-- Barre de progression -->
                    <div class="row g-2 text-center">
                        <div v-for="(step, idx) in steps" :key="step.key" class="col-3">
                            <div
                                class="p-2 rounded-3 border h-100"
                                :class="idx <= currentStepIndex ? 'bg-primary text-white border-primary' : 'bg-white text-muted'"
                            >
                                <i :class="`bi ${step.icon} fs-5 d-block mb-1`"></i>
                                <div class="small fw-semibold">{{ step.label }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="order.diagnostic_notes || order.fault_codes?.length" class="alert alert-light border mt-4 mb-0 small">
                        <div class="fw-bold mb-1"><i class="bi bi-clipboard2-pulse me-1 text-primary"></i>Observations du diagnostic</div>
                        <div v-if="order.fault_codes?.length" class="mb-1">Codes défauts : <strong>{{ order.fault_codes.join(', ') }}</strong></div>
                        <div v-if="order.diagnostic_notes">{{ order.diagnostic_notes }}</div>
                    </div>
                </div>
            </div>

            <!-- Tâches en cours -->
            <div v-if="order.tasks?.length" class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 fw-bold">
                    <i class="bi bi-list-check text-primary me-2"></i>Travaux prévus ({{ order.tasks.length }})
                </div>
                <ul class="list-group list-group-flush small">
                    <li v-for="t in order.tasks" :key="t.id" class="list-group-item d-flex justify-content-between align-items-center py-3">
                        <span>{{ t.title }} <span v-if="t.bay_name" class="text-muted">({{ t.bay_name }})</span></span>
                        <span class="badge bg-light text-dark border">{{ t.status }}</span>
                    </li>
                </ul>
            </div>

            <!-- Devis à valider en 1 clic -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 fw-bold">
                    <i class="bi bi-file-earmark-text text-primary me-2"></i>Devis associés ({{ quotes?.length ?? 0 }})
                </div>
                <div class="card-body p-4">
                    <div v-if="!quotes?.length" class="text-muted small text-center py-3">
                        Aucun devis n'a encore été émis pour cette intervention.
                    </div>
                    <div v-else class="vstack gap-3">
                        <div v-for="q in quotes" :key="q.id" class="border rounded-3 p-3 bg-white">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <span class="fw-bold">{{ q.quote_number }} (v{{ q.version }})</span>
                                    <span class="badge ms-2" :class="q.status === 'accepte' ? 'bg-success' : q.status === 'refuse' ? 'bg-danger' : 'bg-warning text-dark'">
                                        {{ q.status }}
                                    </span>
                                </div>
                                <div class="fw-bold fs-5 text-primary">{{ fmt(q.total_ttc) }} FCFA TTC</div>
                            </div>

                            <ul v-if="q.items?.length" class="list-group list-group-flush small mb-3 border rounded">
                                <li v-for="it in q.items" :key="it.id" class="list-group-item d-flex justify-content-between">
                                    <span>{{ it.description }} (×{{ it.quantity }})</span>
                                    <span class="fw-semibold">{{ fmt(it.total_ht) }} FCFA</span>
                                </li>
                            </ul>

                            <div v-if="q.status !== 'accepte' && q.status !== 'refuse'" class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-success btn-sm px-3 fw-semibold" @click="respondQuote(q.id, 'accept')">
                                    <i class="bi bi-check-lg me-1"></i>Accepter le devis en 1 clic
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm px-3" @click="respondQuote(q.id, 'reject')">
                                    <i class="bi bi-x-lg me-1"></i>Refuser
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <ConfirmStaticModal />
    </div>
</template>
