<?php
/**
 * Completes Google OpenID Connect authentication and establishes app session.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../auth.php';

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

try {
    $oidc = new OpenIDConnectClient('https://accounts.google.com', $clientId, $clientSecret);
    $oidc->setRedirectURL($redirectUri);
    $oidc->addScope(['openid', 'email', 'profile']);
    $oidc->authenticate();

    $userInfo = $oidc->requestUserInfo();
    $sub = isset($userInfo->sub) ? trim((string) $userInfo->sub) : '';
    $email = isset($userInfo->email) ? trim((string) $userInfo->email) : '';
    $name = isset($userInfo->name) ? trim((string) $userInfo->name) : '';

    if ($sub === '') {
        throw new RuntimeException('Google did not return a valid subject identifier.');
    }

    if ($name === '') {
        $name = $email !== '' ? $email : 'Google User';
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $findIdentity = $pdo->prepare(
            'SELECT user_id FROM auth_identities WHERE provider = :provider AND identifier = :identifier LIMIT 1'
        );
        $findIdentity->execute([
            'provider' => 'google',
            'identifier' => $sub,
        ]);
        $identity = $findIdentity->fetch();

        if (is_array($identity) && isset($identity['user_id'])) {
            $userId = (int) $identity['user_id'];

            $touchIdentity = $pdo->prepare(
                'UPDATE auth_identities SET last_used_at = CURRENT_TIMESTAMP WHERE provider = :provider AND identifier = :identifier'
            );
            $touchIdentity->execute([
                'provider' => 'google',
                'identifier' => $sub,
            ]);
        } else {
            $createUser = $pdo->prepare(
                'INSERT INTO users (display_name, preferences_json) VALUES (:display_name, :preferences_json)'
            );
            $createUser->execute([
                'display_name' => $name,
                'preferences_json' => null,
            ]);
            $userId = (int) $pdo->lastInsertId();

            $createIdentity = $pdo->prepare(
                'INSERT INTO auth_identities (user_id, provider, identifier, secret_hash, last_used_at)
                 VALUES (:user_id, :provider, :identifier, NULL, CURRENT_TIMESTAMP)'
            );
            $createIdentity->execute([
                'user_id' => $userId,
                'provider' => 'google',
                'identifier' => $sub,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    ensureSessionStarted();
    $_SESSION['user_id'] = $userId;

    header('Location: /index.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Authentication failed. Please try again.';
}
