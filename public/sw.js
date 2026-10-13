const VERSION = '2';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(`app-v${VERSION}`).then((cache) => cache.addAll(['/offline.html'])));
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((key) => key.startsWith('app-v') && key !== `app-v${VERSION}`).map((key) => caches.delete(key)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') {
        self.skipWaiting();
    }
});

function workspaceSlug(url) {
    const match = new URL(url).pathname.match(/^\/w\/([^/]+)/);
    return match ? match[1] : null;
}

function isHashedAsset(url) {
    return url.pathname.startsWith('/build/');
}

function isPosRead(url) {
    return /\/pos\/lookup$/.test(url.pathname)
        || /\/api\/v1\/(items|customers|stock)$/.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                return await fetch(request);
            } catch {
                const slug = workspaceSlug(request.url);
                if (slug) {
                    const scoped = await caches.match(`/w/${slug}/offline`);
                    if (scoped) {
                        return scoped;
                    }
                }
                return (await caches.match('/offline.html')) || Response.error();
            }
        })());
        return;
    }

    if (isHashedAsset(url)) {
        event.respondWith((async () => {
            const cache = await caches.open(`app-v${VERSION}`);
            const cached = await cache.match(request);
            if (cached) {
                return cached;
            }
            const response = await fetch(request);
            if (response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        })());
        return;
    }

    const slug = workspaceSlug(request.url);
    if (isPosRead(url) && slug) {
        event.respondWith((async () => {
            const cache = await caches.open(`ws-${slug}-data`);
            try {
                const response = await fetch(request);
                if (response.ok) {
                    cache.put(request, response.clone());
                }
                return response;
            } catch {
                return (await cache.match(request)) || Response.error();
            }
        })());
    }
});
