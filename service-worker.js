/* global self, clients */
/**
 * Minimal push + notification click handling (no caching changes).
 */
self.addEventListener('push', function (event) {
    var title = 'Gratitude';
    var body = '';
    var url = '/';
    if (event.data) {
        try {
            var payload = event.data.json();
            if (payload && typeof payload === 'object') {
                if (payload.title) {
                    title = String(payload.title);
                }
                if (payload.body) {
                    body = String(payload.body);
                }
                if (payload.url) {
                    url = String(payload.url);
                }
            }
        } catch (e) {
            try {
                body = event.data.text();
            } catch (e2) {}
        }
    }
    event.waitUntil(
        self.registration.showNotification(title, {
            body: body,
            data: { url: url },
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var raw = event.notification.data;
    var url = '/';
    if (raw && typeof raw === 'object' && raw.url) {
        url = String(raw.url);
    }
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            for (var i = 0; i < windowClients.length; i++) {
                var c = windowClients[i];
                if (c.url === url && 'focus' in c) {
                    return c.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
