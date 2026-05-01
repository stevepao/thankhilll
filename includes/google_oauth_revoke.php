<?php
/**
 * includes/google_oauth_revoke.php — Revoke Google OAuth tokens (RFC 7009-style endpoint).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/**
 * Revoke a refresh or access token with Google. Logs outcome; failures are non-fatal for callers.
 */
function google_oauth_revoke_token_logged(string $token): void
{
    $token = trim($token);
    if ($token === '') {
        return;
    }

    loadEnv();
    $clientId = trim((string) ($_ENV['GOOGLE_OIDC_CLIENT_ID'] ?? ''));
    $clientSecret = trim((string) ($_ENV['GOOGLE_OIDC_CLIENT_SECRET'] ?? ''));

    $postFields = ['token' => $token];
    if ($clientId !== '') {
        $postFields['client_id'] = $clientId;
    }
    if ($clientSecret !== '') {
        $postFields['client_secret'] = $clientSecret;
    }

    $body = http_build_query($postFields, '', '&');
    $url = 'https://oauth2.googleapis.com/revoke';

    if (!function_exists('curl_init')) {
        error_log('google_oauth_revoke_token_logged: PHP curl extension required for revocation');

        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('google_oauth_revoke_token_logged: curl_init failed');

        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('google_oauth_revoke_token_logged: curl error ' . $curlErr);

        return;
    }

    if ($code >= 200 && $code < 300) {
        error_log('google_oauth_revoke_token_logged: revoked OK (HTTP ' . $code . ')');

        return;
    }

    error_log(
        'google_oauth_revoke_token_logged: revoke failed HTTP ' . $code
        . ' body=' . substr((string) $response, 0, 512)
    );
}
