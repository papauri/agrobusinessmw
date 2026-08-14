// AgroBusiness legacy service worker shutdown stub.
// It performs no fetch interception and unregisters itself so an old PWA worker
// cannot interfere with other applications sharing the same origin.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        try {
            await self.registration.unregister();
        } finally {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            clients.forEach(client => client.postMessage({ type: 'AGRO_SW_DISABLED' }));
        }
    })());
});

// Intentionally no fetch handler. Requests pass through to the network.
