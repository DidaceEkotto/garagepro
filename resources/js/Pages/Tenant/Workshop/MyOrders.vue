<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    orders: Object,
});

function changeStatus(orderId, status) {
    router.patch(`/atelier/or/${orderId}/statut`, { status });
}

function logTime(taskId, minutes) {
    router.post(`/atelier/taches/${taskId}/pointage`, { minutes, status: 'en_cours' });
}

function submitQuality(orderId, passed) {
    router.post(`/atelier/or/${orderId}/controle-qualite`, {
        road_test_done: true,
        passed,
        notes: passed ? 'Essai routier concluant.' : 'Anomalie détectée lors du contrôle.',
    });
}
</script>

<template>
    <Head title="Mes OR du jour" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Atelier — Mes OR du jour</h1>
            <p class="text-muted small mb-0">Pointage temps réel, changement d'avancement et contrôle qualité final</p>
        </div>

        <div class="row g-3">
            <div v-if="!orders.data?.length" class="col-12">
                <div class="card border-0 shadow-sm p-4 text-center text-muted small">
                    Aucun ordre de réparation actif dans l'atelier.
                </div>
            </div>
            <div v-for="o in orders.data" :key="o.id" class="col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <span class="fw-bold">{{ o.order_number }} — {{ o.vehicle?.license_plate }}</span>
                        <span class="badge bg-primary">{{ o.status }}</span>
                    </div>
                    <div class="card-body">
                        <div class="small text-muted mb-3">
                            {{ o.vehicle?.brand }} {{ o.vehicle?.model }} • Client : {{ o.customer?.name }}
                        </div>

                        <div class="d-flex flex-wrap gap-1 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="changeStatus(o.id, 'en_cours')">En cours</button>
                            <button type="button" class="btn btn-outline-warning btn-sm" @click="changeStatus(o.id, 'en_pause')">Pause</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="changeStatus(o.id, 'attente_piece')">Attente pièce</button>
                            <button type="button" class="btn btn-outline-dark btn-sm" @click="changeStatus(o.id, 'controle_qualite')">Contrôle qualité</button>
                        </div>

                        <ul class="list-group list-group-flush small mb-3">
                            <li v-for="t in o.tasks" :key="t.id" class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold">{{ t.title }}</div>
                                    <span class="text-muted">{{ t.actual_minutes }} / {{ t.estimated_minutes }} min</span>
                                </div>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-success" @click="logTime(t.id, 15)">+15m</button>
                                    <button type="button" class="btn btn-outline-success" @click="logTime(t.id, 30)">+30m</button>
                                </div>
                            </li>
                        </ul>

                        <div class="d-flex gap-2 pt-2 border-top">
                            <button type="button" class="btn btn-success btn-sm" @click="submitQuality(o.id, true)">
                                <i class="bi bi-check-circle me-1"></i>Valider Contrôle Qualité
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" @click="submitQuality(o.id, false)">
                                Retour atelier
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
