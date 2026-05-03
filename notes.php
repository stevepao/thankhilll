<?php
/**
 * notes.php — Authorized note library with optional date and group filters (GET).
 *
 * Browse-only; writing stays on Today.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/group_helpers.php';
require_once __DIR__ . '/includes/note_library_card.php';
require_once __DIR__ . '/includes/user_preferences.php';
require_once __DIR__ . '/includes/note_media.php';
require_once __DIR__ . '/includes/note_thoughts.php';
require_once __DIR__ . '/includes/thought_reactions.php';
require_once __DIR__ . '/includes/thought_comments.php';
require_once __DIR__ . '/includes/user_timezone.php';

$userId = require_login();
$pdo = db();
$viewerTz = user_timezone_get($pdo, $userId);

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
/** @var list<string|int> $dateParams */
$dateParams = [];
switch ($dateFilter) {
    case 'today':
        $dateSql = 'AND n.entry_date = ?';
        $dateParams[] = user_local_today_ymd($viewerTz);
        break;
    case 'week':
        [$wStart, $wEnd] = user_local_week_bounds_ymd($viewerTz);
        $dateSql = 'AND n.entry_date >= ? AND n.entry_date <= ?';
        $dateParams[] = $wStart;
        $dateParams[] = $wEnd;
        break;
    case 'month':
        [$mStart, $mEnd] = user_local_calendar_month_bounds_ymd($viewerTz);
        $dateSql = 'AND n.entry_date >= ? AND n.entry_date <= ?';
        $dateParams[] = $mStart;
        $dateParams[] = $mEnd;
        break;
    case 'older':
        $dateSql = 'AND n.entry_date < ?';
        $dateParams[] = user_local_month_start_ymd($viewerTz);
        break;
    default:
        break;
}

$groupSql = '';
$params = array_merge([$userId, $userId, $userId], $dateParams);

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
$allVisibleThoughtIds = [];
foreach ($thoughtsByNote as $rows) {
    foreach ($rows as $tr) {
        $allVisibleThoughtIds[] = (int) $tr['id'];
    }
}
$reactionByThought = thought_reactions_grouped_by_thought($pdo, $allVisibleThoughtIds, $userId);

$noteSharedMap = [];
foreach (array_column($notes, 'id') as $nidKey) {
    $noteSharedMap[(int) $nidKey] = note_is_shared_with_any_group($pdo, (int) $nidKey);
}
$thoughtIdsForCommentsFetch = [];
foreach ($thoughtsByNote as $nidKey => $rows) {
    if (!($noteSharedMap[(int) $nidKey] ?? false)) {
        continue;
    }
    foreach ($rows as $trRow) {
        if (empty($trRow['is_private'])) {
            $thoughtIdsForCommentsFetch[] = (int) $trRow['id'];
        }
    }
}
$thoughtCommentsByThought = $thoughtIdsForCommentsFetch !== []
    ? thought_comments_grouped_by_thought($pdo, $thoughtIdsForCommentsFetch)
    : [];

$commentsRedirectBase = '/notes.php';
if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    $commentsRedirectBase .= '?' . $_SERVER['QUERY_STRING'];
}

$groupsForFilter = groups_for_user_with_counts($pdo, $userId);

$hasActiveFilters = ($dateExplicit && $dateFilter !== '')
    || ($groupExplicit && (
        ($groupRaw !== '' && $groupRaw !== '0')
        || $groupRaw === 'mine'
        || ($groupScope === 'group' && $groupScopeId > 0)
    ));

$pageTitle = 'Notes';
$currentNav = 'notes';

$extraStylesheets = ['/public/tailwind.css'];
$bodyClass = 'tn-bg-tn-bg';
$mainClass = 'main tn-notes-shell';
$topBarExtraClass =
    'tn-border-b tn-border-stone-200/50 tn-bg-tn-surface/95 tn-backdrop-blur-sm tn-shadow-[0_1px_0_0_rgb(0,0,0,0.03)]';

require_once __DIR__ . '/header.php';
?>

            <div class="tn-max-w-readable tn-w-full tn-mx-auto tn-px-4 tn-py-6 sm:tn-px-6 tn-space-y-8 tn-min-h-0">
            <form
                class="notes-filters tn-space-y-4 tn-pb-6 tn-mb-2 tn-border-b tn-border-stone-200/40"
                method="get"
                action="/notes.php"
                aria-label="Filter notes"
            >
                <div class="notes-filters__row tn-flex tn-flex-col tn-gap-1.5 sm:tn-max-w-xs">
                    <label class="notes-filters__label tn-normal-case tn-text-sm tn-font-medium tn-text-tn-muted" for="filter-date">When</label>
                    <select
                        class="notes-filters__select tn-block tn-w-full tn-rounded-xl tn-border-0 tn-bg-white tn-py-2.5 tn-px-3 tn-text-tn-ink tn-shadow-tn tn-text-[0.95rem] focus:tn-ring-2 focus:tn-ring-tn-accent/30 focus:tn-outline-none"
                        id="filter-date"
                        name="date"
                        onchange="this.form.submit()"
                    >
                        <option value="" <?= $dateFilter === '' ? 'selected' : '' ?>>Any time</option>
                        <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This week</option>
                        <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This month</option>
                        <option value="older" <?= $dateFilter === 'older' ? 'selected' : '' ?>>Older</option>
                    </select>
                </div>
                <div class="notes-filters__row tn-flex tn-flex-col tn-gap-1.5 sm:tn-max-w-xs">
                    <label class="notes-filters__label tn-normal-case tn-text-sm tn-font-medium tn-text-tn-muted" for="filter-group">Scope</label>
                    <select
                        class="notes-filters__select tn-block tn-w-full tn-rounded-xl tn-border-0 tn-bg-white tn-py-2.5 tn-px-3 tn-text-tn-ink tn-shadow-tn tn-text-[0.95rem] focus:tn-ring-2 focus:tn-ring-tn-accent/30 focus:tn-outline-none"
                        id="filter-group"
                        name="group"
                        onchange="this.form.submit()"
                    >
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
                    <p class="notes-empty tn-text-tn-muted tn-text-[0.95rem] tn-leading-relaxed tn-mt-1">Nothing matches these filters.</p>
                <?php else: ?>
                    <p class="notes-empty tn-text-tn-muted tn-text-[0.95rem] tn-leading-relaxed tn-mt-1">No notes yet.</p>
                <?php endif; ?>
            <?php else: ?>
                <ul class="notes-library tn-flex tn-flex-col tn-gap-5 tn-list-none tn-m-0 tn-p-0">
                    <?php foreach ($notes as $note): ?>
                        <?php
                        $nid = (int) $note['id'];
                        note_library_card_render(
                            $note,
                            $userId,
                            $thoughtsByNote[$nid] ?? [],
                            $photosByNote[$nid] ?? [],
                            $reactionByThought,
                            $thoughtCommentsByThought,
                            $noteSharedMap[$nid] ?? false,
                            $commentsRedirectBase,
                            $viewerTz,
                            true,
                        );
                        ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            </div>

            <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>
            <script src="<?= e(asset_url('/reactions/reactions.js')) ?>"></script>
            <script>
                (function () {
                    if (window.mountThoughtReactions) {
                        window.mountThoughtReactions({
                            csrfToken: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                        });
                    }
                })();
            </script>
            <div id="thought-reaction-picker" class="thought-reaction-picker-wrap" hidden></div>

<?php require_once __DIR__ . '/footer.php'; ?>
