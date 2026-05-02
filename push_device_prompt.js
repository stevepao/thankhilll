/**
 * One-time prompt when comment notifications are enabled account-wide but this device has no subscription.
 */
(function () {
    var bootEl = document.getElementById('thankhill-push-device-bootstrap');
    if (!bootEl) {
        return;
    }

    var boot = {};
    try {
        boot = JSON.parse(bootEl.textContent || '{}');
    } catch (e) {
        return;
    }

    if (!boot.pushCommentReplies) {
        return;
    }

    var lsKey = 'thankhill_push_device_prompt_dismissed_v1';
    try {
        if (localStorage.getItem(lsKey) === '1') {
            return;
        }
    } catch (e) {
        return;
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        return;
    }

    var csrf = boot.csrf || '';

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function runSubscribe() {
        return navigator.serviceWorker
            .register('/service-worker.js', { scope: '/' })
            .then(function (reg) {
                return fetch('/push/vapid-public-key.php', { credentials: 'same-origin' }).then(function (r) {
                    if (!r.ok) {
                        throw new Error('vapid');
                    }
                    return r.json();
                }).then(function (data) {
                    var key = data.publicKey;
                    if (!key) {
                        throw new Error('vapid');
                    }
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(key),
                    });
                });
            })
            .then(function (sub) {
                var j = sub.toJSON();
                return fetch('/push/subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        endpoint: j.endpoint,
                        keys: j.keys,
                        expirationTime: j.expirationTime,
                        csrf_token: csrf,
                    }),
                });
            });
    }

    function maybeOpen() {
        if (window.location.pathname.indexOf('/me.php') !== -1) {
            return;
        }
        navigator.serviceWorker.ready
            .then(function (reg) {
                return reg.pushManager.getSubscription();
            })
            .then(function (sub) {
                if (sub) {
                    return;
                }
                var dlg = document.getElementById('push-device-prompt-dialog');
                var btnEn = document.getElementById('push-device-prompt-enable');
                var btnNo = document.getElementById('push-device-prompt-dismiss');
                if (!dlg || !btnEn || !btnNo || !dlg.showModal) {
                    return;
                }
                dlg.showModal();
                btnNo.onclick = function () {
                    try {
                        localStorage.setItem(lsKey, '1');
                    } catch (e) {}
                    dlg.close();
                };
                btnEn.onclick = function () {
                    if (Notification.permission === 'denied') {
                        try {
                            localStorage.setItem(lsKey, '1');
                        } catch (e) {}
                        dlg.close();
                        return;
                    }
                    if (Notification.permission === 'default') {
                        Notification.requestPermission().then(function (perm) {
                            if (perm !== 'granted') {
                                try {
                                    localStorage.setItem(lsKey, '1');
                                } catch (e) {}
                                dlg.close();
                                return;
                            }
                            runSubscribe()
                                .then(function () {
                                    try {
                                        localStorage.setItem(lsKey, '1');
                                    } catch (e) {}
                                    dlg.close();
                                })
                                .catch(function () {
                                    dlg.close();
                                });
                        });
                    } else if (Notification.permission === 'granted') {
                        runSubscribe()
                            .then(function () {
                                try {
                                    localStorage.setItem(lsKey, '1');
                                } catch (e) {}
                                dlg.close();
                            })
                            .catch(function () {
                                dlg.close();
                            });
                    }
                };
            })
            .catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeOpen);
    } else {
        maybeOpen();
    }
})();
