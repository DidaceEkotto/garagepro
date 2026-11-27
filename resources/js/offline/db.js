const DB_NAME = 'mboacar_offline_v1';
const DB_VERSION = 2;

let dbPromise = null;

function openDb() {
    if (typeof indexedDB === 'undefined') {
        return Promise.resolve(null);
    }

    if (dbPromise) {
        return dbPromise;
    }

    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains('queue')) {
                const queueStore = db.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
                queueStore.createIndex('tenant_id', 'tenant_id', { unique: false });
                queueStore.createIndex('status', 'status', { unique: false });
            }

            if (!db.objectStoreNames.contains('snapshots')) {
                db.createObjectStore('snapshots', { keyPath: 'key' });
            }

            if (!db.objectStoreNames.contains('logs')) {
                const logStore = db.createObjectStore('logs', { keyPath: 'id', autoIncrement: true });
                logStore.createIndex('tenant_id', 'tenant_id', { unique: false });
            }

            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return dbPromise;
}

async function withStore(storeName, mode, callback) {
    const db = await openDb();
    if (!db) return null;

    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, mode);
        const store = tx.objectStore(storeName);
        let result;

        try {
            result = callback(store);
        } catch (err) {
            reject(err);
            return;
        }

        tx.oncomplete = () => resolve(result?.result ?? result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

export async function addQueueItem(item) {
    return withStore('queue', 'readwrite', (store) => store.add(item));
}

export async function updateQueueItem(item) {
    return withStore('queue', 'readwrite', (store) => store.put(item));
}

export async function deleteQueueItem(id) {
    return withStore('queue', 'readwrite', (store) => store.delete(id));
}

export async function getQueueItemsForTenant(tenantId) {
    const db = await openDb();
    if (!db) return [];

    return new Promise((resolve, reject) => {
        const tx = db.transaction('queue', 'readonly');
        const store = tx.objectStore('queue');
        const index = store.index('tenant_id');
        const req = index.getAll(Number(tenantId));

        req.onsuccess = () => {
            const rows = Array.isArray(req.result) ? req.result : [];
            rows.sort((a, b) => (a.id ?? 0) - (b.id ?? 0));
            resolve(rows);
        };
        req.onerror = () => reject(req.error);
    });
}

export async function saveSnapshot(tenantId, entity, data) {
    return withStore('snapshots', 'readwrite', (store) =>
        store.put({
            key: `${tenantId}:${entity}`,
            tenant_id: Number(tenantId),
            entity,
            data,
            updated_at: new Date().toISOString(),
        })
    );
}

export async function getSnapshot(tenantId, entity) {
    const row = await withStore('snapshots', 'readonly', (store) =>
        store.get(`${tenantId}:${entity}`)
    );
    return row?.data ?? null;
}

export async function addSyncLog(entry) {
    return withStore('logs', 'readwrite', (store) => store.add(entry));
}

export async function getSyncLogsForTenant(tenantId, limit = 40) {
    const db = await openDb();
    if (!db) return [];

    return new Promise((resolve, reject) => {
        const tx = db.transaction('logs', 'readonly');
        const store = tx.objectStore('logs');
        const index = store.index('tenant_id');
        const req = index.getAll(Number(tenantId));

        req.onsuccess = () => {
            const rows = Array.isArray(req.result) ? req.result : [];
            rows.sort((a, b) => (b.id ?? 0) - (a.id ?? 0));
            resolve(rows.slice(0, limit));
        };
        req.onerror = () => reject(req.error);
    });
}

export async function clearSyncLogsForTenant(tenantId) {
    const logs = await getSyncLogsForTenant(tenantId, 200);
    for (const log of logs) {
        if (log.id != null) {
            await withStore('logs', 'readwrite', (store) => store.delete(log.id));
        }
    }
}

export async function setOfflineMeta(key, value) {
    return withStore('meta', 'readwrite', (store) =>
        store.put({
            key,
            value,
            updated_at: new Date().toISOString(),
        })
    );
}

export async function getOfflineMeta(key) {
    const row = await withStore('meta', 'readonly', (store) => store.get(key));
    return row?.value ?? null;
}
