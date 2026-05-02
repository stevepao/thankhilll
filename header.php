<?php
/**
 * header.php — Opening HTML, viewport meta, and shared layout shell.
 *
 * Expects before include:
 *   $pageTitle (string) — main heading (top bar); document <title> is fixed to Thankhill for PWA identity
 *   $currentNav (string) — 'today' | 'notes' | 'groups' | 'me' for bottom nav active state (set in footer)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/escape.php';
require_once __DIR__ . '/includes/assets.php';

$pageTitle = $pageTitle ?? 'Gratitude';
$metaDescription = $metaDescription ?? null;
$headerUser = currentUser();
$htmlUserTzAttr = '';
if ($headerUser !== null) {
    require_once __DIR__ . '/includes/user_timezone.php';
    $storedTz = isset($headerUser['timezone']) && is_string($headerUser['timezone']) ? $headerUser['timezone'] : '';
    $htmlUserTzAttr = ' data-user-timezone="' . htmlspecialchars(user_timezone_normalize($storedTz), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
}
?>
<!DOCTYPE html>
<html lang="en"<?= $htmlUserTzAttr ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($metaDescription !== null && $metaDescription !== ''): ?>
        <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <title>Thankhill</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2d6a4f">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
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
