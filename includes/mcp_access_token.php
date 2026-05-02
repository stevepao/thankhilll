<?php
/**
 * MCP access tokens: opaque bearer credentials bound to users (hashed at rest).
 * Issue, list, revoke; use mcp_access_token_resolve_user_id() in MCP auth middleware
 * (rejects expired or revoked rows).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/** Maximum lifetime for a newly issued token (seconds). */
const MCP_ACCESS_TOKEN_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

/**
 * Insert hashed token row; return plaintext token once and RFC3339 expiry.
 *
 * @return array{token: string, expires_at: string}
 */
function mcp_access_token_issue(PDO $pdo, int $userId, ?string $label): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user id');
    }

    $bytes = random_bytes(32);
    $plaintext = bin2hex($bytes);
    $hashHex = hash('sha256', $bytes);

    $expires = new DateTimeImmutable('@' . (time() + MCP_ACCESS_TOKEN_LIFETIME_SECONDS));
    $expiresDb = $expires->format('Y-m-d H:i:s');

    $labelStored = null;
    if ($label !== null) {
        $t = trim($label);
        if ($t !== '') {
            $labelStored = function_exists('mb_substr')
                ? mb_substr($t, 0, 255, 'UTF-8')
                : substr($t, 0, 255);
        }
    }

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            INSERT INTO mcp_access_tokens (user_id, token_hash, label, expires_at)
            VALUES (?, ?, ?, ?)
            SQL
        );
        $stmt->execute([$userId, $hashHex, $labelStored, $expiresDb]);
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            throw new RuntimeException('mcp_access_tokens table missing — run migration 002.', 0, $e);
        }
        throw $e;
    }

    return [
        'token' => $plaintext,
        'expires_at' => $expires->format(DateTimeInterface::ATOM),
    ];
}

/**
 * @return list<array{id:int,created_at:?string,expires_at:?string,revoked_at:?string,label:?string,description:?string}>
 */
function mcp_access_tokens_list_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT id, created_at, expires_at, revoked_at, label
            FROM mcp_access_tokens
            WHERE user_id = ?
            ORDER BY created_at DESC
            SQL
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            throw new RuntimeException('mcp_access_tokens table missing — run migration 002.', 0, $e);
        }
        throw $e;
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row['id']) ? (int) $row['id'] : 0;
        if ($id <= 0) {
            continue;
        }
        $fmt = static function (mixed $v): ?string {
            if ($v === null || !is_string($v) || trim($v) === '') {
                return null;
            }
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v);
            if ($dt === false) {
                return $v;
            }

            return $dt->format(DateTimeInterface::ATOM);
        };
        $lab = $row['label'] ?? null;

        $labelOut = is_string($lab) && $lab !== '' ? $lab : null;

        $out[] = [
            'id' => $id,
            'created_at' => $fmt($row['created_at'] ?? null),
            'expires_at' => $fmt($row['expires_at'] ?? null),
            'revoked_at' => $fmt($row['revoked_at'] ?? null),
            'label' => $labelOut,
            'description' => $labelOut,
        ];
    }

    return $out;
}

/**
 * Revoke a token row owned by user_id (idempotent if already revoked).
 *
 * @return array{ok: true, already_revoked?: true}|array{ok: false, error: 'not_found'}
 */
function mcp_access_token_revoke(PDO $pdo, int $userId, int $tokenId): array
{
    if ($userId <= 0 || $tokenId <= 0) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT id, revoked_at FROM mcp_access_tokens
            WHERE id = ? AND user_id = ?
            LIMIT 1
            SQL
        );
        $stmt->execute([$tokenId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            throw new RuntimeException('mcp_access_tokens table missing — run migration 002.', 0, $e);
        }
        throw $e;
    }

    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    if ($row['revoked_at'] !== null && trim((string) $row['revoked_at']) !== '') {
        return ['ok' => true, 'already_revoked' => true];
    }

    $upd = $pdo->prepare(
        <<<'SQL'
        UPDATE mcp_access_tokens
        SET revoked_at = CURRENT_TIMESTAMP
        WHERE id = ? AND user_id = ? AND revoked_at IS NULL
        SQL
    );
    $upd->execute([$tokenId, $userId]);

    return ['ok' => true];
}

/**
 * Revoke by stored hash hex (same value as DB token_hash). Caller must own the row.
 *
 * @return array{ok: true, already_revoked?: true}|array{ok: false, error: 'not_found'}
 */
function mcp_access_token_revoke_by_hash(PDO $pdo, int $userId, string $tokenHashHex): array
{
    if ($userId <= 0 || preg_match('/^[a-f0-9]{64}$/', $tokenHashHex) !== 1) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT id FROM mcp_access_tokens
            WHERE token_hash = ? AND user_id = ?
            LIMIT 1
            SQL
        );
        $stmt->execute([$tokenHashHex, $userId]);
        $col = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            throw new RuntimeException('mcp_access_tokens table missing — run migration 002.', 0, $e);
        }
        throw $e;
    }

    if ($col === false) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $id = (int) $col;

    return $id > 0 ? mcp_access_token_revoke($pdo, $userId, $id) : ['ok' => false, 'error' => 'not_found'];
}

/**
 * Resolve plaintext bearer token (64-char hex) to user id, or null if invalid/expired/revoked.
 * Call this from MCP gateway middleware before acting as the user.
 */
function mcp_access_token_resolve_user_id(PDO $pdo, string $plaintextHex): ?int
{
    if (preg_match('/^[a-f0-9]{64}$/', $plaintextHex) !== 1) {
        return null;
    }

    $bin = hex2bin($plaintextHex);
    if ($bin === false || strlen($bin) !== 32) {
        return null;
    }

    $hashHex = hash('sha256', $bin);

    try {
        $stmt = $pdo->prepare(
            <<<'SQL'
            SELECT user_id FROM mcp_access_tokens
            WHERE token_hash = ?
              AND revoked_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
            SQL
        );
        $stmt->execute([$hashHex]);
        $col = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (pdo_error_is_unknown_table($e)) {
            return null;
        }
        throw $e;
    }

    if ($col === false) {
        return null;
    }

    $uid = (int) $col;

    return $uid > 0 ? $uid : null;
}

/**
 * Public MCP HTTP gateway base URL (future endpoint root): `{APP_BASE_URL}/mcp/v1`.
 */
function mcp_gateway_endpoint_url(): string
{
    $base = trim(env_var('APP_BASE_URL'));
    if ($base !== '') {
        return rtrim($base, '/') . '/mcp/v1';
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
        ? $_SERVER['HTTP_HOST']
        : 'localhost';

    return $scheme . '://' . $host . '/mcp/v1';
}

/**
 * Base64-encoded 1Password save request for @1password/save-button (UTF-8 JSON, same as encodeOPSaveRequest).
 *
 * @see https://developer.1password.com/docs/web/add-1password-button-website/
 */
function mcp_access_token_onepassword_save_request_base64(
    string $plaintextToken,
    string $expiresAtIso,
    ?string $label,
    ?string $contactEmail,
    string $mcpGatewayUrl
): string {
    $notes = '**Thankhill** MCP bearer token.' . "\n\n"
        . '- MCP gateway: `' . $mcpGatewayUrl . '`' . "\n"
        . '- Expires: `' . $expiresAtIso . '`' . "\n"
        . '- Shown once at creation; revoke from the issuance page token list if leaked.' . "\n";
    if ($label !== null && trim($label) !== '') {
        $notes .= '- Label: ' . trim($label) . "\n";
    }

    $fields = [
        ['autocomplete' => 'url', 'value' => $mcpGatewayUrl],
    ];
    $emailTrim = $contactEmail !== null ? trim($contactEmail) : '';
    if ($emailTrim !== '' && filter_var($emailTrim, FILTER_VALIDATE_EMAIL) !== false) {
        $fields[] = ['autocomplete' => 'email', 'value' => strtolower($emailTrim)];
    }
    $fields[] = ['autocomplete' => 'current-password', 'value' => $plaintextToken];

    $saveRequest = [
        'title' => 'Thankhill MCP Access Token',
        'fields' => $fields,
        'notes' => $notes,
    ];

    $json = json_encode($saveRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return base64_encode($json);
}
