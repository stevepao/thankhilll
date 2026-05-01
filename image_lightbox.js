/**
 * Opens note thumbnails in a modal dialog (view-only). Text stays in scroll flow.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('site-photo-lightbox');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var imgEl = dialog.querySelector('.photo-lightbox__img');
    var panel = dialog.querySelector('.photo-lightbox__panel');
    var closeBtn = dialog.querySelector('.photo-lightbox__close');
    if (!imgEl || !panel) {
        return;
    }

    var lastTrigger = null;

    document.body.addEventListener('click', function (e) {
        var trigger = e.target.closest('.photo-lightbox-trigger');
        if (!trigger || dialog.contains(trigger)) {
            return;
        }
        var thumbImg = trigger.querySelector('img[src]');
        var src = thumbImg && thumbImg.getAttribute('src');
        if (!src) {
            return;
        }
        e.preventDefault();
        lastTrigger = trigger;
        imgEl.src = src;
        imgEl.alt = thumbImg.getAttribute('alt') || '';
        dialog.showModal();
    });

    function focusLastTrigger() {
        var t = lastTrigger;
        lastTrigger = null;
        if (t && typeof t.focus === 'function') {
            try {
                t.focus();
            } catch (_) {}
        }
    }

    dialog.addEventListener('close', function () {
        imgEl.removeAttribute('src');
        imgEl.removeAttribute('alt');
        focusLastTrigger();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            dialog.close();
        });
    }

    panel.addEventListener('click', function (e) {
        if (e.target === panel) {
            dialog.close();
        }
    });
})();
