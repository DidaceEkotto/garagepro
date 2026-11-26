import {
    addQueueItem,
    addSyncLog,
    clearSyncLogsForTenant,
    deleteQueueItem,
    getQueueItemsForTenant,
    getSnapshot,
    getSyncLogsForTenant,
    saveSnapshot,
    updateQueueItem,
} from '@/offline/db';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Singleton réactif partagé par tout l'espace professionnel
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const isSyncing = ref(false);
const activeTenantId = ref(null);
const queueItems = ref([]);
const syncLogs = ref([]);
const lastSyncedAt = ref(null);
const offlineNotice = ref(null);

// Support d'installation PWA
const deferredInstallPrompt = ref(null);
const canInstallPwa = computed(() => Boolean(deferredInstallPrompt.value));

let initialized = false;
let pingIntervalId = null;

function generateUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `off-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function setCsrfToken(token) {
    if (!token) return;
    let meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'csrf-token';
        document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
}

function showNotice(message, type = 'warning') {
    offlineNotice.value = { message, type, ts: Date.now() };
    setTimeout(() => {
        if (offlineNotice.value && Date.now() - offlineNotice.value.ts >= 5500) {
            offlineNotice.value = null;
        }
    }, 6000);
}

async function refreshQueueAndLogs() {
    if (!activeTenantId.value) {
        queueItems.value = [];
        syncLogs.value = [];
        return;
    }
    try {
        queueItems.value = await getQueueItemsForTenant(activeTenantId.value);
        syncLogs.value = await getSyncLogsForTenant(activeTenantId.value);
    } catch {
        // IndexedDB indisponible (ex: navigation privée stricte)
    }
}

async function pingServer() {
    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 5000);
        const res = await fetch('/offline/ping', {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        });
        clearTimeout(timer);

        if (!res.ok) {
            return false;
        }

        const data = await res.json();
        if (data?.csrf_token) {
            setCsrfToken(data.csrf_token);
        }
        isOnline.value = true;
        return true;
    } catch {
        isOnline.value = false;
        return false;
    }
}

async function refreshBootstrapCache() {
    if (!activeTenantId.value || !isOnline.value) return;
    try {
        const res = await fetch('/offline/bootstrap', {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data?.ok) {
            await saveSnapshot(activeTenantId.value, 'customers', data.customers || []);
            await saveSnapshot(activeTenantId.value, 'vehicles', data.vehicles || []);
            await saveSnapshot(activeTenantId.value, 'agencies', data.agencies || []);
        }
    } catch {
        // Non bloquant
    }
}

function resolveTempIdsInPayload(payload, tempIdMap) {
    const resolved = { ...payload };
    const refFields = ['customer_id', 'vehicle_id', 'insurance_policy_id', 'parent_inspection_id'];
    for (const field of refFields) {
        const val = resolved[field];
        if (typeof val === 'string' && tempIdMap[val]) {
            resolved[field] = tempIdMap[val];
        }
    }
    return resolved;
}

async function enqueueOperation({
    entity,
    action = 'create',
    label = '',
    payload = {},
    files = [],
    recordId = null,
    tempId = null,
    baseUpdatedAt = null,
}) {
    if (!activeTenantId.value) {
        throw new Error('Aucun établissement actif pour le mode hors ligne.');
    }

    const clientUuid = generateUuid();
    const generatedTempId = tempId || (action === 'create' ? `tmp_${entity}_${Date.now()}_${Math.random().toString(36).slice(2, 6)}` : null);

    // Cloner les fichiers en Blobs sérialisables dans IndexedDB
    const storedFiles = [];
    for (const f of files || []) {
        if (f instanceof Blob) {
            storedFiles.push({
                name: f.name || `photo_${Date.now()}.jpg`,
                type: f.type || 'image/jpeg',
                blob: f,
            });
        }
    }

    const item = {
        tenant_id: Number(activeTenantId.value),
        client_uuid: clientUuid,
        entity,
        action,
        label: label || `${entity} (${action})`,
        payload: JSON.parse(JSON.stringify(payload)),
        files: storedFiles,
        record_id: recordId,
        temp_id: generatedTempId,
        base_updated_at: baseUpdatedAt,
        status: 'pending',
        error: null,
        created_at: new Date().toISOString(),
    };

    await addQueueItem(item);
    await refreshQueueAndLogs();

    showNotice(
        `Enregistré hors ligne (« ${item.label} »). Synchronisation automatique dès le retour d'Internet.`,
        'warning'
    );

    return { queued: true, tempId: generatedTempId, clientUuid };
}

async function syncNow() {
    if (isSyncing.value || !activeTenantId.value) return;

    const reachable = await pingServer();
    if (!reachable) {
        showNotice('Toujours hors ligne. Vos saisies restent sauvegardées en sécurité.', 'warning');
        return;
    }

    const items = await getQueueItemsForTenant(activeTenantId.value);
    const toProcess = items.filter((i) => i.status === 'pending' || i.status === 'failed');

    if (toProcess.length === 0) {
        await refreshBootstrapCache();
        return;
    }

    isSyncing.value = true;
    const tempIdMap = {};
    let syncedCount = 0;
    let conflictCount = 0;
    let failedCount = 0;

    try {
        for (const item of toProcess) {
            item.status = 'syncing';
            item.error = null;
            await updateQueueItem(item);
            await refreshQueueAndLogs();

            const resolvedPayload = resolveTempIdsInPayload(item.payload || {}, tempIdMap);
            let recordId = item.record_id;
            if (typeof recordId === 'string' && tempIdMap[recordId]) {
                recordId = tempIdMap[recordId];
            }

            try {
                const csrf = getCsrfToken();
                let res;

                if (Array.isArray(item.files) && item.files.length > 0) {
                    const formData = new FormData();
                    formData.append('client_uuid', item.client_uuid);
                    formData.append('entity', item.entity);
                    formData.append('action', item.action);
                    if (recordId) formData.append('record_id', String(recordId));
                    if (item.base_updated_at) formData.append('base_updated_at', item.base_updated_at);
                    formData.append('payload', JSON.stringify(resolvedPayload));

                    item.files.forEach((f, idx) => {
                        formData.append(`photos[${idx}]`, f.blob, f.name || `photo_${idx}.jpg`);
                    });

                    res = await fetch('/offline/sync', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: formData,
                    });
                } else {
                    res = await fetch('/offline/sync', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            client_uuid: item.client_uuid,
                            entity: item.entity,
                            action: item.action,
                            record_id: recordId,
                            base_updated_at: item.base_updated_at,
                            payload: resolvedPayload,
                        }),
                    });
                }

                if (res.status === 419) {
                    // Token CSRF expiré : rafraîchir et marquer pending pour retry immédiat
                    await pingServer();
                    item.status = 'pending';
                    item.error = 'Session CSRF rafraîchie, nouvel essai nécessaire.';
                    await updateQueueItem(item);
                    failedCount++;
                    continue;
                }

                const data = await res.json().catch(() => ({}));

                if (!res.ok || !data?.ok) {
                    const firstValidationError = data?.errors
                        ? Object.values(data.errors).flat()[0]
                        : null;
                    item.status = 'failed';
                    item.error = firstValidationError || data?.message || `Erreur serveur (${res.status})`;
                    await updateQueueItem(item);
                    await addSyncLog({
                        tenant_id: Number(activeTenantId.value),
                        level: 'error',
                        title: `Échec synchro : ${item.label}`,
                        detail: item.error,
                        created_at: new Date().toISOString(),
                    });
                    failedCount++;
                    continue;
                }

                if (item.temp_id && data.id) {
                    tempIdMap[item.temp_id] = data.id;
                }

                if (data.conflict) {
                    conflictCount++;
                    await addSyncLog({
                        tenant_id: Number(activeTenantId.value),
                        level: 'conflict',
                        title: `Conflit résolu : ${item.label}`,
                        detail: data.conflict_message || 'Dernière modification appliquée.',
                        created_at: new Date().toISOString(),
                    });
                } else {
                    await addSyncLog({
                        tenant_id: Number(activeTenantId.value),
                        level: 'info',
                        title: `Synchronisé : ${data.label || item.label}`,
                        detail: data.duplicate ? 'Déjà synchronisé (anti-doublon).' : 'Enregistré sur le serveur.',
                        created_at: new Date().toISOString(),
                    });
                }

                await deleteQueueItem(item.id);
                syncedCount++;
            } catch {
                // Coupure réseau pendant la synchro : remettre en pending et arrêter la boucle
                item.status = 'pending';
                item.error = null;
                await updateQueueItem(item);
                isOnline.value = false;
                break;
            }
        }
    } finally {
        isSyncing.value = false;
        lastSyncedAt.value = new Date().toISOString();
        await refreshQueueAndLogs();
    }

    if (syncedCount > 0) {
        await refreshBootstrapCache();
        showNotice(
            conflictCount > 0
                ? `${syncedCount} élément(s) synchronisé(s) dont ${conflictCount} conflit(s) résolu(s).`
                : `${syncedCount} élément(s) hors ligne synchronisé(s) avec succès !`,
            conflictCount > 0 ? 'warning' : 'success'
        );
        // Recharger proprement les props Inertia de la page courante
        try {
            router.reload({ preserveScroll: true });
        } catch {
            // Non bloquant
        }
    } else if (failedCount > 0) {
        showNotice(`${failedCount} élément(s) en erreur. Ouvrez le Centre de Synchronisation.`, 'danger');
    }
}

async function removeQueueItem(id) {
    await deleteQueueItem(id);
    await refreshQueueAndLogs();
}

async function clearLogs() {
    if (!activeTenantId.value) return;
    await clearSyncLogsForTenant(activeTenantId.value);
    await refreshQueueAndLogs();
}

async function installPwa() {
    if (!deferredInstallPrompt.value) return false;
    deferredInstallPrompt.value.prompt();
    const choice = await deferredInstallPrompt.value.userChoice;
    deferredInstallPrompt.value = null;
    return choice?.outcome === 'accepted';
}

function initOfflineSync(tenantId) {
    if (tenantId) {
        activeTenantId.value = Number(tenantId);
        refreshQueueAndLogs();
        if (isOnline.value) {
            refreshBootstrapCache();
        }
    }

    if (initialized || typeof window === 'undefined') {
        return;
    }
    initialized = true;

    window.addEventListener('online', async () => {
        isOnline.value = true;
        await syncNow();
    });

    window.addEventListener('offline', () => {
        isOnline.value = false;
        showNotice('Connexion Internet coupée — Mode Hors Ligne activé.', 'warning');
    });

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt.value = event;
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt.value = null;
        showNotice('MboaCar a été installé sur votre appareil !', 'success');
    });

    // Vérification périodique discrète quand hors ligne ou quand des éléments attendent
    pingIntervalId = setInterval(async () => {
        if (!activeTenantId.value) return;
        const hasPending = queueItems.value.some((i) => i.status === 'pending');
        if (!isOnline.value || hasPending) {
            const ok = await pingServer();
            if (ok && hasPending) {
                await syncNow();
            }
        }
    }, 30000);
}

const pendingCount = computed(() => queueItems.value.filter((i) => i.status === 'pending' || i.status === 'syncing').length);
const failedCount = computed(() => queueItems.value.filter((i) => i.status === 'failed').length);
const conflictLogsCount = computed(() => syncLogs.value.filter((l) => l.level === 'conflict').length);

function pendingItemsForEntity(entity) {
    return computed(() =>
        queueItems.value.filter((i) => i.entity === entity && i.action === 'create')
    );
}

export function useOfflineSync() {
    return {
        isOnline,
        isSyncing,
        queueItems,
        syncLogs,
        pendingCount,
        failedCount,
        conflictLogsCount,
        lastSyncedAt,
        offlineNotice,
        canInstallPwa,
        initOfflineSync,
        enqueueOperation,
        syncNow,
        removeQueueItem,
        clearLogs,
        installPwa,
        pendingItemsForEntity,
        getSnapshot: (entity) => (activeTenantId.value ? getSnapshot(activeTenantId.value, entity) : Promise.resolve(null)),
    };
}
