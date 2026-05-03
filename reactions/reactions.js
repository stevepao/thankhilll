(() => {
    /** emoji-picker-element sometimes omits top-level `unicode`; fall back to DB emoji record. */
    function unicodeFromEmojiClickDetail(detail) {
        if (!detail || typeof detail !== 'object') {
            return '';
        }
        if (typeof detail.unicode === 'string' && detail.unicode !== '') {
            return detail.unicode;
        }
        const fromEmoji = detail.emoji && typeof detail.emoji.unicode === 'string' ? detail.emoji.unicode : '';
        return fromEmoji !== '' ? fromEmoji : '';
    }

    function renderReactionButtons(listEl, thoughtId, reactions) {
        if (!listEl) {
            return;
        }
        listEl.innerHTML = '';
        reactions.forEach((r) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'thought-reaction-pill';
            if (r.reacted_by_me) {
                btn.classList.add('is-active');
            }
            btn.dataset.reactionToggle = '1';
            btn.dataset.thoughtId = String(thoughtId);
            btn.dataset.emoji = r.emoji;
            btn.setAttribute('aria-label', `Toggle reaction ${r.emoji}`);
            btn.textContent = `${r.emoji} ${r.count}`;
            listEl.appendChild(btn);
        });
    }

    function mountThoughtReactions(options) {
        const csrfToken = options.csrfToken || '';
        if (!csrfToken) {
            return;
        }

        if (window.__thankhillThoughtReactionsMounted) {
            return;
        }
        window.__thankhillThoughtReactionsMounted = true;

        /** Serialize async toggles per thought so responses apply in order (pairs with server-side row lock). */
        function createExclusiveRunner() {
            let tail = Promise.resolve();
            return function runExclusive(fn) {
                const run = tail.then(() => fn());
                tail = run.catch(() => {}).then(() => {});
                return run;
            };
        }
        const exclusiveByThoughtId = new Map();
        function runThoughtExclusive(thoughtId, fn) {
            let runner = exclusiveByThoughtId.get(thoughtId);
            if (!runner) {
                runner = createExclusiveRunner();
                exclusiveByThoughtId.set(thoughtId, runner);
            }
            return runner(fn);
        }

        let pickerMount = document.getElementById('thought-reaction-picker');
        if (!pickerMount) {
            pickerMount = document.createElement('div');
            pickerMount.id = 'thought-reaction-picker';
            pickerMount.className = 'thought-reaction-picker-wrap';
            pickerMount.hidden = true;
            document.body.appendChild(pickerMount);
        }
        let pickerHost = null;
        let activeThoughtId = 0;
        let activeContainer = null;

        pickerMount.addEventListener('click', (ev) => {
            ev.stopPropagation();
        });

        function clickEventInsidePicker(ev) {
            if (!pickerMount || pickerMount.hidden) {
                return false;
            }
            if (typeof ev.composedPath === 'function') {
                return ev.composedPath().includes(pickerMount);
            }
            return Boolean(ev.target?.closest?.('#thought-reaction-picker'));
        }

        function ensurePicker() {
            if (!pickerMount || pickerHost) {
                return;
            }
            pickerHost = document.createElement('emoji-picker');
            pickerHost.className = 'thought-reaction-picker';
            pickerMount.appendChild(pickerHost);
            pickerHost.addEventListener('emoji-click', (ev) => {
                const tid = activeThoughtId;
                const unicode = unicodeFromEmojiClickDetail(ev.detail);
                if (!unicode || tid <= 0) {
                    return;
                }
                sendToggle(tid, unicode);
            });
        }

        function hidePicker() {
            if (!pickerMount) {
                return;
            }
            pickerMount.hidden = true;
            if (pickerHost) {
                pickerHost.style.maxHeight = '';
            }
            activeThoughtId = 0;
            activeContainer = null;
        }

        function showPickerFor(thoughtId, anchorEl) {
            if (!pickerMount || !anchorEl) {
                return;
            }
            ensurePicker();
            activeThoughtId = thoughtId;
            activeContainer = anchorEl.closest('[data-thought-reactions]');

            const gap = 6;
            const pad = 8;

            function layoutPicker() {
                const rect = anchorEl.getBoundingClientRect();
                const vw = window.innerWidth;
                const vh = window.innerHeight;
                if (pickerHost) {
                    pickerHost.style.maxHeight = '';
                }

                const pr = pickerMount.getBoundingClientRect();
                const h = pr.height;
                const w = pr.width;

                const spaceBelow = vh - pad - rect.bottom - gap;
                const spaceAbove = rect.top - gap - pad;

                let top;
                if (h <= spaceBelow) {
                    top = rect.bottom + gap;
                } else if (h <= spaceAbove) {
                    top = rect.top - h - gap;
                } else {
                    const preferBelow = spaceBelow >= spaceAbove;
                    const cap = preferBelow ? spaceBelow : spaceAbove;
                    if (pickerHost) {
                        pickerHost.style.maxHeight = `${Math.max(0, cap)}px`;
                    }
                    const h2 = pickerMount.getBoundingClientRect().height;
                    if (preferBelow) {
                        top = rect.bottom + gap;
                    } else {
                        top = rect.top - h2 - gap;
                    }
                }

                let left = rect.left;
                if (left + w > vw - pad) {
                    left = Math.max(pad, vw - pad - w);
                }
                if (left < pad) {
                    left = pad;
                }

                pickerMount.style.top = `${top}px`;
                pickerMount.style.left = `${left}px`;

                const pr2 = pickerMount.getBoundingClientRect();
                let adjTop = top;
                if (pr2.bottom > vh - pad) {
                    adjTop -= pr2.bottom - (vh - pad);
                }
                if (pr2.top < pad) {
                    adjTop += pad - pr2.top;
                }
                if (adjTop !== top) {
                    pickerMount.style.top = `${adjTop}px`;
                }

                const pr3 = pickerMount.getBoundingClientRect();
                let adjLeft = left;
                if (pr3.right > vw - pad) {
                    adjLeft -= pr3.right - (vw - pad);
                }
                if (pr3.left < pad) {
                    adjLeft += pad - pr3.left;
                }
                if (adjLeft !== left) {
                    pickerMount.style.left = `${adjLeft}px`;
                }
            }

            const rect0 = anchorEl.getBoundingClientRect();
            pickerMount.style.top = `${rect0.bottom + gap}px`;
            pickerMount.style.left = `${rect0.left}px`;
            if (pickerHost) {
                pickerHost.style.maxHeight = '';
            }
            pickerMount.hidden = false;

            requestAnimationFrame(() => {
                requestAnimationFrame(layoutPicker);
            });
        }

        function sendToggle(thoughtId, emoji) {
            return runThoughtExclusive(thoughtId, async () => {
                try {
                    const body = new URLSearchParams();
                    body.set('csrf_token', csrfToken);
                    body.set('thought_id', String(thoughtId));
                    body.set('emoji', emoji);

                    const res = await fetch('/reactions/toggle.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        },
                        body: body.toString(),
                        credentials: 'same-origin',
                    });

                    const data = await res.json();
                    if (!res.ok || !data.ok) {
                        throw new Error(data.error || 'Could not update reaction.');
                    }

                    const container =
                        activeContainer ||
                        document.querySelector(`[data-thought-reactions][data-thought-id="${thoughtId}"]`);
                    if (!container) {
                        hidePicker();
                        return;
                    }
                    const list = container.querySelector('[data-reaction-list]');
                    renderReactionButtons(list, thoughtId, data.reactions || []);
                    hidePicker();
                } catch (err) {
                    console.error(err);
                }
            });
        }

        document.body.addEventListener('click', (ev) => {
            const addBtn = ev.target.closest('[data-reaction-add]');
            if (addBtn) {
                ev.preventDefault();
                const thoughtId = Number(addBtn.dataset.thoughtId || '0');
                if (thoughtId > 0) {
                    showPickerFor(thoughtId, addBtn);
                }
                return;
            }

            const toggleBtn = ev.target.closest('[data-reaction-toggle]');
            if (toggleBtn) {
                ev.preventDefault();
                const thoughtId = Number(toggleBtn.dataset.thoughtId || '0');
                const emoji = toggleBtn.dataset.emoji || '';
                if (thoughtId > 0 && emoji !== '') {
                    activeContainer = toggleBtn.closest('[data-thought-reactions]');
                    sendToggle(thoughtId, emoji);
                }
                return;
            }

            if (pickerMount && !pickerMount.hidden && !clickEventInsidePicker(ev)) {
                hidePicker();
            }
        });
    }

    window.mountThoughtReactions = mountThoughtReactions;
})();
