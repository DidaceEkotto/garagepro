<script setup>
import PwaInstallButton from '@/Components/PwaInstallButton.vue';
import { useOfflineSync } from '@/composables/useOfflineSync';
import { computed, ref } from 'vue';

const {
    isOnline,
    isSyncing,
    queueItems,
    syncLogs,
    pendingCount,
    failedCount,
    conflictLogsCount,
    syncNow,
    removeQueueItem,
    clearLogs,
} = useOfflineSync();

const modalTab = ref('queue');

const totalBadge = computed(() => pendingCount.value + failedCount.value);

function entityLabel(entity) {
    const map = {
        customer: 'Client',
        vehicle: 'Véhicule',
        technical_inspection: 'Visite Technique',
        insurance_policy: 'Police Assurance',
        insurance_claim: 'Sinistre Assurance',
    };
    return map[entity] || entity;
}

function formatDate(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString('fr-FR', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}
</script>

<template>
    <div class="d-flex align-items-center gap-2">
        <!-- Bouton Installer PWA (toujours accessible PC & mobile tant que non installé) -->
        <PwaInstallButton variant="light" size="sm" label="Installer l'app" :compact="true" />

        <!-- Pill État Réseau & File de synchronisation -->
        <button
            type="button"
            class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-2 px-3 fw-semibold border-0"
            :class="
                isSyncing
                    ? 'btn-info text-dark'
                    : !isOnline
                        ? 'btn-warning text-dark'
                        : failedCount > 0
                            ? 'btn-danger text-white'
                            : totalBadge > 0
                                ? 'btn-primary text-white'
                                : 'bg-white bg-opacity-10 text-white'
            "
            data-bs-toggle="modal"
            data-bs-target="#modalOfflineSyncCenter"
            title="Centre de synchronisation hors ligne"
        >
            <span v-if="isSyncing" class="spinner-border spinner-border-sm" role="status"></span>
            <span
                v-else
                class="rounded-circle d-inline-block"
                :class="isOnline ? 'bg-success' : 'bg-danger'"
                style="width: 8px; height: 8px;"
            ></span>

            <span class="small d-none d-sm-inline">
                <template v-if="isSyncing">Synchro...</template>
                <template v-else-if="!isOnline">Hors ligne</template>
                <template v-else>En ligne</template>
            </span>

            <span v-if="totalBadge > 0" class="badge bg-dark text-white rounded-pill">
                {{ totalBadge }}
            </span>
            <span v-else-if="conflictLogsCount > 0" class="badge bg-warning text-dark rounded-pill" title="Conflits résolus">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ conflictLogsCount }}
            </span>
        </button>
    </div>

    <!-- Modal Centre de Synchronisation (téléporté dans body pour éviter tout z-index/backdrop conflict) -->
    <Teleport to="body">
        <div class="modal fade" id="modalOfflineSyncCenter" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <div class="modal-header border-bottom">
                        <div>
                            <h5 class="modal-title fw-bold mb-0 d-flex align-items-center gap-2">
                                <i class="bi bi-cloud-arrow-up-fill text-primary"></i>
                                Centre de Synchronisation Hors Ligne
                            </h5>
                            <div class="small text-muted">
                                État actuel :
                                <strong :class="isOnline ? 'text-success' : 'text-warning'">
                                    {{ isOnline ? 'Connecté à Internet' : 'Hors ligne (enregistrement local actif)' }}
                                </strong>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>

                    <div class="modal-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <ul class="nav nav-pills nav-sm gap-1">
                                <li class="nav-item">
                                    <button
                                        type="button"
                                        class="nav-link py-1 px-3 small"
                                        :class="{ active: modalTab === 'queue' }"
                                        @click="modalTab = 'queue'"
                                    >
                                        File d'attente ({{ queueItems.length }})
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button
                                        type="button"
                                        class="nav-link py-1 px-3 small"
                                        :class="{ active: modalTab === 'logs' }"
                                        @click="modalTab = 'logs'"
                                    >
                                        Journal &amp; Conflits ({{ syncLogs.length }})
                                        <span v-if="conflictLogsCount > 0" class="badge bg-warning text-dark ms-1">
                                            {{ conflictLogsCount }}
                                        </span>
                                    </button>
                                </li>
                            </ul>

                            <div class="d-flex gap-2">
                                <button
                                    v-if="modalTab === 'queue' && queueItems.length > 0"
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    :disabled="isSyncing"
                                    @click="syncNow"
                                >
                                    <span v-if="isSyncing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="bi bi-arrow-repeat me-1"></i>
                                    Synchroniser maintenant
                                </button>
                                <button
                                    v-if="modalTab === 'logs' && syncLogs.length > 0"
                                    type="button"
                                    class="btn btn-outline-secondary btn-sm"
                                    @click="clearLogs"
                                >
                                    Vider le journal
                                </button>
                            </div>
                        </div>

                        <!-- Onglet 1 : File d'attente -->
                        <div v-if="modalTab === 'queue'">
                            <div v-if="queueItems.length === 0" class="text-center py-4 text-muted">
                                <i class="bi bi-cloud-check fs-1 text-success d-block mb-2"></i>
                                <div class="fw-semibold text-dark">Toutes vos données sont synchronisées !</div>
                                <div class="small">Si Internet coupe, vos saisies (Clients, Véhicules, Visite Technique, Assurances) seront automatiquement sauvegardées ici.</div>
                            </div>

                            <div v-else class="list-group list-group-flush">
                                <div
                                    v-for="item in queueItems"
                                    :key="item.id"
                                    class="list-group-item px-0 py-3 d-flex justify-content-between align-items-start gap-3"
                                >
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge bg-light text-dark border">{{ entityLabel(item.entity) }}</span>
                                            <span
                                                class="badge"
                                                :class="
                                                    item.status === 'failed'
                                                        ? 'bg-danger'
                                                        : item.status === 'syncing'
                                                            ? 'bg-info text-dark'
                                                            : 'bg-warning text-dark'
                                                "
                                            >
                                                {{ item.status === 'failed' ? 'Erreur' : item.status === 'syncing' ? 'Envoi...' : 'En attente' }}
                                            </span>
                                            <span v-if="item.files?.length" class="badge bg-primary-subtle text-primary">
                                                <i class="bi bi-image me-1"></i>{{ item.files.length }} photo(s)
                                            </span>
                                        </div>
                                        <div class="fw-semibold small text-dark">{{ item.label }}</div>
                                        <div class="text-muted" style="font-size: 0.76rem;">
                                            Saisi le {{ formatDate(item.created_at) }}
                                        </div>
                                        <div v-if="item.error" class="text-danger small mt-1">
                                            <i class="bi bi-exclamation-circle me-1"></i>{{ item.error }}
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        title="Supprimer de la file"
                                        @click="removeQueueItem(item.id)"
                                    >
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Onglet 2 : Journal & Conflits -->
                        <div v-if="modalTab === 'logs'">
                            <div v-if="syncLogs.length === 0" class="text-center py-4 text-muted small">
                                Aucun événement de synchronisation récent.
                            </div>
                            <div v-else class="list-group list-group-flush">
                                <div
                                    v-for="log in syncLogs"
                                    :key="log.id"
                                    class="list-group-item px-0 py-2"
                                >
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="fw-semibold small">
                                            <i
                                                class="bi me-1"
                                                :class="
                                                    log.level === 'conflict'
                                                        ? 'bi-exclamation-triangle-fill text-warning'
                                                        : log.level === 'error'
                                                            ? 'bi-x-circle-fill text-danger'
                                                            : 'bi-check-circle-fill text-success'
                                                "
                                            ></i>
                                            {{ log.title }}
                                        </span>
                                        <span class="text-muted" style="font-size: 0.74rem;">{{ formatDate(log.created_at) }}</span>
                                    </div>
                                    <div class="small text-muted ps-4">{{ log.detail }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer border-top d-flex justify-content-between">
                        <span class="small text-muted">
                            <i class="bi bi-shield-check me-1 text-success"></i>Protection anti-doublon active
                        </span>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
