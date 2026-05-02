/**
 * Me tab: comment push preference + in-app permission flow (no prompt until user intent).
 */
(function () {
    var root = document.getElementById('me-notifications-root');
    if (!root) {
        return;
    }

    var csrf = root.getAttribute('data-csrf') || '';
    var input = document.getElementById('me-push-comment-replies');
    var preDialog = document.getElementById('me-push-prepermission-dialog');
    var preEnable = document.getElementById('me-push-prepermission-enable');
    var preDismiss = document.getElementById('me-push-prepermission-dismiss');
    var statusEl = document.getElementById('me-push-status');

    if (!input || !preDialog || !preEnable || !preDismiss) {
        return;
    }

    var previousChecked = input.checked;

    function setStatus(msg) {
        if (statusEl) {
            statusEl.textContent = msg || '';
        }
    }

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

    function savePref(enabled) {
        return fetch('/me_notification_prefs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ push_comment_replies_enabled: enabled, csrf_token: csrf }),
        }).then(function (r) {
            if (!r.ok) {
                throw new Error('save');
            }
            return r.json();
        });
    }

    function fetchVapidKey() {
        return fetch('/push/vapid-public-key.php', { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) {
                throw new Error('vapid');
            }
            return r.json();
        });
    }

    function postSubscribe(subscription) {
        var j = subscription.toJSON();
        var body = {
            endpoint: j.endpoint,
            keys: j.keys,
            expirationTime: j.expirationTime,
            csrf_token: csrf,
        };
        return fetch('/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(function (r) {
            if (!r.ok) {
                throw new Error('subscribe');
            }
            return r.json();
        });
    }

    function postUnsubscribe(endpoint) {
        return fetch('/push/unsubscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: endpoint, csrf_token: csrf }),
        });
    }

    function registerSw() {
        if (!('serviceWorker' in navigator)) {
            return Promise.reject(new Error('no sw'));
        }
        return navigator.serviceWorker.register('/service-worker.js', { scope: '/' });
    }

    function runSubscribeFlow() {
        if (!('Notification' in window) || !('PushManager' in window)) {
            setStatus('Your browser does not support push notifications here.');
            return Promise.resolve();
        }
        return registerSw()
            .then(function (reg) {
                return fetchVapidKey().then(function (data) {
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
                return postSubscribe(sub);
            })
            .then(function () {
                setStatus('Notifications are set up on this device.');
            })
            .catch(function () {
                setStatus('Could not enable push on this device. You can try again from this page later.');
            });
    }

    input.addEventListener('change', function () {
        var want = input.checked;
        if (want === previousChecked) {
            return;
        }

        if (!want) {
            savePref(false)
                .then(function () {
                    previousChecked = false;
                    if (!('serviceWorker' in navigator)) {
                        return;
                    }
                    return navigator.serviceWorker.ready
                        .then(function (reg) {
                            return reg.pushManager.getSubscription();
                        })
                        .then(function (sub) {
                            if (!sub) {
                                return;
                            }
                            var ep = sub.endpoint;
                            return sub.unsubscribe().then(function () {
                                return postUnsubscribe(ep);
                            });
                        });
                })
                .then(function () {
                    setStatus('Comment notifications are off. We removed this device from your push list.');
                })
                .catch(function () {
                    input.checked = true;
                    previousChecked = true;
                    setStatus('Could not update your preference. Please try again.');
                });
            return;
        }

        savePref(true)
            .then(function () {
                previousChecked = true;
                if (Notification.permission === 'granted') {
                    return runSubscribeFlow();
                }
                if (Notification.permission === 'denied') {
                    setStatus(
                        'Notifications are turned off for this device. You can enable them anytime in your browser settings.'
                    );
                    return;
                }
                if (!preDialog.showModal) {
                    return runSubscribeFlow();
                }
                preDialog.showModal();
            })
            .catch(function () {
                input.checked = false;
                previousChecked = false;
                setStatus('Could not update your preference. Please try again.');
            });
    });

    preEnable.addEventListener('click', function () {
        preDialog.close();
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    runSubscribeFlow();
                } else {
                    setStatus(
                        'Notifications are turned off for this device. You can enable them anytime in your browser settings.'
                    );
                }
            });
        } else if (Notification.permission === 'granted') {
            runSubscribeFlow();
        } else {
            setStatus(
                'Notifications are turned off for this device. You can enable them anytime in your browser settings.'
            );
        }
    });

    preDismiss.addEventListener('click', function () {
        preDialog.close();
        setStatus(
            'You can turn on browser notifications anytime using the toggle above when you are ready.'
        );
    });
})();
