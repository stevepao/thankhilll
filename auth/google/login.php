<?php
/**
 * Starts Google OpenID Connect authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';

use Jumbojett\OpenIDConnectClient;

loadEnv();

$clientId = $_ENV['GOOGLE_OIDC_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_OIDC_CLIENT_SECRET'] ?? '';
$redirectUri = $_ENV['GOOGLE_OIDC_REDIRECT_URI'] ?? '';

if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
    http_response_code(500);
    echo 'Missing Google OIDC configuration.';
    exit;
}

$oidc = new OpenIDConnectClient('https://accounts.google.com', $clientId, $clientSecret);
$oidc->setRedirectURL($redirectUri);
$oidc->addScope(['openid', 'email', 'profile']);

$oidc->authenticate();
