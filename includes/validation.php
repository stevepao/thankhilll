<?php
/**
 * includes/validation.php — Small server-side input validators for forms.
 *
 * Returns structured results so callers can show errors without throwing.
 */
declare(strict_types=1);

/** Maximum UTF-8 characters accepted for a single gratitude note body. */
const NOTE_CONTENT_MAX_LENGTH = 10000;

function validation_utf8_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/**
 * @return array{ok: true, value: string}|array{ok: false, error: string}
 */
function validate_required_string(mixed $value, int $maxLength): array
{
    if (!is_string($value)) {
        return ['ok' => false, 'error' => 'Invalid input.'];
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'Please enter a note before saving.'];
    }

    if ($maxLength < 1 || validation_utf8_length($trimmed) > $maxLength) {
        return ['ok' => false, 'error' => 'Note is too long.'];
    }

    return ['ok' => true, 'value' => $trimmed];
}

/**
 * @return array{ok: true, value: string}|array{ok: false, error: string}
 */
function validate_optional_string(mixed $value, int $maxLength): array
{
    if ($value === null) {
        return ['ok' => true, 'value' => ''];
    }

    if (!is_string($value)) {
        return ['ok' => false, 'error' => 'Invalid input.'];
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return ['ok' => true, 'value' => ''];
    }

    if ($maxLength < 1 || validation_utf8_length($trimmed) > $maxLength) {
        return ['ok' => false, 'error' => 'Input is too long.'];
    }

    return ['ok' => true, 'value' => $trimmed];
}

/**
 * @param list<string> $allowedValues
 * @return array{ok: true, value: string}|array{ok: false, error: string}
 */
function validate_enum(mixed $value, array $allowedValues): array
{
    if (!is_string($value)) {
        return ['ok' => false, 'error' => 'Invalid selection.'];
    }

    if (!in_array($value, $allowedValues, true)) {
        return ['ok' => false, 'error' => 'Invalid selection.'];
    }

    return ['ok' => true, 'value' => $value];
}
