<?php
/**
 * includes/note_preview.php — Single-line plaintext preview for shared snippets.
 */
declare(strict_types=1);

/** Collapse whitespace and truncate for calm list previews (no HTML). */
function note_plain_preview(string $content, int $maxChars = 140): string
{
    $line = trim(preg_replace('/\s+/u', ' ', $content));
    if ($line === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($line, 'UTF-8') <= $maxChars) {
            return $line;
        }

        return mb_substr($line, 0, max(1, $maxChars - 1), 'UTF-8') . '…';
    }

    if (strlen($line) <= $maxChars) {
        return $line;
    }

    return substr($line, 0, max(1, $maxChars - 1)) . '…';
}
