import {
    addQueueItem,
    addSyncLog,
    clearSyncLogsForTenant,
    deleteQueueItem,
    getOfflineMeta,
    getQueueItemsForTenant,
    getSnapshot,
    getSyncLogsForTenant,
    saveSnapshot,
    setOfflineMeta,
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

// Bail hors ligne 14 jours + anti-triche horloge
const leaseExpiresAt = ref(null); // ISO string
const maxSeenClockMs = ref(0);
const clockTampered = ref(false);
const leaseNowTick = ref(Date.now());

// Support d'installation PWA
const deferredInstallPrompt = ref(null);
const canInstallPwa = computed(() => Boolean(deferredInstallPrompt.value));

let initialized = false;
let pingIntervalId = null;
let precachedForTenant = null;

// Routes qui nécessitent obligatoirement Internet (jamais mises en file hors ligne)
const ONLINE_ONLY_PREFIXES = [
    '/assistant-ia',
    '/paiements',
    '/abonnement',
    '/deconnexion',
    '/connexion',
    '/inscription',
    '/admin',
    '/mon-espace-client',
    '/devis-public',
    '/offline/',
];

function isOnlineOnlyUrl(urlStr) {
    try {
        const parsed = new URL(urlStr, window.location.origin);
        const path = parsed.pathname;
        return ONLINE_ONLY_PREFIXES.some((prefix) => path === prefix || path.startsWith(prefix + '/') || path.startsWith(prefix));
    } catch {
        return false;
    }
}

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
        if (offlineNotice.value && Date.now() - offlineNotice.value.ts >= 6000) {
            offlineNotice.value = null;
        }
    }, 6500);
}

const leaseDaysRemaining = computed(() => {
    if (!leaseExpiresAt.value) return 14;
    const diffMs = new Date(leaseExpiresAt.value).getTime() - leaseNowTick.value;
    if (diffMs <= 0) return 0;
    return Math.ceil(diffMs / (1000 * 60 * 60 * 24));
});

const leaseExpired = computed(() => {
    if (isOnline.value) return false;
    if (clockTampered.value) return true;
    if (!leaseExpiresAt.value) return false;
    return leaseNowTick.value > new Date(leaseExpiresAt.value).getTime();
});

const leaseWarning = computed(() => {
    if (isOnline.value || leaseExpired.value || !leaseExpiresAt.value) return false;
    return leaseDaysRemaining.value <= 4;
});

async function loadLeaseAndCheckClock() {
    if (!activeTenantId.value) return;
    try {
        const savedLease = await getOfflineMeta(`lease:${activeTenantId.value}`);
        const savedMaxClock = await getOfflineMeta(`max_clock:${activeTenantId.value}`);

        if (savedLease?.expires_at) {
            leaseExpiresAt.value = savedLease.expires_at;
        }

        const now = Date.now();
        leaseNowTick.value = now;

        if (typeof savedMaxClock === 'number' && savedMaxClock > 0) {
            maxSeenClockMs.value = savedMaxClock;
            // Tolérance de 5 minutes pour éviter faux positifs NTP ; au-delà = horloge reculée
            if (now + 5 * 60 * 1000 < savedMaxClock) {
                clockTampered.value = true;
            } else {
                clockTampered.value = false;
                if (now > savedMaxClock) {
                    maxSeenClockMs.value = now;
                    await setOfflineMeta(`max_clock:${activeTenantId.value}`, now);
                }
            }
        } else {
            maxSeenClockMs.value = now;
            await setOfflineMeta(`max_clock:${activeTenantId.value}`, now);
        }
    } catch {
        // Non bloquant
    }
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
        // IndexedDB indisponible
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

        // Renouvellement automatique du bail hors ligne (14j plafonné à l'abonnement)
        const tenantId = data?.tenant_id || activeTenantId.value;
        if (tenantId && data?.offline_lease_expires_at) {
            leaseExpiresAt.value = data.offline_lease_expires_at;
            await setOfflineMeta(`lease:${tenantId}`, {
                expires_at: data.offline_lease_expires_at,
                is_read_only: Boolean(data.is_read_only),
                renewed_at: data.server_time,
            });
        }

        const serverMs = Number(data?.server_timestamp_ms || Date.now());
        const nextMaxClock = Math.max(serverMs, Date.now());
        maxSeenClockMs.value = nextMaxClock;
        clockTampered.value = false;
        leaseNowTick.value = Date.now();
        if (tenantId) {
            await setOfflineMeta(`max_clock:${tenantId}`, nextMaxClock);
        }

        return true;
    } catch {
        isOnline.value = false;
        await loadLeaseAndCheckClock();
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

/**
 * Pré-cache discrètement les pages principales du menu de l'établissement connecté
 * afin qu'elles puissent s'ouvrir même si Internet coupe avant de les visiter.
 */
async function precacheMenuPages(urls = []) {
    if (!activeTenantId.value || !isOnline.value || !Array.isArray(urls) || urls.length === 0) {
        return;
    }
    if (precachedForTenant === activeTenantId.value) {
        return;
    }
    precachedForTenant = activeTenantId.value;

    const filtered = urls
        .filter((u) => typeof u === 'string' && u.startsWith('/') && !isOnlineOnlyUrl(u))
        .slice(0, 18);

    // Préchargement doux et séquentiel en arrière-plan pour ne pas saturer une connexion 3G/4G
    setTimeout(async () => {
        for (const path of filtered) {
            if (!isOnline.value) break;
            try {
                await fetch(path, {
                    method: 'GET',
                    headers: {
                        'X-Inertia': 'true',
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'text/html, application/xhtml+xml',
                    },
                    credentials: 'same-origin',
                });
            } catch {
                // Non bloquant
            }
            await new Promise((r) => setTimeout(r, 250));
        }
    }, 2500);
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

function extractFilesAndData(rawData) {
    const cleanPayload = {};
    const extractedFiles = [];

    if (rawData instanceof FormData) {
        for (const [key, val] of rawData.entries()) {
            if (val instanceof Blob) {
                extractedFiles.push({
                    field: key,
                    name: val.name || `fichier_${Date.now()}.bin`,
                    type: val.type || 'application/octet-stream',
                    blob: val,
                });
            } else if (Object.prototype.hasOwnProperty.call(cleanPayload, key)) {
                if (!Array.isArray(cleanPayload[key])) {
                    cleanPayload[key] = [cleanPayload[key]];
                }
                cleanPayload[key].push(val);
            } else {
                cleanPayload[key] = val;
            }
        }
        return { payload: cleanPayload, files: extractedFiles };
    }

    const walk = (obj, prefix = '') => {
        if (!obj || typeof obj !== 'object') return;
        for (const [k, v] of Object.entries(obj)) {
            const fieldKey = prefix ? `${prefix}[${k}]` : k;
            if (v instanceof Blob) {
                extractedFiles.push({
                    field: fieldKey,
                    name: v.name || `fichier_${Date.now()}.bin`,
                    type: v.type || 'application/octet-stream',
                    blob: v,
                });
            } else if (Array.isArray(v) && v.some((x) => x instanceof Blob)) {
                v.forEach((fileItem, idx) => {
                    if (fileItem instanceof Blob) {
                        extractedFiles.push({
                            field: `${fieldKey}[${idx}]`,
                            name: fileItem.name || `fichier_${idx}.bin`,
                            type: fileItem.type || 'application/octet-stream',
                            blob: fileItem,
                        });
                    }
                });
            } else if (!prefix) {
                cleanPayload[k] = v;
            }
        }
    };

    walk(rawData);
    return {
        payload: JSON.parse(JSON.stringify(cleanPayload)),
        files: extractedFiles,
    };
}

function closeOpenModals() {
    try {
        document.querySelectorAll('.modal.show').forEach((el) => {
            window.bootstrap?.Modal.getInstance(el)?.hide();
        });
    } catch {
        // Non bloquant
    }
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
    url = null,
    method = null,
}) {
    if (!activeTenantId.value) {
        throw new Error('Aucun établissement actif pour le mode hors ligne.');
    }

    await loadLeaseAndCheckClock();
    if (leaseExpired.value) {
        showNotice(
            clockTampered.value
                ? 'Horloge système incohérente détectée. Reconnectez-vous à Internet pour réactiver le mode hors ligne.'
                : 'Votre période hors ligne de 14 jours (ou votre abonnement) a expiré. Connectez-vous à Internet pour synchroniser et prolonger.',
            'danger'
        );
        return { queued: false, blockedByLease: true };
    }

    const clientUuid = generateUuid();
    const generatedTempId = tempId || (action === 'create' ? `tmp_${entity}_${Date.now()}_${Math.random().toString(36).slice(2, 6)}` : null);

    const storedFiles = [];
    for (const f of files || []) {
        if (f instanceof Blob) {
            storedFiles.push({
                field: 'photos[]',
                name: f.name || `photo_${Date.now()}.jpg`,
                type: f.type || 'image/jpeg',
                blob: f,
            });
        } else if (f && f.blob instanceof Blob) {
            storedFiles.push(f);
        }
    }

    const item = {
        tenant_id: Number(activeTenantId.value),
        client_uuid: clientUuid,
        entity,
        action,
        url,
        method,
        label: label || `${entity} (${action})`,
        payload: JSON.parse(JSON.stringify(payload || {})),
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
        `Enregistré hors ligne (« ${item.label} »). Synchronisation & envois WhatsApp automatiques dès le retour d'Internet.`,
        'warning'
    );

    return { queued: true, tempId: generatedTempId, clientUuid };
}

function buildGenericFormData(payload, files, method) {
    const fd = new FormData();
    const upperMethod = String(method || 'POST').toUpperCase();
    if (upperMethod !== 'POST') {
        fd.append('_method', upperMethod);
    }

    const appendValue = (key, val) => {
        if (val === null || val === undefined) return;
        if (Array.isArray(val)) {
            val.forEach((v, i) => appendValue(`${key}[${i}]`, v));
        } else if (typeof val === 'object') {
            Object.entries(val).forEach(([k, v]) => appendValue(`${key}[${k}]`, v));
        } else if (typeof val === 'boolean') {
            fd.append(key, val ? '1' : '0');
        } else {
            fd.append(key, String(val));
        }
    };

    Object.entries(payload || {}).forEach(([k, v]) => appendValue(k, v));

    (files || []).forEach((f) => {
        if (f?.blob instanceof Blob) {
            fd.append(f.field || 'file', f.blob, f.name || 'fichier.bin');
        }
    });

    return fd;
}

async function replayGenericHttpRequest(item) {
    const csrf = getCsrfToken();
    const upperMethod = String(item.method || 'POST').toUpperCase();
    const hasFiles = Array.isArray(item.files) && item.files.length > 0;

    if (hasFiles) {
        const fd = buildGenericFormData(item.payload, item.files, upperMethod);
        return fetch(item.url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'X-Offline-Replay': '1',
                'X-Client-Uuid': item.client_uuid,
            },
            credentials: 'same-origin',
            body: fd,
        });
    }

    return fetch(item.url, {
        method: upperMethod,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'X-Offline-Replay': '1',
            'X-Client-Uuid': item.client_uuid,
        },
        credentials: 'same-origin',
        body: JSON.stringify(item.payload || {}),
    });
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

            try {
                // Cas A : Requête universelle interceptée depuis n'importe quel module pro
                if (item.entity === 'http_request' && item.url) {
                    const res = await replayGenericHttpRequest(item);

                    if (res.status === 419) {
                        await pingServer();
                        item.status = 'pending';
                        item.error = 'Jeton CSRF rafraîchi, réessai automatique.';
                        await updateQueueItem(item);
                        failedCount++;
                        continue;
                    }

                    // En mode Laravel/Inertia, une redirection 302/303 suivie en 200 ou un JSON 2xx = succès
                    if (!res.ok && res.status !== 302 && res.status !== 303) {
                        const errJson = await res.json().catch(() => ({}));
                        const firstErr = errJson?.errors ? Object.values(errJson.errors).flat()[0] : null;
                        item.status = 'failed';
                        item.error = firstErr || errJson?.message || `Erreur serveur (${res.status})`;
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

                    await addSyncLog({
                        tenant_id: Number(activeTenantId.value),
                        level: 'info',
                        title: `Synchronisé : ${item.label}`,
                        detail: 'Rejoué avec succès (WhatsApp/e-mails déclenchés si applicables).',
                        created_at: new Date().toISOString(),
                    });
                    await deleteQueueItem(item.id);
                    syncedCount++;
                    continue;
                }

                // Cas B : Opération structurée via /offline/sync (résolution IDs temporaires + détection conflit)
                const resolvedPayload = resolveTempIdsInPayload(item.payload || {}, tempIdMap);
                let recordId = item.record_id;
                if (typeof recordId === 'string' && tempIdMap[recordId]) {
                    recordId = tempIdMap[recordId];
                }

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

/**
 * Suggestion bonus #3 : Export JSON de secours des saisies en attente (clé USB / sauvegarde locale).
 */
async function exportQueueBackup() {
    if (!activeTenantId.value) return;
    const items = await getQueueItemsForTenant(activeTenantId.value);
    const exportPayload = {
        app: 'MboaCar',
        exported_at: new Date().toISOString(),
        tenant_id: activeTenantId.value,
        lease_expires_at: leaseExpiresAt.value,
        count: items.length,
        items: items.map((i) => ({
            client_uuid: i.client_uuid,
            entity: i.entity,
            action: i.action,
            method: i.method || null,
            url: i.url || null,
            label: i.label,
            status: i.status,
            error: i.error,
            created_at: i.created_at,
            payload: i.payload,
            files_count: (i.files || []).length,
        })),
    };

    const blob = new Blob([JSON.stringify(exportPayload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `mboacar-secours-hors-ligne-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

async function installPwa() {
    if (!deferredInstallPrompt.value) return false;
    deferredInstallPrompt.value.prompt();
    const choice = await deferredInstallPrompt.value.userChoice;
    deferredInstallPrompt.value = null;
    return choice?.outcome === 'accepted';
}

function humanizeOfflineRouteLabel(method, path) {
    const clean = String(path || '').split('?')[0];
    if (clean.includes('/ordres-reparation')) return 'Ordre de réparation';
    if (clean.includes('/devis')) return 'Devis client';
    if (clean.includes('/factures')) return 'Facturation / Paiement';
    if (clean.includes('/receptions')) return 'Réception véhicule';
    if (clean.includes('/rendez-vous')) return 'Rendez-vous';
    if (clean.includes('/stock')) return 'Stock & Pièces';
    if (clean.includes('/approvisionnement')) return 'Approvisionnement';
    if (clean.includes('/inspections')) return 'Inspection DVI';
    if (clean.includes('/sous-traitance')) return 'Sous-traitance';
    if (clean.includes('/outillage')) return 'Outillage & Consommables';
    if (clean.includes('/planning')) return 'Planning Atelier';
    if (clean.includes('/flottes')) return 'Flotte B2B';
    if (clean.includes('/comptabilite')) return 'Comptabilité';
    if (clean.includes('/rh')) return 'RH / Pointage';
    if (clean.includes('/apres-vente')) return 'Après-vente';
    if (clean.includes('/messagerie')) return 'Messagerie / Notification';
    if (clean.includes('/agences')) return 'Agence';
    if (clean.includes('/professionnels')) return 'Professionnel / Équipe';
    if (clean.includes('/whatsapp') || clean.includes('/rappeler') || clean.includes('/relancer')) {
        return 'Message WhatsApp / Rappel différé';
    }
    return `Action ${String(method || 'POST').toUpperCase()} (${clean})`;
}

function initOfflineSync(tenantId) {
    if (tenantId) {
        activeTenantId.value = Number(tenantId);
        refreshQueueAndLogs();
        loadLeaseAndCheckClock();
        if (isOnline.value) {
            pingServer().then((ok) => {
                if (ok) refreshBootstrapCache();
            });
        }
    }

    if (initialized || typeof window === 'undefined') {
        return;
    }
    initialized = true;

    // Intercepteur universel Inertia : capture TOUTES les écritures hors ligne (sauf IA Auto & paiements)
    router.on('before', (event) => {
        const visit = event.detail.visit;
        const method = String(visit.method || 'get').toLowerCase();
        if (method === 'get' || isOnline.value || !activeTenantId.value) {
            return;
        }

        const targetUrl = visit.url?.pathname
            ? `${visit.url.pathname}${visit.url.search || ''}`
            : String(visit.url || '');

        if (targetUrl.startsWith('/assistant-ia')) {
            event.preventDefault();
            showNotice("L'Assistant IA Auto nécessite une connexion Internet pour répondre.", 'danger');
            return false;
        }

        if (isOnlineOnlyUrl(targetUrl)) {
            event.preventDefault();
            showNotice('Cette opération sécurisée (paiement / abonnement / session) nécessite une connexion Internet.', 'danger');
            return false;
        }

        // Bloquer l'écriture et basculer dans la file hors ligne
        event.preventDefault();
        const { payload, files } = extractFilesAndData(visit.data || {});
        const label = humanizeOfflineRouteLabel(method, targetUrl);

        enqueueOperation({
            entity: 'http_request',
            action: method,
            url: targetUrl,
            method: method.toUpperCase(),
            label,
            payload,
            files,
        }).then((res) => {
            if (res?.queued) {
                closeOpenModals();
            }
        });

        return false;
    });

    window.addEventListener('online', async () => {
        isOnline.value = true;
        await syncNow();
    });

    window.addEventListener('offline', () => {
        isOnline.value = false;
        loadLeaseAndCheckClock();
        showNotice('Connexion Internet coupée — Mode Hors Ligne activé (bail 14 jours).', 'warning');
    });

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt.value = event;
    });

    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt.value = null;
        showNotice('MboaCar a été installé sur votre appareil !', 'success');
    });

    pingIntervalId = setInterval(async () => {
        leaseNowTick.value = Date.now();
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
        leaseExpiresAt,
        leaseDaysRemaining,
        leaseWarning,
        leaseExpired,
        clockTampered,
        initOfflineSync,
        precacheMenuPages,
        enqueueOperation,
        syncNow,
        removeQueueItem,
        clearLogs,
        exportQueueBackup,
        installPwa,
        pendingItemsForEntity,
        getSnapshot: (entity) => (activeTenantId.value ? getSnapshot(activeTenantId.value, entity) : Promise.resolve(null)),
    };
}
