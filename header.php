<?php
/**
 * header.php — Opening HTML, viewport meta, and shared layout shell.
 *
 * Expects before include:
 *   $pageTitle (string) — used in <title>
 *   $currentNav (string) — 'today' | 'notes' for bottom nav active state (set in footer)
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Gratitude';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Gratitude</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="app">
        <header class="top-bar">
            <h1 class="top-bar__title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        </header>
        <main class="main">
