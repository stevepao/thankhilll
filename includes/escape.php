<?php
/**
 * includes/escape.php — HTML output escaping for dynamic values.
 *
 * Escape when rendering to HTML so stored or user-supplied text cannot break out into markup or scripts.
 * Values should remain raw in the database and session; only the presentation layer calls e().
 */
declare(strict_types=1);

/**
 * Escape a scalar for safe insertion into HTML text nodes and quoted attributes.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
