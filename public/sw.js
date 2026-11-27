const CACHE_VERSION = 'mboacar-pwa-v2';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const PAGE_CACHE = `${CACHE_VERSION}-pages`;

const NO_CACHE_PREFIXES = [
    '/admin',
    '/mon-espace-client',
    '/devis-public/',
    '/suivi/',
    '/carnet/',
    '/pv-visite/',
    '/connexion',
    '/deconnexion',
    '/offline/',
];

function isSensitiveNoCachePath(pathname) {
    return NO_CACHE_PREFIXES.some((p) => pathname === p || pathname.startsWith(p));
}

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CLEAR_AUTH_CACHES') {
        event.waitUntil(caches.delete(PAGE_CACHE).catch(() => {}));
    }
});

const PRECACHE_URLS = [
    '/manifest.webmanifest',
    '/assets/images/logo.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => !k.startsWith(CACHE_VERSION))
                        .map((k) => caches.delete(k))
                )
            )
            .then(() => self.clients.claim())
    );
});

function isStaticAsset(url) {
    return (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/assets/') ||
        /\.(?:css|js|woff2?|ttf|png|jpg|jpeg|webp|svg|ico)$/i.test(url.pathname)
    );
}

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Ne jamais intercepter les requêtes d'écriture ou le ping réseau
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);
    if (isSensitiveNoCachePath(url.pathname)) {
        return;
    }

    // 1. Assets statiques (Vite /build, images, polices) : Cache First + refresh en arrière-plan
    if (url.origin === self.location.origin && isStaticAsset(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cached = await cache.match(req);
                const fetchPromise = fetch(req)
                    .then((res) => {
                        if (res && res.status === 200) {
                            cache.put(req, res.clone()).catch(() => {});
                        }
                        return res;
                    })
                    .catch(() => cached);

                return cached || fetchPromise;
            })
        );
        return;
    }

    // 2. Navigations HTML & requêtes GET Inertia (same-origin) : Network First avec repli Cache
    if (url.origin === self.location.origin) {
        const isInertia = req.headers.get('X-Inertia') === 'true';
        const isNavigation = req.mode === 'navigate';

        if (isNavigation || isInertia) {
            event.respondWith(
                fetch(req)
                    .then((res) => {
                        if (res && res.status === 200) {
                            const cacheKey = isInertia ? `${url.href}::inertia` : req;
                            caches.open(PAGE_CACHE).then((cache) => {
                                cache.put(cacheKey, res.clone()).catch(() => {});
                            });
                        }
                        return res;
                    })
                    .catch(async () => {
                        const cache = await caches.open(PAGE_CACHE);
                        const cacheKey = isInertia ? `${url.href}::inertia` : req;
                        const cached = await cache.match(cacheKey);
                        if (cached) {
                            return cached;
                        }

                        if (isNavigation) {
                            return new Response(
                                `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MboaCar — Mode Hors Ligne</title><style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;text-align:center}.card{max-width:440px;background:#1e293b;border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:28px}h1{font-size:1.3rem;margin:0 0 10px}p{color:#cbd5e1;font-size:.92rem;line-height:1.5}button{margin-top:16px;padding:10px 18px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}</style></head><body><div class="card"><h1>Connexion Internet interrompue</h1><p>Cette page n'a pas encore été mise en cache. Revenez à la page précédente ou réessayez dès que le réseau revient.</p><button onclick="history.back()">Page précédente</button> <button onclick="location.reload()">Réessayer</button></div></body></html>`,
                                { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                            );
                        }

                        return new Response(JSON.stringify({ offline: true }), {
                            status: 503,
                            headers: { 'Content-Type': 'application/json' },
                        });
                    })
            );
        }
    }
});
