<?php
/**
 * header.php — Opening HTML, viewport meta, and shared layout shell.
 *
 * Expects before include:
 *   $pageTitle (string) — used in <title>
 *   $currentNav (string) — 'today' | 'notes' for bottom nav active state (set in footer)
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? 'Gratitude';
$headerUser = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Gratitude</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
    <div class="app">
        <header class="top-bar">
            <div class="top-bar__row">
                <h1 class="top-bar__title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($headerUser !== null): ?>
                    <div class="top-bar__auth">
                        <span class="top-bar__user"><?= htmlspecialchars((string) $headerUser['display_name'], ENT_QUOTES, 'UTF-8') ?></span>
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
