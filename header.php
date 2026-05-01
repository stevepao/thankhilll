<?php
/**
 * header.php — Opening HTML, viewport meta, and shared layout shell.
 *
 * Expects before include:
 *   $pageTitle (string) — used in <title>
 *   $currentNav (string) — 'today' | 'notes' | 'groups' | 'me' for bottom nav active state (set in footer)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/escape.php';
require_once __DIR__ . '/includes/assets.php';

$pageTitle = $pageTitle ?? 'Gratitude';
$headerUser = currentUser();
$htmlUserTzAttr = '';
if ($headerUser !== null) {
    require_once __DIR__ . '/includes/user_timezone.php';
    $storedTz = isset($headerUser['timezone']) && is_string($headerUser['timezone']) ? $headerUser['timezone'] : '';
    $htmlUserTzAttr = ' data-user-timezone="' . htmlspecialchars(user_timezone_normalize($storedTz), ENT_QUOTES, 'UTF-8') . '"';
}
?>
<!DOCTYPE html>
<html lang="en"<?= $htmlUserTzAttr ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — Gratitude</title>
    <link rel="stylesheet" href="<?= e(asset_url('/styles.css')) ?>">
</head>
<body>
    <div class="app">
        <header class="top-bar">
            <div class="top-bar__row">
                <h1 class="top-bar__title"><?= e($pageTitle) ?></h1>
                <?php if ($headerUser !== null): ?>
                    <div class="top-bar__auth">
                        <span class="top-bar__user"><?= e($headerUser['display_name'] ?? '') ?></span>
                        <a href="/auth/logout.php">Log out</a>
                    </div>
                <?php else: ?>
                    <div class="top-bar__auth">
                        <a href="/login.php">Log in</a>
                    </div>
                <?php endif; ?>
            </div>
        </header>
        <main class="main">
