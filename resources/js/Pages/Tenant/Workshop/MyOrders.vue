<script setup>
import TenantLayout from '@/Layouts/TenantLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    orders: Object,
    mechanicProductivity: { type: Array, default: () => [] },
});

const OFFLINE_TIMERS_KEY = 'mboacar_offline_mechanic_timers';
const nowTick = ref(Date.now());
const offlineTimers = ref(loadOfflineTimers());
let tickHandle = null;

function loadOfflineTimers() {
    try {
        return JSON.parse(localStorage.getItem(OFFLINE_TIMERS_KEY) || '{}') || {};
    } catch {
        return {};
    }
}

function saveOfflineTimers() {
    try {
        localStorage.setItem(OFFLINE_TIMERS_KEY, JSON.stringify(offlineTimers.value));
    } catch {
        // ignore quota errors
    }
}

onMounted(() => {
    tickHandle = setInterval(() => {
        nowTick.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => {
    if (tickHandle) clearInterval(tickHandle);
});

function isRunning(task) {
    return Boolean(task.timer_started_at || offlineTimers.value[task.id]);
}

function liveElapsedLabel(task) {
    const startIso = offlineTimers.value[task.id] || task.timer_started_at;
    if (!startIso) return '';
    const diffSec = Math.max(0, Math.floor((nowTick.value - new Date(startIso).getTime()) / 1000));
    const h = Math.floor(diffSec / 3600);
    const m = Math.floor((diffSec % 3600) / 60);
    const s = diffSec % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function startTimer(task) {
    if (!navigator.onLine) {
        offlineTimers.value = { ...offlineTimers.value, [task.id]: new Date().toISOString() };
        saveOfflineTimers();
        return;
    }
    router.post(`/atelier/taches/${task.id}/chrono/demarrer`, {}, { preserveScroll: true });
}

function pauseTimer(task, finish = false) {
    let offlineMinutes = 0;
    if (offlineTimers.value[task.id]) {
        const started = new Date(offlineTimers.value[task.id]).getTime();
        offlineMinutes = Math.max(1, Math.ceil((Date.now() - started) / 60000));
        const copy = { ...offlineTimers.value };
        delete copy[task.id];
        offlineTimers.value = copy;
        saveOfflineTimers();
    }

    router.post(`/atelier/taches/${task.id}/chrono/pause`, {
        finish,
        offline_elapsed_minutes: offlineMinutes || undefined,
    }, { preserveScroll: true });
}

function changeStatus(orderId, status) {
    router.patch(`/atelier/or/${orderId}/statut`, { status }, { preserveScroll: true });
}

function logTime(taskId, minutes) {
    router.post(`/atelier/taches/${taskId}/pointage`, { minutes, status: 'en_cours' }, { preserveScroll: true });
}

function submitQuality(orderId, passed) {
    router.post(`/atelier/or/${orderId}/controle-qualite`, {
        road_test_done: true,
        passed,
        notes: passed ? 'Essai routier concluant.' : 'Anomalie détectée lors du contrôle.',
    }, { preserveScroll: true });
}

function fmt(n) {
    return new Intl.NumberFormat('fr-FR').format(n ?? 0) + ' FCFA';
}
</script>

<template>
    <Head title="Mes OR du jour &amp; Chrono Atelier" />
    <TenantLayout>
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-0">Atelier — Chrono Mécaniciens &amp; Rendement</h1>
            <p class="text-muted small mb-0">Chronomètre en direct (compatible PWA hors ligne), heures passées vs facturées et primes mécaniciens</p>
        </div>

        <div class="row g-3 mb-4">
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
                            <li v-for="t in o.tasks" :key="t.id" class="list-group-item px-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <div class="fw-semibold">
                                        {{ t.title }}
                                        <span v-if="isRunning(t)" class="badge bg-danger ms-1">
                                            <i class="bi bi-stopwatch me-1"></i>{{ liveElapsedLabel(t) }}
                                        </span>
                                    </div>
                                    <span class="text-muted">
                                        Passé : <strong>{{ t.actual_minutes }} min</strong> / Facturé : {{ t.estimated_minutes }} min
                                        <span v-if="t.mechanic"> • {{ t.mechanic.name }}</span>
                                    </span>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <button v-if="!isRunning(t) && t.status !== 'termine'" type="button" class="btn btn-success btn-sm" @click="startTimer(t)">
                                        <i class="bi bi-play-fill"></i> Chrono
                                    </button>
                                    <button v-if="isRunning(t)" type="button" class="btn btn-warning btn-sm" @click="pauseTimer(t, false)">
                                        <i class="bi bi-pause-fill"></i> Pause
                                    </button>
                                    <button v-if="isRunning(t)" type="button" class="btn btn-primary btn-sm" @click="pauseTimer(t, true)">
                                        <i class="bi bi-check2"></i> Fin
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="logTime(t.id, 15)">+15m</button>
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

        <!-- Tableau de rendement & primes mécaniciens (30 derniers jours) -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-speedometer2 text-primary me-2"></i>Rendement &amp; Primes Mécaniciens (30 jours)</h2>
                <p class="text-muted small mb-0">Heures passées vs heures facturées et calcul automatique des primes / commissions</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Mécanicien</th>
                                <th class="text-center">Tâches (Terminées)</th>
                                <th class="text-end">Heures Passées</th>
                                <th class="text-end">Heures Facturées</th>
                                <th class="text-end">Rendement</th>
                                <th class="text-end">Prime estimée</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in mechanicProductivity" :key="m.mechanic_id">
                                <td class="fw-semibold">{{ m.mechanic_name }}</td>
                                <td class="text-center">{{ m.tasks_count }} ({{ m.completed_count }})</td>
                                <td class="text-end">{{ m.actual_hours }} h</td>
                                <td class="text-end">{{ m.estimated_hours }} h</td>
                                <td class="text-end">
                                    <span class="badge" :class="m.efficiency_pct >= 100 ? 'bg-success' : (m.efficiency_pct >= 75 ? 'bg-warning text-dark' : 'bg-danger')">
                                        {{ m.efficiency_pct }}%
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-success">{{ fmt(m.estimated_commission) }}</td>
                            </tr>
                            <tr v-if="!mechanicProductivity.length">
                                <td colspan="6" class="text-center text-muted py-4">Aucun pointage mécanicien enregistré sur les 30 derniers jours.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </TenantLayout>
</template>
