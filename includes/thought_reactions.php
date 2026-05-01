<?php
/**
 * includes/thought_reactions.php — Emoji reactions for visible thoughts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

/** @return array{ok:true,value:string}|array{ok:false,error:string} */
function thought_reaction_validate_emoji(mixed $raw): array
{
    if (!is_string($raw)) {
        return ['ok' => false, 'error' => 'Invalid emoji.'];
    }

    $emoji = trim($raw);
    if ($emoji === '') {
        return ['ok' => false, 'error' => 'Choose an emoji.'];
    }

    if (\class_exists(\Normalizer::class)) {
        $normalized = \Normalizer::normalize($emoji, \Normalizer::FORM_C);
        if (\is_string($normalized) && $normalized !== '') {
            $emoji = $normalized;
        }
    }

    $len = function_exists('mb_strlen') ? mb_strlen($emoji, 'UTF-8') : strlen($emoji);
    if ($len > 32 || preg_match('/[\r\n\t]/u', $emoji) === 1) {
        return ['ok' => false, 'error' => 'Invalid emoji.'];
    }

    return ['ok' => true, 'value' => $emoji];
}

/**
 * @param list<int> $thoughtIds
 * @return array<int, list<array{emoji:string,count:int,reacted_by_me:bool}>>
 */
function thought_reactions_grouped_by_thought(PDO $pdo, array $thoughtIds, int $viewerUserId): array
{
    $thoughtIds = array_values(array_unique(array_filter(array_map('intval', $thoughtIds), static fn (int $id): bool => $id > 0)));
    if ($thoughtIds === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($thoughtIds), '?'));
    $sql = <<<SQL
        SELECT tr.thought_id,
               tr.emoji,
               COUNT(*) AS cnt,
               MAX(CASE WHEN tr.user_id = ? THEN 1 ELSE 0 END) AS reacted_by_me
        FROM thought_reactions tr
        WHERE tr.thought_id IN ($ph)
        GROUP BY tr.thought_id, tr.emoji
        ORDER BY tr.thought_id ASC, cnt DESC, tr.emoji ASC
        SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$viewerUserId, ...$thoughtIds]);

    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int) $row['thought_id'];
        if (!isset($out[$tid])) {
            $out[$tid] = [];
        }
        $out[$tid][] = [
            'emoji' => (string) $row['emoji'],
            'count' => (int) $row['cnt'],
            'reacted_by_me' => ((int) $row['reacted_by_me']) === 1,
        ];
    }

    return $out;
}

/**
 * @return array{ok:true,reactions:list<array{emoji:string,count:int,reacted_by_me:bool}>}|array{ok:false,error:string}
 */
function thought_reaction_toggle(PDO $pdo, int $thoughtId, int $userId, string $emoji): array
{
    if ($thoughtId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }

    $pdo->beginTransaction();
    try {
        // Serialize toggles for the same thought so concurrent requests cannot each read an incomplete row set.
        $lockThought = $pdo->prepare('SELECT id FROM note_thoughts WHERE id = ? LIMIT 1 FOR UPDATE');
        $lockThought->execute([$thoughtId]);
        if ((int) $lockThought->fetchColumn() <= 0) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => 'Thought not found.'];
        }

        $exists = $pdo->prepare(
            'SELECT id FROM thought_reactions WHERE thought_id = ? AND user_id = ? AND emoji = ? LIMIT 1'
        );
        $exists->execute([$thoughtId, $userId, $emoji]);
        $existingId = (int) $exists->fetchColumn();

        if ($existingId > 0) {
            $pdo->prepare('DELETE FROM thought_reactions WHERE id = ?')->execute([$existingId]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO thought_reactions (thought_id, user_id, emoji, created_at) VALUES (?, ?, ?, NOW())'
            );
            $ins->execute([$thoughtId, $userId, $emoji]);
        }

        $reactions = thought_reactions_grouped_by_thought($pdo, [$thoughtId], $userId)[$thoughtId] ?? [];
        $pdo->commit();

        return ['ok' => true, 'reactions' => $reactions];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('thought_reaction_toggle: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not update reaction.'];
    }
}
