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
/** @var list<string> $extraStylesheets Extra CSS paths after Tailwind (site-root paths). */
$extraStylesheets = $extraStylesheets ?? [];
/** @var string Optional extra classes on <body> (page-specific; logged-in canvas classes are appended in header). */
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
/** @var string Classes for <main> (default keeps legacy layout). */
$mainClass = isset($mainClass) ? trim((string) $mainClass) : 'main';
/** @var string Extra classes on the top header bar (merged with signed-in chrome below). */
$topBarExtraClass = isset($topBarExtraClass) ? trim((string) $topBarExtraClass) : '';
/** @var string Extra classes on the top-bar page title heading (merged when signed in). */
$topBarTitleClass = isset($topBarTitleClass) ? trim((string) $topBarTitleClass) : '';
$headerUser = currentUser();
$mainClassEffective = trim($mainClass . ($headerUser !== null ? ' tn-th-scope' : ''));
/** Slate canvas, antialiasing, and frosted top bar — same as Notes, for every signed-in route. */
$bodyClassEffective = trim(
    $bodyClass . ($headerUser !== null ? ' tn-bg-tn-bg tn-antialiased th-app-shell' : ''),
);
$topBarExtraClassEffective = trim(
    $topBarExtraClass . ($headerUser !== null ? ' tn-bg-white/75 tn-backdrop-blur-sm' : ''),
);
$topBarTitleClassEffective = trim(
    $topBarTitleClass . ($headerUser !== null ? ' tn-text-xl tn-font-semibold tn-tracking-tight tn-text-slate-800' : ''),
);
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
    <link rel="stylesheet" href="<?= e(asset_url('/public/tailwind.css')) ?>">
    <?php foreach ($extraStylesheets as $sheetHref): ?>
        <?php
        $sheetHref = trim((string) $sheetHref);
        if ($sheetHref === '') {
            continue;
        }
        ?>
        <link rel="stylesheet" href="<?= e(asset_url($sheetHref)) ?>">
    <?php endforeach; ?>
</head>
<body<?= $bodyClassEffective !== '' ? ' class="' . e($bodyClassEffective) . '"' : '' ?>>
    <div class="app">
        <header class="top-bar<?= $topBarExtraClassEffective !== '' ? ' ' . e($topBarExtraClassEffective) : '' ?>">
            <div class="top-bar__row">
                <h1 class="top-bar__title<?= $topBarTitleClassEffective !== '' ? ' ' . e($topBarTitleClassEffective) : '' ?>"><?= e($pageTitle) ?></h1>
                <?php if ($headerUser !== null): ?>
                    <div class="top-bar__auth">
                        <button
                            type="button"
                            class="top-bar__tips-btn"
                            data-th-setup-tips-open
                            aria-haspopup="dialog"
                            aria-controls="thankhill-setup-tips-dialog"
                            title="Tips—install Thankhill and notifications"
                        >
                            <span class="top-bar__tips-icon" aria-hidden="true">ⓘ</span>
                            <span class="visually-hidden">Tips and setup</span>
                        </button>
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
        <main class="<?= e($mainClassEffective) ?>">
