/**
 * Me tab: gratitude reminder + comment push preferences and permission flows.
 */
(function () {
    var STORAGE_CROSS_DEVICE_DISMISSED = 'thankhill_me_gratitude_cross_device_dismissed';

    var root = document.getElementById('me-notifications-root');
    if (!root) {
        return;
    }

    var csrf = root.getAttribute('data-csrf') || '';
    var inputGratitude = document.getElementById('me-push-gratitude-reminder');
    var inputComments = document.getElementById('me-push-comment-replies');
    var statusEl = document.getElementById('me-push-status');

    var gratitudeDialog = document.getElementById('me-gratitude-prepermission-dialog');
    var gratitudeEnable = document.getElementById('me-gratitude-prepermission-enable');
    var gratitudeDismiss = document.getElementById('me-gratitude-prepermission-dismiss');

    var commentDialog = document.getElementById('me-push-prepermission-dialog');
    var commentEnable = document.getElementById('me-push-prepermission-enable');
    var commentDismiss = document.getElementById('me-push-prepermission-dismiss');

    var crossDeviceDialog = document.getElementById('me-cross-device-push-dialog');
    var crossDeviceEnable = document.getElementById('me-cross-device-enable');
    var crossDeviceDismiss = document.getElementById('me-cross-device-dismiss');

    if (
        !inputGratitude ||
        !inputComments ||
        !gratitudeDialog ||
        !gratitudeEnable ||
        !gratitudeDismiss ||
        !commentDialog ||
        !commentEnable ||
        !commentDismiss ||
        !crossDeviceDialog ||
        !crossDeviceEnable ||
        !crossDeviceDismiss
    ) {
        return;
    }

    var previousGratitude = inputGratitude.checked;
    var previousComments = inputComments.checked;

    function setStatus(msg) {
        if (statusEl) {
            statusEl.textContent = msg || '';
        }
    }

    function setGratitudeAccountAttr(on) {
        root.setAttribute('data-gratitude-reminder-account', on ? '1' : '0');
    }

    function saveNotifPrefs(patch) {
        var body = { csrf_token: csrf };
        if ('push_comment_replies_enabled' in patch) {
            body.push_comment_replies_enabled = patch.push_comment_replies_enabled;
        }
        if ('push_reminders_enabled' in patch) {
            body.push_reminders_enabled = patch.push_reminders_enabled;
        }
        return fetch('/me_notification_prefs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(function (r) {
            if (!r.ok) {
                throw new Error('save');
            }
            return r.json();
        });
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

    function getPushSubscription() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return Promise.resolve(null);
        }
        return navigator.serviceWorker.ready.then(function (reg) {
            return reg.pushManager.getSubscription();
        });
    }

    function permissionDeniedCalmMessage() {
        setStatus(
            'Notifications are turned off for this device. You can enable them anytime in your browser settings.'
        );
    }

    /** Cross-device one-time prompt (Part D): gratitude enabled account, no subscription on this browser. */
    function maybeShowCrossDevicePrompt() {
        if (root.getAttribute('data-gratitude-reminder-account') !== '1') {
            return;
        }
        try {
            if (localStorage.getItem(STORAGE_CROSS_DEVICE_DISMISSED) === '1') {
                return;
            }
        } catch (e) {
            return;
        }
        getPushSubscription().then(function (sub) {
            if (sub) {
                return;
            }
            if (!crossDeviceDialog.showModal) {
                return;
            }
            crossDeviceDialog.showModal();
        });
    }

    inputGratitude.addEventListener('change', function () {
        var want = inputGratitude.checked;
        if (want === previousGratitude) {
            return;
        }

        if (!want) {
            saveNotifPrefs({ push_reminders_enabled: false })
                .then(function () {
                    previousGratitude = false;
                    setGratitudeAccountAttr(false);
                    setStatus('');
                })
                .catch(function () {
                    inputGratitude.checked = true;
                    previousGratitude = true;
                    setStatus('Could not update your preference. Please try again.');
                });
            return;
        }

        saveNotifPrefs({ push_reminders_enabled: true })
            .then(function () {
                previousGratitude = true;
                setGratitudeAccountAttr(true);
                return getPushSubscription();
            })
            .then(function (sub) {
                if (sub) {
                    setStatus('Notifications are set up on this device.');
                    return;
                }
                if (!('Notification' in window)) {
                    setStatus('Your browser does not support notifications here.');
                    return;
                }
                if (Notification.permission === 'granted') {
                    return runSubscribeFlow();
                }
                if (Notification.permission === 'denied') {
                    permissionDeniedCalmMessage();
                    return;
                }
                if (!gratitudeDialog.showModal) {
                    return runSubscribeFlow();
                }
                gratitudeDialog.showModal();
            })
            .catch(function () {
                inputGratitude.checked = false;
                previousGratitude = false;
                setGratitudeAccountAttr(false);
                setStatus('Could not update your preference. Please try again.');
            });
    });

    gratitudeEnable.addEventListener('click', function () {
        gratitudeDialog.close();
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    runSubscribeFlow();
                } else {
                    permissionDeniedCalmMessage();
                }
            });
        } else if (Notification.permission === 'granted') {
            runSubscribeFlow();
        } else {
            permissionDeniedCalmMessage();
        }
    });

    gratitudeDismiss.addEventListener('click', function () {
        gratitudeDialog.close();
        setStatus('You can turn on browser notifications anytime using the toggle above when you are ready.');
    });

    inputComments.addEventListener('change', function () {
        var want = inputComments.checked;
        if (want === previousComments) {
            return;
        }

        if (!want) {
            saveNotifPrefs({ push_comment_replies_enabled: false })
                .then(function () {
                    previousComments = false;
                    if (inputGratitude.checked) {
                        setStatus('Comment notifications are off.');
                        return;
                    }
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
                    if (!inputGratitude.checked) {
                        setStatus('Comment notifications are off. We removed this device from your push list.');
                    }
                })
                .catch(function () {
                    inputComments.checked = true;
                    previousComments = true;
                    setStatus('Could not update your preference. Please try again.');
                });
            return;
        }

        saveNotifPrefs({ push_comment_replies_enabled: true })
            .then(function () {
                previousComments = true;
                if (Notification.permission === 'granted') {
                    return runSubscribeFlow();
                }
                if (Notification.permission === 'denied') {
                    permissionDeniedCalmMessage();
                    return;
                }
                if (!commentDialog.showModal) {
                    return runSubscribeFlow();
                }
                commentDialog.showModal();
            })
            .catch(function () {
                inputComments.checked = false;
                previousComments = false;
                setStatus('Could not update your preference. Please try again.');
            });
    });

    commentEnable.addEventListener('click', function () {
        commentDialog.close();
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    runSubscribeFlow();
                } else {
                    permissionDeniedCalmMessage();
                }
            });
        } else if (Notification.permission === 'granted') {
            runSubscribeFlow();
        } else {
            permissionDeniedCalmMessage();
        }
    });

    commentDismiss.addEventListener('click', function () {
        commentDialog.close();
        setStatus('You can turn on browser notifications anytime using the toggle above when you are ready.');
    });

    crossDeviceEnable.addEventListener('click', function () {
        crossDeviceDialog.close();
        if (!('Notification' in window)) {
            setStatus('Your browser does not support notifications here.');
            return;
        }
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    runSubscribeFlow();
                } else {
                    permissionDeniedCalmMessage();
                }
            });
        } else if (Notification.permission === 'granted') {
            runSubscribeFlow();
        } else {
            permissionDeniedCalmMessage();
        }
    });

    crossDeviceDismiss.addEventListener('click', function () {
        crossDeviceDialog.close();
        try {
            localStorage.setItem(STORAGE_CROSS_DEVICE_DISMISSED, '1');
        } catch (e) {}
    });

    maybeShowCrossDevicePrompt();
})();
