<?php
/**
 * group_new.php — Create a group (name only); creator becomes owner and member.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/group_helpers.php';

$userId = require_login();

$validationError = null;
$nameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_post_or_abort();

    $nameRaw = $_POST['name'] ?? '';
    $nameValue = is_string($nameRaw) ? $nameRaw : '';
    $nameTrimmed = trim($nameValue);

    if ($nameTrimmed === '') {
        $validationError = 'Please enter a group name.';
    } elseif (validation_utf8_length($nameTrimmed) > 160) {
        $validationError = 'Name is too long.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO `groups` (name, owner_user_id) VALUES (?, ?)'
            );
            $ins->execute([$nameTrimmed, $userId]);
            $groupId = (int) $pdo->lastInsertId();

            $mem = $pdo->prepare(
                'INSERT INTO group_members (user_id, group_id, role, joined_at)
                 VALUES (?, ?, \'member\', NOW())'
            );
            $mem->execute([$userId, $groupId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('group_new: ' . $e->getMessage());
            $validationError = 'Could not create the group. Please try again.';
        }

        if ($validationError === null) {
            header('Location: /group.php?id=' . $groupId . '&created=1');
            exit;
        }
    }
}

$pageTitle = 'New group';
$currentNav = 'groups';

require_once __DIR__ . '/header.php';
?>

            <?php if ($validationError !== null): ?>
                <p class="flash flash--error" role="alert"><?= e($validationError) ?></p>
            <?php endif; ?>

            <form class="note-form" method="post" action="/group_new.php">
                <?php csrf_hidden_field(); ?>
                <label class="note-form__label" for="name">Group name</label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    class="note-form__input"
                    maxlength="160"
                    required
                    autocomplete="organization"
                    value="<?= e($nameValue) ?>"
                >
                <button type="submit" class="btn btn--primary">Create group</button>
            </form>

<?php require_once __DIR__ . '/footer.php'; ?>
