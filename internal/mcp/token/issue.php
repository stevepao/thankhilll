<?php
/**
 * Internal HTML UI: issue MCP token once with masked display + 1Password Save button.
 * GET: form. POST: creates token, renders one-time plaintext surface (not stored for replay).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/csrf.php';
require_once dirname(__DIR__, 3) . '/includes/mcp_access_token.php';
require_once dirname(__DIR__, 3) . '/includes/escape.php';

bootstrap_session();

$userId = current_user_id();
if ($userId === null) {
    header('Location: /login.php?next=' . rawurlencode('/internal/mcp/token/issue'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $labelRaw = $_POST['label'] ?? '';
    $label = null;
    if (is_string($labelRaw)) {
        $t = trim($labelRaw);
        if ($t !== '') {
            $label = function_exists('mb_substr')
                ? mb_substr($t, 0, 255, 'UTF-8')
                : substr($t, 0, 255);
        }
    }

    try {
        $pdo = db();
        $issued = mcp_access_token_issue($pdo, $userId, $label);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'mcp_access_tokens table missing')) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Database migration required (mcp_access_tokens). Run php bin/migrate.php';
            exit;
        }
        throw $e;
    }

    $plaintext = $issued['token'];
    $opSaveB64 = mcp_access_token_onepassword_save_request_base64($plaintext, $issued['expires_at'], $label);

    $pageTitle = 'MCP token issued';
    $showNav = false;
    require_once dirname(__DIR__, 3) . '/header.php';
    ?>

            <article class="mcp-issue">
                <div class="mcp-issue-success-banner" role="status">
                    <strong>Token created.</strong> <strong>Shown only once.</strong> It will not appear again after you leave, refresh, or reopen this page—copy or save it now.
                </div>

                <section class="mcp-issue-panel" aria-labelledby="mcp-issue-token-heading">
                    <h2 id="mcp-issue-token-heading" class="mcp-issue-panel__title">Your MCP access token</h2>
                    <p id="mcp-token-help" class="mcp-issue-help">
                        Hidden by default. Use Reveal only on a private screen, then copy or save to 1Password.
                    </p>

                    <div class="mcp-issue-token-row">
                        <label class="mcp-issue-label" for="mcp-token-field">Token</label>
                        <input
                            id="mcp-token-field"
                            class="mcp-issue-token-field"
                            type="password"
                            readonly
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            value=""
                            placeholder="•••••••••••••••••••••••••••••••••••••••••••••••••••"
                            aria-describedby="mcp-token-help"
                        >
                    </div>

                    <div class="mcp-issue-actions">
                        <button type="button" class="btn btn--ghost" id="mcp-token-reveal">Reveal</button>
                        <button type="button" class="btn btn--ghost" id="mcp-token-hide" hidden>Hide</button>
                        <button type="button" class="btn btn--primary" id="mcp-token-copy">Copy to clipboard</button>
                    </div>
                    <p class="mcp-issue-copy-status visually-hidden" id="mcp-copy-status" aria-live="polite"></p>

                    <div class="mcp-issue-op">
                        <p class="mcp-issue-op__label">Save in 1Password</p>
                        <onepassword-save-button
                            data-onepassword-type="api-key"
                            value="<?= e($opSaveB64) ?>"
                            lang="en"
                            data-theme="light"
                            padding="compact"
                        ></onepassword-save-button>
                        <p class="mcp-issue-op__hint">
                            The official Save in 1Password control stays disabled until your browser extension activates it
                            (<a href="https://developer.1password.com/docs/web/add-1password-button-website/">docs</a>).
                        </p>
                    </div>
                </section>

                <p class="mcp-issue-footer-actions">
                    <a class="btn btn--ghost" href="/internal/mcp/token/issue">Issue another token</a>
                    <a class="btn btn--ghost" href="/index.php">Back to Thankhill</a>
                </p>

                <script type="application/json" id="thankhill-mcp-token-bootstrap"><?php
                    echo json_encode(
                        [
                            'token' => $plaintext,
                            'expires_at' => $issued['expires_at'],
                        ],
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
?>
                </script>

                <script type="module">
                    import { activateOPButton } from 'https://unpkg.com/@1password/save-button@1.3.0/built/index.js';

                    history.replaceState(null, '', '/internal/mcp/token/issue');

                    const bootEl = document.getElementById('thankhill-mcp-token-bootstrap');
                    let token = '';
                    try {
                        token = bootEl && bootEl.textContent ? JSON.parse(bootEl.textContent.trim()).token || '' : '';
                    } catch (e) {
                        token = '';
                    }
                    if (bootEl) {
                        bootEl.textContent = '';
                        bootEl.remove();
                    }

                    const field = document.getElementById('mcp-token-field');
                    const btnReveal = document.getElementById('mcp-token-reveal');
                    const btnHide = document.getElementById('mcp-token-hide');
                    const btnCopy = document.getElementById('mcp-token-copy');
                    const copyStatus = document.getElementById('mcp-copy-status');

                    function setRevealed(show) {
                        if (!field) return;
                        if (show) {
                            field.type = 'text';
                            field.value = token;
                            btnReveal.hidden = true;
                            btnHide.hidden = false;
                        } else {
                            field.type = 'password';
                            field.value = '';
                            btnReveal.hidden = false;
                            btnHide.hidden = true;
                        }
                    }

                    btnReveal?.addEventListener('click', () => setRevealed(true));
                    btnHide?.addEventListener('click', () => setRevealed(false));

                    btnCopy?.addEventListener('click', async () => {
                        if (!token) return;
                        try {
                            await navigator.clipboard.writeText(token);
                            if (copyStatus) {
                                copyStatus.textContent = 'Copied to clipboard.';
                                copyStatus.classList.remove('visually-hidden');
                                setTimeout(() => {
                                    copyStatus.textContent = '';
                                    copyStatus.classList.add('visually-hidden');
                                }, 2500);
                            }
                        } catch (err) {
                            if (copyStatus) {
                                copyStatus.textContent = 'Could not copy automatically—use Reveal and copy manually.';
                                copyStatus.classList.remove('visually-hidden');
                            }
                        }
                    });

                    activateOPButton();
                </script>
            </article>

    <?php
    require_once dirname(__DIR__, 3) . '/footer.php';
    exit;
}

$pageTitle = 'MCP access token';
$showNav = false;
require_once dirname(__DIR__, 3) . '/header.php';
?>

            <article class="mcp-issue">
                <section class="mcp-issue-panel">
                    <h2 class="mcp-issue-panel__title">Issue MCP access token</h2>
                    <p class="mcp-issue-lede">
                        Creates a bearer credential bound to your Thankhill account for trusted MCP clients.
                        The secret is shown once after you submit—plan to copy or save it immediately.
                    </p>

                    <form class="mcp-issue-form note-form" method="post" action="">
                        <?php csrf_hidden_field(); ?>
                        <label class="note-form__label" for="mcp_issue_label">Label (optional)</label>
                        <input
                            class="note-form__input"
                            type="text"
                            id="mcp_issue_label"
                            name="label"
                            maxlength="255"
                            autocomplete="off"
                            placeholder="e.g. Cursor on laptop"
                        >
                        <button type="submit" class="btn btn--primary">Issue token</button>
                    </form>
                </section>

                <section class="mcp-issue-panel" aria-labelledby="mcp-issue-existing-heading">
                    <h2 id="mcp-issue-existing-heading" class="mcp-issue-panel__title">Existing tokens (metadata only)</h2>
                    <p class="mcp-issue-help">Secrets are never listed here—only ids and timestamps.</p>
                    <div id="mcp-token-metadata-mount" class="mcp-issue-metadata">
                        <p class="mcp-issue-metadata__loading" id="mcp-metadata-loading">Loading…</p>
                    </div>
                </section>

                <p class="mcp-issue-footer-actions">
                    <a class="btn btn--ghost" href="/index.php">Back to Thankhill</a>
                </p>

                <script>
                    (function () {
                        function escapeHtml(s) {
                            return String(s)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;');
                        }

                        var mount = document.getElementById('mcp-token-metadata-mount');
                        var loading = document.getElementById('mcp-metadata-loading');
                        if (!mount) return;

                        fetch('/internal/mcp/tokens', { credentials: 'same-origin' })
                            .then(function (r) {
                                return r.json();
                            })
                            .then(function (data) {
                                if (loading) loading.remove();
                                if (!data || !data.ok || !Array.isArray(data.tokens)) {
                                    mount.innerHTML = '<p class="mcp-issue-metadata__empty">Could not load tokens.</p>';
                                    return;
                                }
                                if (data.tokens.length === 0) {
                                    mount.innerHTML = '<p class="mcp-issue-metadata__empty">No tokens yet.</p>';
                                    return;
                                }
                                var rows = data.tokens
                                    .map(function (t) {
                                        var rev = t.revoked_at ? 'Revoked ' + t.revoked_at : 'Active';
                                        var lab = t.label ? '<br><span class="mcp-issue-meta-label">' + escapeHtml(t.label) + '</span>' : '';
                                        return (
                                            '<li class="mcp-issue-meta-row">' +
                                            '<strong>#' +
                                            t.id +
                                            '</strong> · created ' +
                                            escapeHtml(t.created_at || '') +
                                            ' · expires ' +
                                            escapeHtml(t.expires_at || '') +
                                            '<br>' +
                                            escapeHtml(rev) +
                                            lab +
                                            '</li>'
                                        );
                                    })
                                    .join('');
                                mount.innerHTML = '<ul class="mcp-issue-meta-list">' + rows + '</ul>';
                            })
                            .catch(function () {
                                if (loading) loading.remove();
                                mount.innerHTML = '<p class="mcp-issue-metadata__empty">Could not load tokens.</p>';
                            });
                    })();
                </script>
            </article>

<?php
require_once dirname(__DIR__, 3) . '/footer.php';
