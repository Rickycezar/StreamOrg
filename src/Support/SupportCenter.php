<?php
declare(strict_types=1);

/**
 * What needs a user's attention in Support, for the badge in the user menu
 * and on the Support page: questionnaires to answer, bug reports with news,
 * and messages with a reply they have not read.
 */
final class SupportCenter
{
    /** @return array{surveys:int, bugs:int, messages:int, total:int} */
    public static function attentionFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                (SELECT count(*) FROM surveys s
                  WHERE s.status = 'open' AND (s.closes_at IS NULL OR s.closes_at > now())
                    AND (s.audience = 'everyone' OR EXISTS (SELECT 1 FROM survey_targets t WHERE t.survey_id = s.id AND t.user_id = :user))
                    AND NOT EXISTS (SELECT 1 FROM survey_responses r WHERE r.survey_id = s.id AND r.user_id = :user)) AS surveys,
                (SELECT count(*) FROM bug_reports b WHERE b.user_id = :user AND (b.seen_by_user_at IS NULL OR b.seen_by_user_at < b.updated_at)) AS bugs,
                (SELECT count(*) FROM feedback_threads f WHERE f.user_id = :user AND f.user_unread) AS messages"
        );

        try {
            $stmt->execute(['user' => $userId]);
            $row = array_map('intval', $stmt->fetch());
        } catch (PDOException) {
            $row = ['surveys' => 0, 'bugs' => 0, 'messages' => 0];
        }

        return $row + ['total' => array_sum($row)];
    }
}
