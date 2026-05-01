<?php
/**
 * Register a new email + PIN account (provider `email`).
 *
 * Sends a welcome message via includes/mailer.php; SMTP settings stay out of this file.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/validation.php';
require_once dirname(__DIR__, 2) . '/includes/email_auth.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';

if (current_user_id() !== null) {
    header('Location: /index.php');
    exit;
}

$errorMessage = null;
$formEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $email = email_auth_normalize($_POST['email'] ?? null);
    $pinA = validate_required_string($_POST['pin'] ?? null, 12);
    $pinB = validate_required_string($_POST['pin_confirm'] ?? null, 12);

    $formEmail = is_string($_POST['email'] ?? null) ? (string) ($_POST['email'] ?? '') : '';

    $genericError = 'We could not complete registration. Please try again.';

    $inputsOk = $email !== null
        && $pinA['ok']
        && $pinB['ok']
        && email_auth_pin_length_ok($pinA['value'])
        && hash_equals($pinA['value'], $pinB['value']);

    if (!$inputsOk) {
        $errorMessage = $genericError;
    } else {
        $pdo = db();
        $exists = $pdo->prepare(
            'SELECT id FROM auth_identities WHERE provider = ? AND identifier = ? LIMIT 1'
        );
        $exists->execute(['email', $email]);

        if ($exists->fetch()) {
            $errorMessage = $genericError;
        } else {
            $pdo->beginTransaction();

            try {
                $displayName = email_auth_display_name_from_email($email);
                $insUser = $pdo->prepare(
                    'INSERT INTO users (display_name, preferences_json) VALUES (?, NULL)'
                );
                $insUser->execute([$displayName]);
                $newUserId = (int) $pdo->lastInsertId();

                $hash = password_hash($pinA['value'], PASSWORD_DEFAULT);
                $insId = $pdo->prepare(
                    'INSERT INTO auth_identities (user_id, provider, identifier, secret_hash, last_used_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $insId->execute([$newUserId, 'email', $email, $hash]);

                $pdo->commit();

                send_email(
                    $email,
                    'Welcome to Gratitude Journal',
                    "Thanks for registering.\n\n"
                    . "You can sign in at any time using this email address and the PIN you chose.\n"
                );

                header('Location: /auth/email/login.php?registered=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Email registration failed: ' . $e->getMessage());
                $errorMessage = $genericError;
            }
        }
    }
}

$pageTitle = 'Create account';
$currentNav = '';
$showNav = false;

require_once dirname(__DIR__, 2) . '/header.php';
?>

            <?php if ($errorMessage !== null): ?>
                <p class="flash flash--error" role="alert"><?= e($errorMessage) ?></p>
            <?php endif; ?>

            <form class="note-form" method="post" action="/auth/email/register.php">
                <?php csrf_hidden_field(); ?>
                <label class="note-form__label" for="email">Email</label>
                <input
                    type="email"
                    class="note-form__input"
                    id="email"
                    name="email"
                    autocomplete="email"
                    value="<?= e($formEmail) ?>"
                >

                <label class="note-form__label" for="pin">PIN (6–12 characters)</label>
                <input type="password" class="note-form__input" id="pin" name="pin" autocomplete="new-password">

                <label class="note-form__label" for="pin_confirm">Confirm PIN</label>
                <input type="password" class="note-form__input" id="pin_confirm" name="pin_confirm" autocomplete="new-password">

                <button type="submit" class="btn btn--primary">Create account</button>
            </form>

            <p class="empty-state"><a href="/auth/email/login.php">Sign in with email</a> · <a href="/login.php">All sign-in options</a></p>

<?php require_once dirname(__DIR__, 2) . '/footer.php'; ?>
