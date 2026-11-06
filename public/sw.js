// Bluestar service worker. It exists so browsers treat the app as installable.
// It deliberately does NOT cache anything: balances and transfers must always
// come live from the server, and there is no offline mode.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    // Drop any cache a previous version might have created.
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

// An empty fetch handler leaves every request to the network.
self.addEventListener('fetch', () => {});
