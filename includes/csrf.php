<?php
/**
 * includes/csrf.php — Session-bound CSRF tokens for form POSTs.
 *
 * Intent: prevent cross-site requests from forging authenticated actions. Tokens are opaque,
 * stored server-side only, and compared with hash_equals() to avoid timing leaks.
 * Independent of how the user authenticated (Google, email+PIN, etc.).
 */
declare(strict_types=1);

require_once __DIR__ . '/session.php';

/**
 * Returns the current CSRF token, generating one on first use if absent.
 */
function csrf_token(): string
{
    bootstrap_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * True if the submitted value matches the stored token (constant-time compare).
 */
function csrf_validate(string $submitted): bool
{
    bootstrap_session();

    $stored = $_SESSION['csrf_token'] ?? null;
    if (!is_string($stored) || $stored === '') {
        return false;
    }

    return hash_equals($stored, $submitted);
}

/**
 * Echo a hidden input for POST forms (name must match POST validation).
 */
function csrf_hidden_field(): void
{
    $value = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    echo '<input type="hidden" name="csrf_token" value="' . $value . '">';
}

/**
 * Abort with 403 unless POST includes a valid csrf_token field.
 * Call at the start of any POST handler before mutating state.
 */
function csrf_verify_post_or_abort(): void
{
    $raw = $_POST['csrf_token'] ?? null;
    if (!is_string($raw) || $raw === '' || !csrf_validate($raw)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid or missing security token. Please refresh the page and try again.';
        exit;
    }
}
