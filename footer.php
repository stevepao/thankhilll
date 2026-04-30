<?php
/**
 * footer.php — Closes main content and renders the reusable bottom navigation.
 *
 * Expects:
 *   $currentNav (string) — 'today' | 'notes'
 */
declare(strict_types=1);

$currentNav = $currentNav ?? 'today';
$showNav = $showNav ?? true;
?>
        </main>

        <?php if ($showNav): ?>
            <nav class="bottom-nav" aria-label="Primary">
                <a href="/index.php" class="bottom-nav__link <?= $currentNav === 'today' ? 'is-active' : '' ?>">
                    <span class="bottom-nav__label">Today</span>
                </a>
                <a href="/notes.php" class="bottom-nav__link <?= $currentNav === 'notes' ? 'is-active' : '' ?>">
                    <span class="bottom-nav__label">Notes</span>
                </a>
            </nav>
        <?php endif; ?>
    </div>
</body>
</html>
