<?php
/**
 * notes.php — Authorized note library with optional date and group filters (GET).
 *
 * Browse-only; writing stays on Today.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/note_preview.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_thoughts.php';

$userId = require_login();
$pdo = db();

$dateExplicit = array_key_exists('date', $_GET);
$groupExplicit = array_key_exists('group', $_GET);

$dateRaw = $_GET['date'] ?? '';
$dateFilter = '';
if (is_string($dateRaw) && in_array($dateRaw, ['today', 'week', 'month', 'older'], true)) {
    $dateFilter = $dateRaw;
}

$groupRaw = $_GET['group'] ?? '';
$groupScope = 'all';
$groupScopeId = 0;

if (!$groupExplicit) {
    $prefs = user_preferences_load($pdo, $userId);
    if (($prefs['notes_default_scope'] ?? 'all') === 'mine') {
        $groupScope = 'mine';
    }
}

if ($groupRaw === 'mine') {
    $groupScope = 'mine';
} elseif (is_string($groupRaw) && ctype_digit($groupRaw)) {
    $gid = (int) $groupRaw;
    if ($gid > 0 && user_is_group_member($pdo, $userId, $gid)) {
        $groupScope = 'group';
        $groupScopeId = $gid;
    }
}

$dateSql = '';
switch ($dateFilter) {
    case 'today':
        $dateSql = 'AND n.entry_date = CURDATE()';
        break;
    case 'week':
        $dateSql = 'AND n.entry_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
            AND n.entry_date < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)';
        break;
    case 'month':
        $dateSql = 'AND YEAR(n.entry_date) = YEAR(CURDATE()) AND MONTH(n.entry_date) = MONTH(CURDATE())';
        break;
    case 'older':
        $dateSql = 'AND n.entry_date < DATE_FORMAT(CURDATE(), \'%Y-%m-01\')';
        break;
    default:
        break;
}

$groupSql = '';
$params = [$userId, $userId, $userId];

if ($groupScope === 'mine') {
    $groupSql = 'AND n.user_id = ?';
    $params[] = $userId;
} elseif ($groupScope === 'group' && $groupScopeId > 0) {
    $groupSql = 'AND EXISTS (
        SELECT 1
        FROM note_groups ngx
        INNER JOIN group_members gmx ON gmx.group_id = ngx.group_id AND gmx.user_id = ?
        WHERE ngx.note_id = n.id AND ngx.group_id = ?
    )';
    $params[] = $userId;
    $params[] = $groupScopeId;
}

$sql = <<<SQL
SELECT
    n.id,
    n.entry_date,
    n.created_at,
    n.user_id,
    MAX(COALESCE(u.display_name, '')) AS author_name,
    GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ') AS shared_group_names
FROM notes n
LEFT JOIN users u ON u.id = n.user_id
LEFT JOIN note_groups ng ON ng.note_id = n.id
LEFT JOIN group_members gm ON gm.group_id = ng.group_id AND gm.user_id = ?
LEFT JOIN `groups` g ON g.id = ng.group_id AND gm.user_id IS NOT NULL
WHERE (
    n.user_id = ?
    OR EXISTS (
        SELECT 1
        FROM note_groups ng_auth
        INNER JOIN group_members gm_auth ON gm_auth.group_id = ng_auth.group_id AND gm_auth.user_id = ?
        WHERE ng_auth.note_id = n.id
    )
)
{$dateSql}
{$groupSql}
GROUP BY n.id, n.entry_date, n.created_at, n.user_id
ORDER BY n.entry_date DESC, n.id DESC
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$photosByNote = note_media_grouped_by_note($pdo, array_column($notes, 'id'));
$thoughtsByNote = note_thoughts_grouped_by_note($pdo, array_column($notes, 'id'), $userId);

$groupsForFilter = groups_for_user_with_counts($pdo, $userId);

$hasActiveFilters = ($dateExplicit && $dateFilter !== '')
    || ($groupExplicit && (
        ($groupRaw !== '' && $groupRaw !== '0')
        || $groupRaw === 'mine'
        || ($groupScope === 'group' && $groupScopeId > 0)
    ));

$pageTitle = 'Notes';
$currentNav = 'notes';

require_once __DIR__ . '/header.php';
?>

            <form class="notes-filters" method="get" action="/notes.php" aria-label="Filter notes">
                <div class="notes-filters__row">
                    <label class="notes-filters__label" for="filter-date">When</label>
                    <select class="notes-filters__select" id="filter-date" name="date" onchange="this.form.submit()">
                        <option value="" <?= $dateFilter === '' ? 'selected' : '' ?>>Any time</option>
                        <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This week</option>
                        <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This month</option>
                        <option value="older" <?= $dateFilter === 'older' ? 'selected' : '' ?>>Older</option>
                    </select>
                </div>
                <div class="notes-filters__row">
                    <label class="notes-filters__label" for="filter-group">Scope</label>
                    <select class="notes-filters__select" id="filter-group" name="group" onchange="this.form.submit()">
                        <option value="" <?= $groupScope === 'all' ? 'selected' : '' ?>>All notes</option>
                        <option value="mine" <?= $groupScope === 'mine' ? 'selected' : '' ?>>Just mine</option>
                        <?php foreach ($groupsForFilter as $g): ?>
                            <option
                                value="<?= (int) $g['id'] ?>"
                                <?= ($groupScope === 'group' && $groupScopeId === (int) $g['id']) ? 'selected' : '' ?>
                            ><?= e($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if (count($notes) === 0): ?>
                <?php if ($hasActiveFilters): ?>
                    <p class="notes-empty">Nothing matches these filters.</p>
                <?php else: ?>
                    <p class="notes-empty">No notes yet.</p>
                <?php endif; ?>
            <?php else: ?>
                <ul class="notes-library">
                    <?php foreach ($notes as $note): ?>
                        <?php
                        $authorId = (int) $note['user_id'];
                        $isMine = ($authorId === $userId);
                        $authorLabel = trim((string) ($note['author_name'] ?? ''));
                        if ($authorLabel === '') {
                            $authorLabel = 'Someone';
                        }
                        $groupsLabel = trim((string) ($note['shared_group_names'] ?? ''));
                        $ts = strtotime((string) $note['entry_date']);
                        $dateLabel = $ts ? date('M j, Y', $ts) : '';
                        $nid = (int) $note['id'];
                        $thoughtRows = $thoughtsByNote[$nid] ?? [];
                        $previewBlob = '';
                        foreach ($thoughtRows as $tr) {
                            $previewBlob .= ($previewBlob !== '' ? "\n\n" : '') . trim($tr['body']);
                        }
                        $preview = note_plain_preview($previewBlob, 220);
                        $thumbs = $photosByNote[$nid] ?? [];
                        ?>
                        <li class="notes-library__card">
                            <a class="notes-library__card-main" href="/note.php?id=<?= $nid ?>">
                                <time
                                    class="notes-library__date"
                                    datetime="<?= e((string) $note['entry_date']) ?>"
                                ><?= e($dateLabel) ?></time>
                                <?php if (!$isMine): ?>
                                    <p class="notes-library__author"><?= e($authorLabel) ?></p>
                                <?php endif; ?>
                                <?php if ($groupsLabel !== ''): ?>
                                    <p class="notes-library__groups">Shared in <?= e($groupsLabel) ?></p>
                                <?php endif; ?>
                                <?php if (count($thumbs) > 0): ?>
                                    <ul class="today-note-photos today-note-photos--notes">
                                        <?php foreach ($thumbs as $thumb): ?>
                                            <li class="today-note-photos__item">
                                                <img
                                                    src="/media/note_photo.php?id=<?= (int) $thumb['id'] ?>"
                                                    alt=""
                                                    class="today-note-photos__img"
                                                    loading="lazy"
                                                    width="<?= (int) $thumb['width'] ?>"
                                                    height="<?= (int) $thumb['height'] ?>"
                                                >
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <p class="notes-library__preview"><?= e($preview) ?></p>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
