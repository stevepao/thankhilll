#!/usr/bin/env php
<?php
/**
 * bin/migrate_user_content.php
 *
 * One-off merge: move notes and related data from a mistaken account (e.g. email OTP)
 * into the user's real account (e.g. Google). Duplicate entry dates merge thoughts/media
 * onto the target user's existing note for that day.
 *
 * Usage:
 *   php bin/migrate_user_content.php --from-email=mpao@spao.net --to-email=marsha@bykumi.com --dry-run
 *   php bin/migrate_user_content.php --from-email=mpao@spao.net --to-email=marsha@bykumi.com --apply --delete-source
 *
 * Docker host:
 *   docker compose exec web php bin/migrate_user_content.php --from-email=... --to-email=... --dry-run
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/includes/email_auth.php';
require_once dirname(__DIR__) . '/includes/user_preferences.php';
require_once dirname(__DIR__) . '/includes/account_delete.php';

function th_migrate_print_usage(): void
{
    $self = basename(__FILE__);
    fwrite(STDERR, <<<TXT
Usage:
  php {$self} --from-email=SOURCE --to-email=TARGET [--dry-run|--apply] [--delete-source]

  --from-email     Source account email (typically email OTP auth identity).
  --to-email       Target account email (typically Google oauth contact / login email).
  --dry-run        Report planned changes only (default).
  --apply          Execute migration in a transaction.
  --delete-source  With --apply, remove the source user after a successful merge.

TXT);
}

/**
 * @return array{user_id:int,display_name:string,provider:string}|null
 */
function th_migrate_user_by_email_auth(PDO $pdo, string $email, string $provider): ?array
{
    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT u.id AS user_id, u.display_name, ai.provider
        FROM auth_identities ai
        INNER JOIN users u ON u.id = ai.user_id
        WHERE ai.provider = ? AND ai.identifier = ?
        LIMIT 1
        SQL
    );
    $stmt->execute([$provider, $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? [
        'user_id' => (int) $row['user_id'],
        'display_name' => (string) $row['display_name'],
        'provider' => (string) $row['provider'],
    ] : null;
}

/**
 * @return array{user_id:int,display_name:string,provider:string}|null
 */
function th_migrate_user_by_email_any(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE login_email_normalized = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return [
            'user_id' => (int) $row['id'],
            'display_name' => (string) $row['display_name'],
            'provider' => 'login_email',
        ];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
        SELECT u.id AS user_id, u.display_name, ai.provider
        FROM auth_identities ai
        INNER JOIN users u ON u.id = ai.user_id
        WHERE ai.oauth_contact_email_normalized = ?
        ORDER BY ai.provider = 'google' DESC, ai.last_used_at DESC, ai.id DESC
        LIMIT 1
        SQL
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? [
        'user_id' => (int) $row['user_id'],
        'display_name' => (string) $row['display_name'],
        'provider' => (string) $row['provider'],
    ] : null;
}

/** @return list<array{id:int,entry_date:string}> */
function th_migrate_notes_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, entry_date FROM notes WHERE user_id = ? ORDER BY entry_date ASC, id ASC'
    );
    $stmt->execute([$userId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (int) $row['id'],
            'entry_date' => (string) $row['entry_date'],
        ];
    }

    return $out;
}

/** @return array<string,int> entry_date => note_id */
function th_migrate_notes_index_by_date(array $notes): array
{
    $map = [];
    foreach ($notes as $note) {
        $map[$note['entry_date']] = $note['id'];
    }

    return $map;
}

function th_migrate_count_for_note(PDO $pdo, string $table, int $noteId): int
{
    $allowed = ['note_thoughts', 'note_media', 'note_groups'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported table: ' . $table);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE note_id = ?");
    $stmt->execute([$noteId]);

    return (int) $stmt->fetchColumn();
}

/** @return list<array{group_id:int,role:string}> */
function th_migrate_group_memberships(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT group_id, role FROM group_members WHERE user_id = ? ORDER BY group_id ASC'
    );
    $stmt->execute([$userId]);
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'group_id' => (int) $row['group_id'],
            'role' => (string) $row['role'],
        ];
    }

    return $out;
}

function th_migrate_reassign_user_rows(PDO $pdo, string $table, int $fromUserId, int $toUserId, bool $apply): int
{
    $allowed = ['reactions', 'thought_reactions', 'thought_comments'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported table: ' . $table);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
    $countStmt->execute([$fromUserId]);
    $count = (int) $countStmt->fetchColumn();
    if ($count === 0 || !$apply) {
        return $count;
    }

    if ($table === 'reactions') {
        $rows = $pdo->prepare('SELECT id, note_id, emoji FROM reactions WHERE user_id = ?');
        $rows->execute([$fromUserId]);
        $moved = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $dup = $pdo->prepare(
                'SELECT 1 FROM reactions WHERE note_id = ? AND user_id = ? AND emoji = ? LIMIT 1'
            );
            $dup->execute([(int) $row['note_id'], $toUserId, (string) $row['emoji']]);
            if ($dup->fetchColumn()) {
                $del = $pdo->prepare('DELETE FROM reactions WHERE id = ?');
                $del->execute([(int) $row['id']]);
                continue;
            }
            $upd = $pdo->prepare('UPDATE reactions SET user_id = ? WHERE id = ?');
            $upd->execute([$toUserId, (int) $row['id']]);
            $moved++;
        }

        return $moved;
    }

    if ($table === 'thought_reactions') {
        $rows = $pdo->prepare('SELECT id, thought_id, emoji FROM thought_reactions WHERE user_id = ?');
        $rows->execute([$fromUserId]);
        $moved = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $dup = $pdo->prepare(
                'SELECT 1 FROM thought_reactions WHERE thought_id = ? AND user_id = ? AND emoji = ? LIMIT 1'
            );
            $dup->execute([(int) $row['thought_id'], $toUserId, (string) $row['emoji']]);
            if ($dup->fetchColumn()) {
                $del = $pdo->prepare('DELETE FROM thought_reactions WHERE id = ?');
                $del->execute([(int) $row['id']]);
                continue;
            }
            $upd = $pdo->prepare('UPDATE thought_reactions SET user_id = ? WHERE id = ?');
            $upd->execute([$toUserId, (int) $row['id']]);
            $moved++;
        }

        return $moved;
    }

    $upd = $pdo->prepare("UPDATE {$table} SET user_id = ? WHERE user_id = ?");
    $upd->execute([$toUserId, $fromUserId]);

    return $count;
}

function th_migrate_merge_note(PDO $pdo, int $sourceNoteId, int $targetNoteId, bool $apply): void
{
    if (!$apply) {
        return;
    }

    $moveThoughts = $pdo->prepare('UPDATE note_thoughts SET note_id = ? WHERE note_id = ?');
    $moveThoughts->execute([$targetNoteId, $sourceNoteId]);

    $moveMedia = $pdo->prepare('UPDATE note_media SET note_id = ? WHERE note_id = ?');
    $moveMedia->execute([$targetNoteId, $sourceNoteId]);

    $groups = $pdo->prepare('SELECT id, group_id FROM note_groups WHERE note_id = ?');
    $groups->execute([$sourceNoteId]);
    while ($row = $groups->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $groupId = (int) $row['group_id'];
        $rowId = (int) $row['id'];
        $exists = $pdo->prepare(
            'SELECT 1 FROM note_groups WHERE note_id = ? AND group_id = ? LIMIT 1'
        );
        $exists->execute([$targetNoteId, $groupId]);
        if ($exists->fetchColumn()) {
            $del = $pdo->prepare('DELETE FROM note_groups WHERE id = ?');
            $del->execute([$rowId]);
            continue;
        }
        $upd = $pdo->prepare('UPDATE note_groups SET note_id = ? WHERE id = ?');
        $upd->execute([$targetNoteId, $rowId]);
    }

    $delNote = $pdo->prepare('DELETE FROM notes WHERE id = ?');
    $delNote->execute([$sourceNoteId]);
}

function th_migrate_merge_preferences(PDO $pdo, int $fromUserId, int $toUserId, bool $apply): array
{
    $fromPrefs = user_preferences_load($pdo, $fromUserId);
    $toPrefs = user_preferences_load($pdo, $toUserId);

    $mergedIds = [];
    foreach ([$toPrefs['last_used_group_ids'] ?? [], $fromPrefs['last_used_group_ids'] ?? []] as $ids) {
        if (!is_array($ids)) {
            continue;
        }
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $mergedIds[$n] = $n;
            }
        }
    }
    $mergedIds = array_values(array_slice(array_values($mergedIds), 0, 32));

    $patch = [
        'last_used_group_ids' => $mergedIds,
    ];
    if (($fromPrefs['default_note_visibility'] ?? '') === 'last_used_groups'
        && ($toPrefs['default_note_visibility'] ?? '') !== 'last_used_groups') {
        $patch['default_note_visibility'] = 'last_used_groups';
    }

    if ($apply) {
        user_preferences_merge_save($pdo, $toUserId, $patch);
    }

    return $patch;
}

$fromEmailRaw = null;
$toEmailRaw = null;
$apply = false;
$deleteSource = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        th_migrate_print_usage();
        exit(0);
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $apply = false;
        continue;
    }
    if ($arg === '--delete-source') {
        $deleteSource = true;
        continue;
    }
    if (str_starts_with($arg, '--from-email=')) {
        $fromEmailRaw = substr($arg, strlen('--from-email='));
        continue;
    }
    if (str_starts_with($arg, '--to-email=')) {
        $toEmailRaw = substr($arg, strlen('--to-email='));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    th_migrate_print_usage();
    exit(2);
}

if ($fromEmailRaw === null || $toEmailRaw === null) {
    fwrite(STDERR, "Error: --from-email and --to-email are required.\n");
    th_migrate_print_usage();
    exit(2);
}

$fromEmail = email_auth_normalize($fromEmailRaw);
$toEmail = email_auth_normalize($toEmailRaw);
if ($fromEmail === null || $toEmail === null) {
    fwrite(STDERR, "Error: invalid email address.\n");
    exit(2);
}

if ($fromEmail === $toEmail) {
    fwrite(STDERR, "Error: source and target email must differ.\n");
    exit(2);
}

$pdo = db();

$source = th_migrate_user_by_email_auth($pdo, $fromEmail, 'email');
if ($source === null) {
    $source = th_migrate_user_by_email_any($pdo, $fromEmail);
}
if ($source === null) {
    fwrite(STDERR, "Error: no user found for source email {$fromEmail}.\n");
    exit(1);
}

$target = th_migrate_user_by_email_any($pdo, $toEmail);
if ($target === null) {
    fwrite(STDERR, "Error: no user found for target email {$toEmail}.\n");
    exit(1);
}

$targetGoogle = $pdo->prepare(
    'SELECT 1 FROM auth_identities WHERE user_id = ? AND provider = \'google\' LIMIT 1'
);
$targetGoogle->execute([$target['user_id']]);
if (!$targetGoogle->fetchColumn()) {
    fwrite(STDERR, "Warning: target user #{$target['user_id']} has no Google auth identity.\n");
}

$fromUserId = $source['user_id'];
$toUserId = $target['user_id'];
if ($fromUserId <= 0 || $toUserId <= 0) {
    fwrite(STDERR, "Error: invalid resolved user id (from={$fromUserId}, to={$toUserId}).\n");
    exit(1);
}
if ($fromUserId === $toUserId) {
    fwrite(STDERR, "Error: source and target resolve to the same user id {$fromUserId}.\n");
    exit(1);
}

echo ($apply ? "Applying migration.\n" : "Dry run (no writes).\n");
echo "Source: user #{$fromUserId} ({$source['display_name']}) via {$source['provider']} — {$fromEmail}\n";
echo "Target: user #{$toUserId} ({$target['display_name']}) via {$target['provider']} — {$toEmail}\n";

$sourceNotes = th_migrate_notes_for_user($pdo, $fromUserId);
$targetNotes = th_migrate_notes_for_user($pdo, $toUserId);
$targetByDate = th_migrate_notes_index_by_date($targetNotes);

$reassign = [];
$merge = [];
foreach ($sourceNotes as $note) {
    $entryDate = $note['entry_date'];
    if (isset($targetByDate[$entryDate])) {
        $merge[] = [
            'source_note_id' => $note['id'],
            'target_note_id' => $targetByDate[$entryDate],
            'entry_date' => $entryDate,
            'thoughts' => th_migrate_count_for_note($pdo, 'note_thoughts', $note['id']),
            'media' => th_migrate_count_for_note($pdo, 'note_media', $note['id']),
            'groups' => th_migrate_count_for_note($pdo, 'note_groups', $note['id']),
        ];
    } else {
        $reassign[] = $note;
    }
}

echo "\nNotes to reassign: " . count($reassign) . "\n";
foreach ($reassign as $note) {
    echo "  - note #{$note['id']} ({$note['entry_date']})\n";
}

echo "\nNotes to merge (duplicate dates): " . count($merge) . "\n";
foreach ($merge as $item) {
    echo "  - note #{$item['source_note_id']} ({$item['entry_date']})"
        . " → #{$item['target_note_id']}"
        . " [thoughts={$item['thoughts']}, media={$item['media']}, groups={$item['groups']}]\n";
}

$sourceGroups = th_migrate_group_memberships($pdo, $fromUserId);
$targetGroupIds = array_flip(array_column(th_migrate_group_memberships($pdo, $toUserId), 'group_id'));
$groupsToAdd = [];
foreach ($sourceGroups as $gm) {
    if (!isset($targetGroupIds[$gm['group_id']])) {
        $groupsToAdd[] = $gm;
    }
}

echo "\nGroup memberships to add to target: " . count($groupsToAdd) . "\n";
foreach ($groupsToAdd as $gm) {
    echo "  - group #{$gm['group_id']} (role={$gm['role']})\n";
}

$reactionStmt = $pdo->prepare('SELECT COUNT(*) FROM reactions WHERE user_id = ?');
$reactionStmt->execute([$fromUserId]);
$reactionCount = (int) $reactionStmt->fetchColumn();

$thoughtReactionStmt = $pdo->prepare('SELECT COUNT(*) FROM thought_reactions WHERE user_id = ?');
$thoughtReactionStmt->execute([$fromUserId]);
$thoughtReactionCount = (int) $thoughtReactionStmt->fetchColumn();

$commentStmt = $pdo->prepare('SELECT COUNT(*) FROM thought_comments WHERE user_id = ?');
$commentStmt->execute([$fromUserId]);
$commentCount = (int) $commentStmt->fetchColumn();

echo "\nUser-attributed activity to reassign:\n";
echo "  - reactions: {$reactionCount}\n";
echo "  - thought_reactions: {$thoughtReactionCount}\n";
echo "  - thought_comments: {$commentCount}\n";

$prefPatch = th_migrate_merge_preferences($pdo, $fromUserId, $toUserId, false);
echo "\nPreferences patch for target:\n";
echo '  - last_used_group_ids: ' . json_encode($prefPatch['last_used_group_ids'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
if (isset($prefPatch['default_note_visibility'])) {
    echo '  - default_note_visibility: ' . $prefPatch['default_note_visibility'] . "\n";
}

if (!$apply) {
    echo "\nDry run complete. Re-run with --apply to execute";
    if ($deleteSource) {
        echo " (ignored --delete-source without --apply)";
    }
    echo ".\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    foreach ($reassign as $note) {
        $upd = $pdo->prepare('UPDATE notes SET user_id = ? WHERE id = ? AND user_id = ?');
        $upd->execute([$toUserId, $note['id'], $fromUserId]);
    }

    foreach ($merge as $item) {
        th_migrate_merge_note($pdo, $item['source_note_id'], $item['target_note_id'], true);
    }

    th_migrate_reassign_user_rows($pdo, 'reactions', $fromUserId, $toUserId, true);
    th_migrate_reassign_user_rows($pdo, 'thought_reactions', $fromUserId, $toUserId, true);
    th_migrate_reassign_user_rows($pdo, 'thought_comments', $fromUserId, $toUserId, true);

    foreach ($groupsToAdd as $gm) {
        $ins = $pdo->prepare(
            'INSERT INTO group_members (user_id, group_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        );
        $ins->execute([$toUserId, $gm['group_id'], $gm['role']]);
    }

    th_migrate_merge_preferences($pdo, $fromUserId, $toUserId, true);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nMigration applied successfully.\n";

if ($deleteSource) {
    $remainingNotes = th_migrate_notes_for_user($pdo, $fromUserId);
    if ($remainingNotes !== []) {
        fwrite(STDERR, "Error: source user still has notes; refusing --delete-source.\n");
        exit(1);
    }
    if (!account_delete_user_completely($pdo, $fromUserId)) {
        fwrite(STDERR, "Error: could not delete source user #{$fromUserId}.\n");
        exit(1);
    }
    echo "Deleted source user #{$fromUserId}.\n";
}

exit(0);
