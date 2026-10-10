<?php
declare(strict_types=1);

/**
 * A collab's cast (collab_streamers) and the lives planned from it.
 *
 * Saving a cast keeps what each person already had (confirmation, note)
 * and changes only who is in it and their roles. Lives still planned from
 * the collab follow it: their collaborators become the collab's cast, and
 * the credit in their title ("ft. @a, @b", with the user's own word for
 * "ft.") is swapped for the new one, placed before the hashtags the way
 * the content form places it. Lives that went live, ended or were
 * cancelled are left as they were.
 */
final class CollabCast
{
    /**
     * Sets a collab's cast to these streamers (the user's own only), each
     * with its role from $roles (streamer id => role, guest by default).
     *
     * @param list<int|string> $ids
     * @param array<int|string, string> $roles
     */
    public static function save(PDO $pdo, int $collabId, int $userId, array $ids, array $roles): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));

        if ($ids === []) {
            $pdo->prepare('DELETE FROM collab_streamers WHERE collab_id = ?')->execute([$collabId]);
            return;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM collab_streamers WHERE collab_id = ? AND streamer_id NOT IN ({$marks})")->execute([$collabId, ...$ids]);

        $upsert = $pdo->prepare(
            "INSERT INTO collab_streamers (collab_id, streamer_id, role, confirmation)
             SELECT ?, s.id, ?, 'invited' FROM streamers s WHERE s.id = ? AND s.user_id = ?
             ON CONFLICT (collab_id, streamer_id) DO UPDATE SET role = EXCLUDED.role"
        );

        foreach ($ids as $streamerId) {
            $role = (string) ($roles[$streamerId] ?? 'guest');
            $upsert->execute([$collabId, in_array($role, CollabController::ROLES, true) ? $role : 'guest', $streamerId, $userId]);
        }
    }

    /**
     * The cast's names, in the order the credit lists them.
     *
     * @return list<string>
     */
    public static function names(PDO $pdo, int $collabId): array
    {
        $stmt = $pdo->prepare(
            'SELECT st.name FROM collab_streamers cs JOIN streamers st ON st.id = cs.streamer_id WHERE cs.collab_id = ? ORDER BY st.name'
        );
        $stmt->execute([$collabId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Brings the lives still planned from a collab in line with its cast:
     * their collaborators, and their title credit (from $before, the names
     * the credit had, to the cast's names now).
     *
     * @param list<string> $before
     * @return int how many lives changed
     */
    public static function followPlanned(PDO $pdo, int $collabId, int $userId, array $before): int
    {
        $stmt = $pdo->prepare("SELECT id, title FROM streams WHERE collab_id = ? AND user_id = ? AND status = 'planned'");
        $stmt->execute([$collabId, $userId]);
        $streams = $stmt->fetchAll();

        if ($streams === []) {
            return 0;
        }

        $prefix = ContentDefaults::collabPrefix($userId);
        $now    = self::names($pdo, $collabId);
        $old    = self::credit($prefix, $before);
        $new    = self::credit($prefix, $now);

        foreach ($streams as $stream) {
            $streamId = (int) $stream['id'];

            $pdo->prepare(
                'DELETE FROM stream_collaborators sc WHERE sc.stream_id = ?
                    AND NOT EXISTS (SELECT 1 FROM collab_streamers cs WHERE cs.collab_id = ? AND cs.streamer_id = sc.streamer_id)'
            )->execute([$streamId, $collabId]);

            $pdo->prepare(
                'INSERT INTO stream_collaborators (stream_id, streamer_id, role, confirmation, note)
                 SELECT ?, cs.streamer_id, cs.role, cs.confirmation, cs.note FROM collab_streamers cs WHERE cs.collab_id = ?
                 ON CONFLICT (stream_id, streamer_id) DO UPDATE SET role = EXCLUDED.role'
            )->execute([$streamId, $collabId]);

            $title = self::retitle((string) $stream['title'], $old, $new);

            if ($title !== (string) $stream['title']) {
                $pdo->prepare('UPDATE streams SET title = ?, updated_at = now() WHERE id = ?')->execute([$title, $streamId]);
            }
        }

        return count($streams);
    }

    /** "ft. @A, @B" (with the user's word, or none); '' without names. */
    public static function credit(string $prefix, array $names): string
    {
        if ($names === []) {
            return '';
        }

        $handles = array_map(static fn (string $n): string => '@' . preg_replace('/\s+/u', '', $n), $names);

        return ltrim(trim($prefix) . ' ' . implode(', ', $handles));
    }

    /**
     * A title with its credit swapped: the old credit replaced where it is,
     * otherwise the new one put before the trailing hashtags (or at the
     * end); with no new credit, the old one taken out.
     */
    public static function retitle(string $title, string $old, string $new): string
    {
        if ($old !== '' && str_contains($title, $old)) {
            $title = str_replace($old, $new, $title);
        } elseif ($new !== '' && !str_contains($title, $new)) {
            $title = preg_match('/^(.*?)(\s*(?:#\S+\s*)+)$/su', $title, $m)
                ? rtrim($m[1]) . ' ' . $new . ' ' . trim($m[2])
                : rtrim($title) . ' ' . $new;
        }

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $title));
    }
}
