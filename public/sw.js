/**
 * Life OS service worker.
 *
 * Scope, deliberately: make the app *installable* and keep the static app
 * shell (compiled CSS/JS, icons, manifest) available offline, with a small
 * offline fallback page for navigations. It does NOT cache authenticated
 * HTML pages or any API/financial response, and it never intercepts
 * non-GET requests — financial writes always go straight to the network
 * and fail visibly if there isn't one (CLAUDE.md §11: this is not an
 * offline-capable financial app).
 *
 * Update strategy: bump CACHE_VERSION on deploy. The new worker installs
 * and precaches under a new cache name, then waits — it only takes over
 * (skipWaiting) when the page explicitly asks it to via postMessage, which
 * resources/js/pwa.js does after showing an "update available" prompt.
 * On activate, every cache from a previous version is deleted.
 */

const CACHE_VERSION = 'v1';
const SHELL_CACHE = `lifeos-shell-${CACHE_VERSION}`;

const PRECACHE_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

// Same-origin, cacheable-at-runtime paths: compiled build assets and icons.
// Everything else (pages, Livewire's own POST requests, API calls) is
// intentionally left untouched and always goes to the network.
const RUNTIME_CACHEABLE_PREFIXES = ['/build/', '/icons/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key.startsWith('lifeos-shell-') && key !== SHELL_CACHE)
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Never intercept anything but plain GETs. All writes (financial or
    // otherwise) must go straight to the network.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Page navigations: network-first, falling back to a cached shell page
    // (or the offline page) only when there's genuinely no connection.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(request).then((cached) => cached || caches.match('/offline.html'))
            )
        );
        return;
    }

    const isRuntimeCacheable = RUNTIME_CACHEABLE_PREFIXES.some((prefix) =>
        url.pathname.startsWith(prefix)
    );

    if (!isRuntimeCacheable) {
        return;
    }

    // Static build assets / icons: cache-first, populate on first use.
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(SHELL_CACHE).then((cache) => cache.put(request, clone));
                }

                return response;
            });
        })
    );
});
