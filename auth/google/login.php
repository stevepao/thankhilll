<?php
/**
 * Starts Google OpenID Connect authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/auth_redirect.php';

use Jumbojett\OpenIDConnectClient;

bootstrap_session();

if (isset($_GET['next']) && is_string($_GET['next']) && auth_redirect_uri_safe($_GET['next'])) {
    $_SESSION['auth_redirect_after_login'] = $_GET['next'];
}

require_once __DIR__ . '/../../db.php';

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
// Request refresh tokens so we can revoke Google access when the user deletes their account.
$oidc->addAuthParam(['access_type' => 'offline']);

$oidc->authenticate();
