/*
 * Service worker — penny-track
 *
 * WHAT THIS IS FOR (GUIDING-LIGHT §3.3b): a manifest with `display: standalone`
 * plus `start_url` gets the app *installable*; a service worker is the piece
 * that makes the install actually behave like an app once it is on the home
 * screen. Without one, iOS Safari shows the "Add to Home Screen" affordance but
 * every launch still goes to the network and a dropped connection is a blank
 * page.
 *
 * WHAT IT DELIBERATELY DOES NOT DO — cache the app's own pages.
 *
 * penny-track's HTML is authenticated: the pages call the API with a key kept
 * in localStorage, and a cached page can outlive the key that produced it. So
 * the HTML is never served from cache. The fetch handler below is selective and
 * network-first for anything that is not a build-hashed asset.
 *
 * Offline *writes* are a separate, larger piece of work (a depth-1 IndexedDB
 * queue, GUIDING-LIGHT §3.3d) and are NOT implemented here. This worker does not
 * pretend to queue anything: if the API call fails while offline the UI says so.
 */

// Bump to evict everything below on the next activation.
const CACHE_NAME = 'penny-track-static-v1';

/*
 * Only immutable, build-hashed output is precached. AssetMapper fingerprints
 * these filenames (…/tailwind-<hash>.css), so a new build produces a new URL
 * and the cache entry can never be stale — which is exactly why these are safe
 * to serve cache-first while the HTML is not.
 *
 * The paths cannot be hardcoded: the hash is not known at authoring time. The
 * worker therefore populates its cache on demand, from the actual requests the
 * page makes, instead of precaching a list.
 */
const CACHEABLE_DESTINATIONS = ['style', 'script', 'font', 'image'];

self.addEventListener('install', () => {
    // Take over immediately rather than waiting for every tab to be closed.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only GET is cacheable, and only same-origin responses are ours to manage.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Never touch the API or the auth flow: a stale auth response is exactly the
    // failure mode §3.3d warns about, and the API is the one thing whose
    // freshness the app depends on.
    if (url.pathname.startsWith('/api/')) return;

    // Navigations and documents are always network-only. See the header.
    if (request.mode === 'navigate' || request.destination === 'document') {
        return;
    }

    if (!CACHEABLE_DESTINATIONS.includes(request.destination)) {
        return;
    }

    // Cache-first for fingerprinted static output; refresh the entry in the
    // background so a long-lived install picks up new builds.
    event.respondWith(
        caches.open(CACHE_NAME).then(async (cache) => {
            const cached = await cache.match(request);
            const network = fetch(request).then((response) => {
                if (response && response.ok) {
                    cache.put(request, response.clone());
                }
                return response;
            }).catch(() => cached);

            return cached || network;
        }),
    );
});
