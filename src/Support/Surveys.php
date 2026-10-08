<?php
declare(strict_types=1);

/**
 * Questionnaires: administrators build them (sections with questions of
 * several kinds), choose who answers (everyone or chosen users) and open
 * them; each user answers once and can change their answers while the
 * questionnaire is open; administrators see the results, question by
 * question and person by person. Opening one tells its audience through
 * a notification. See 044_support.sql.
 *
 * Every text is kept per language ({"pt-BR": "…", "en": "…"}): each person
 * reads their own language, otherwise English, otherwise whichever was
 * written (text()). Choices carry a key ("c1", "c2"…) and answers keep the
 * key, so answers given in different languages count together.
 *
 * Question kinds and their options:
 *   short_text, long_text   free text
 *   radio, select           one of options.choices
 *   checkbox                any of options.choices
 *   scale                   a whole number from options.min to options.max,
 *                           with labels for both ends
 *   yes_no                  yes or no
 *   number, date            a number, a date
 */
final class Surveys
{
    public const TYPES     = ['short_text', 'long_text', 'radio', 'checkbox', 'select', 'scale', 'yes_no', 'number', 'date'];
    public const CHOICES   = ['radio', 'checkbox', 'select'];
    public const STATUSES  = ['draft', 'open', 'closed'];
    public const LINK      = '/support/survey?id=';
    public const MAX_QUESTIONS = 100;

    /**
     * A text in the reader's language: theirs, otherwise English, otherwise
     * the first one written.
     *
     * @param array<string, string>|string|null $texts
     */
    public static function text(array|string|null $texts, ?string $locale = null): string
    {
        if (!is_array($texts)) {
            return (string) $texts;
        }

        foreach ([$locale ?? Lang::locale(), 'en'] as $candidate) {
            if (($texts[$candidate] ?? '') !== '') {
                return (string) $texts[$candidate];
            }
        }

        foreach ($texts as $text) {
            if ((string) $text !== '') {
                return (string) $text;
            }
        }

        return '';
    }

    /**
     * Creates or updates a questionnaire from the builder. Sections and
     * questions keep their ids, so answers already given to a question that
     * stays are kept; removed ones go with their answers.
     *
     * @param array<string, mixed> $data title, description (per language), audience, users, closes_at, groups[{id, title, description, questions[…]}]
     * @return int the questionnaire's id
     * @throws UserError naming what is wrong
     */
    public static function save(int $adminId, ?int $surveyId, array $data): int
    {
        $title = self::cleanText($data['title'] ?? [], 140, true);

        if ($title === []) {
            throw new UserError(__('ui.message.survey_title_needed'));
        }

        $description = self::cleanText($data['description'] ?? [], 2000);
        $audience    = ($data['audience'] ?? '') === 'users' ? 'users' : 'everyone';
        $users       = array_values(array_unique(array_filter(array_map('intval', (array) ($data['users'] ?? [])))));
        $closesAt    = trim((string) ($data['closes_at'] ?? '')) !== '' ? self::date((string) $data['closes_at']) : null;
        $groups      = self::cleanGroups((array) ($data['groups'] ?? []));

        if ($audience === 'users' && $users === []) {
            throw new UserError(__('ui.message.survey_users_needed'));
        }

        return Database::transaction(static function (PDO $pdo) use ($adminId, $surveyId, $title, $description, $audience, $users, $closesAt, $groups): int {
            $json = static fn (array $texts): string => json_encode($texts ?: new stdClass(), JSON_UNESCAPED_UNICODE);

            if ($surveyId === null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO surveys (title, description, audience, closes_at, created_by) VALUES (CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?) RETURNING id'
                );
                $stmt->execute([$json($title), $json($description), $audience, $closesAt, $adminId]);
                $surveyId = (int) $stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE surveys SET title = CAST(? AS jsonb), description = CAST(? AS jsonb), audience = ?, closes_at = ?, updated_at = now() WHERE id = ?'
                );
                $stmt->execute([$json($title), $json($description), $audience, $closesAt, $surveyId]);

                if ($stmt->rowCount() === 0) {
                    throw new UserError(__('ui.message.survey_not_found'));
                }
            }

            $pdo->prepare('DELETE FROM survey_targets WHERE survey_id = ?')->execute([$surveyId]);

            if ($audience === 'users') {
                $pdo->prepare(
                    'INSERT INTO survey_targets (survey_id, user_id) SELECT ?, id FROM users WHERE id = ANY(CAST(? AS bigint[]))'
                )->execute([$surveyId, '{' . implode(',', $users) . '}']);
            }

            $keepGroups    = [];
            $keepQuestions = [];
            $position      = 0;

            foreach ($groups as $g => $group) {
                $groupId = self::upsert($pdo, 'survey_groups', $group['id'], $surveyId,
                    ['title' => $json($group['title']), 'description' => $json($group['description']), 'position' => $g]);
                $keepGroups[] = $groupId;

                foreach ($group['questions'] as $question) {
                    $keepQuestions[] = self::upsert($pdo, 'survey_questions', $question['id'], $surveyId, [
                        'group_id' => $groupId,
                        'type'     => $question['type'],
                        'label'    => $json($question['label']),
                        'help'     => $json($question['help']),
                        'required' => $question['required'] ? 'true' : 'false',
                        'options'  => json_encode($question['options'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
                        'position' => $position++,
                    ]);
                }
            }

            $pdo->prepare('DELETE FROM survey_questions WHERE survey_id = ? AND NOT (id = ANY(CAST(? AS bigint[])))')
                ->execute([$surveyId, '{' . implode(',', $keepQuestions) . '}']);
            $pdo->prepare('DELETE FROM survey_groups WHERE survey_id = ? AND NOT (id = ANY(CAST(? AS bigint[])))')
                ->execute([$surveyId, '{' . implode(',', $keepGroups) . '}']);

            return $surveyId;
        });
    }

    /**
     * Opens a questionnaire for answers and tells its audience (the first
     * time it opens), each in their language; or closes it.
     *
     * @throws UserError when it has no questions to answer
     */
    public static function setStatus(int $surveyId, string $status): void
    {
        $survey = self::find($surveyId);

        if ($survey === null) {
            throw new UserError(__('ui.message.survey_not_found'));
        }

        if ($status === 'open' && self::questionCount($surveyId) === 0) {
            throw new UserError(__('ui.message.survey_no_questions'));
        }

        $first = $status === 'open' && $survey['opened_at'] === null;

        Database::connection()->prepare(
            "UPDATE surveys SET status = ?, updated_at = now(), opened_at = CASE WHEN ? = 'open' THEN coalesce(opened_at, now()) ELSE opened_at END WHERE id = ?"
        )->execute([$status, $status, $surveyId]);

        if ($first) {
            $titles = [];

            foreach (Lang::available() as $locale) {
                $titles[$locale] = self::text($survey['title'], $locale);
            }

            Notifications::system(self::audienceIds($surveyId), 'survey_new', [$titles], self::LINK . $surveyId, 'news');
        }
    }

    public static function delete(int $surveyId): void
    {
        Database::connection()->prepare('DELETE FROM surveys WHERE id = ?')->execute([$surveyId]);
    }

    /**
     * A questionnaire's row, with its texts per language.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $surveyId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM surveys WHERE id = ?');
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch();

        if ($survey === false) {
            return null;
        }

        $survey['title']       = self::decode($survey['title']);
        $survey['description'] = self::decode($survey['description']);

        return $survey;
    }

    /**
     * A questionnaire with its sections and questions, in order, every text
     * per language, and its chosen users (for the builder).
     *
     * @return array<string, mixed>|null
     */
    public static function full(int $surveyId): ?array
    {
        $survey = self::find($surveyId);

        if ($survey === null) {
            return null;
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id, title, description FROM survey_groups WHERE survey_id = ? ORDER BY position, id');
        $stmt->execute([$surveyId]);
        $groups = [];

        foreach ($stmt->fetchAll() as $g) {
            $groups[(int) $g['id']] = ['id' => (int) $g['id'], 'title' => self::decode($g['title']), 'description' => self::decode($g['description']), 'questions' => []];
        }

        $stmt = $pdo->prepare('SELECT id, group_id, type, label, help, required, options FROM survey_questions WHERE survey_id = ? ORDER BY position, id');
        $stmt->execute([$surveyId]);

        foreach ($stmt->fetchAll() as $q) {
            if (!isset($groups[(int) $q['group_id']])) {
                continue;
            }

            $groups[(int) $q['group_id']]['questions'][] = [
                'id'       => (int) $q['id'],
                'type'     => (string) $q['type'],
                'label'    => self::decode($q['label']),
                'help'     => self::decode($q['help']),
                'required' => (bool) $q['required'],
                'options'  => json_decode((string) $q['options'], true) ?: [],
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, coalesce(u.display_name, u.username) AS name, u.username FROM survey_targets t JOIN users u ON u.id = t.user_id
              WHERE t.survey_id = ? ORDER BY lower(coalesce(u.display_name, u.username))'
        );
        $stmt->execute([$surveyId]);

        $survey['groups'] = array_values($groups);
        $survey['users']  = $stmt->fetchAll();

        return $survey;
    }

    /**
     * A questionnaire as one person reads it: every text in their language
     * (see text()), and each choice as {key, label}.
     *
     * @return array<string, mixed>
     */
    public static function localized(array $survey, ?string $locale = null): array
    {
        $survey['title']       = self::text($survey['title'], $locale);
        $survey['description'] = self::text($survey['description'], $locale);

        foreach ($survey['groups'] as &$group) {
            $group['title']       = self::text($group['title'], $locale);
            $group['description'] = self::text($group['description'], $locale);

            foreach ($group['questions'] as &$q) {
                $q['label'] = self::text($q['label'], $locale);
                $q['help']  = self::text($q['help'], $locale);

                if (isset($q['options']['choices'])) {
                    $q['options']['choices'] = array_map(
                        static fn (array $c): array => ['key' => (string) $c['key'], 'label' => self::text($c['text'] ?? [], $locale)],
                        (array) $q['options']['choices']
                    );
                }

                foreach (['min_label', 'max_label'] as $end) {
                    if (isset($q['options'][$end])) {
                        $q['options'][$end] = self::text($q['options'][$end], $locale);
                    }
                }
            }
            unset($q);
        }
        unset($group);

        return $survey;
    }

    /**
     * The questionnaires a user is asked to answer (open, for everyone or
     * for them), and the ones they already answered, newest first; titles in
     * their language.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT s.id, s.title, s.description, s.status, s.closes_at, s.opened_at, r.submitted_at, r.updated_at AS answered_updated_at,
                    (SELECT count(*) FROM survey_questions q WHERE q.survey_id = s.id) AS questions,
                    (s.status = 'open' AND (s.closes_at IS NULL OR s.closes_at > now())) AS answerable
               FROM surveys s
          LEFT JOIN survey_responses r ON r.survey_id = s.id AND r.user_id = :user
              WHERE s.status <> 'draft'
                AND (s.audience = 'everyone' OR EXISTS (SELECT 1 FROM survey_targets t WHERE t.survey_id = s.id AND t.user_id = :user))
                AND (r.id IS NOT NULL OR (s.status = 'open' AND (s.closes_at IS NULL OR s.closes_at > now())))
           ORDER BY (r.id IS NULL) DESC, coalesce(s.opened_at, s.created_at) DESC"
        );
        $stmt->execute(['user' => $userId]);

        return array_map(static function (array $s): array {
            $s['title']       = self::text(self::decode($s['title']));
            $s['description'] = self::text(self::decode($s['description']));

            return $s;
        }, $stmt->fetchAll());
    }

    /** How many open questionnaires a user still has to answer. */
    public static function pendingFor(int $userId): int
    {
        return count(array_filter(self::forUser($userId), static fn (array $s): bool => $s['answerable'] && $s['submitted_at'] === null));
    }

    /** Whether this user may see this questionnaire (it is not a draft, and is for them). */
    public static function visibleTo(array $survey, int $userId): bool
    {
        if ($survey['status'] === 'draft') {
            return false;
        }

        if ($survey['audience'] === 'everyone') {
            return true;
        }

        $stmt = Database::connection()->prepare('SELECT 1 FROM survey_targets WHERE survey_id = ? AND user_id = ?');
        $stmt->execute([$survey['id'], $userId]);

        return (bool) $stmt->fetchColumn();
    }

    /** Whether answers are still taken. */
    public static function answerable(array $survey): bool
    {
        return $survey['status'] === 'open' && ($survey['closes_at'] === null || strtotime((string) $survey['closes_at']) > time());
    }

    /**
     * A user's answers to a questionnaire, by question id.
     *
     * @return array<int, mixed>
     */
    public static function answersOf(int $surveyId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.question_id, a.value FROM survey_answers a JOIN survey_responses r ON r.id = a.response_id
              WHERE r.survey_id = ? AND r.user_id = ?'
        );
        $stmt->execute([$surveyId, $userId]);
        $out = [];

        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['question_id']] = json_decode((string) $row['value'], true);
        }

        return $out;
    }

    /**
     * Keeps a user's answers (replacing earlier ones), each checked against
     * its question.
     *
     * @param array<int|string, mixed> $input question id => answer (choice keys for choices)
     * @return array<int, string> question id => what is wrong with it; empty when saved
     * @throws UserError when the questionnaire is not open to this user
     */
    public static function submit(int $userId, int $surveyId, array $input): array
    {
        $survey = self::full($surveyId);

        if ($survey === null || !self::visibleTo($survey, $userId)) {
            throw new UserError(__('ui.message.survey_not_found'));
        }

        if (!self::answerable($survey)) {
            throw new UserError(__('ui.message.survey_closed'));
        }

        $answers = [];
        $errors  = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $raw = $input[$q['id']] ?? $input[(string) $q['id']] ?? null;
                [$value, $error] = self::checkAnswer($q, $raw);

                if ($error !== null) {
                    $errors[$q['id']] = $error;
                } elseif ($value !== null) {
                    $answers[$q['id']] = $value;
                }
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        Database::transaction(static function (PDO $pdo) use ($userId, $surveyId, $answers): void {
            $stmt = $pdo->prepare(
                'INSERT INTO survey_responses (survey_id, user_id) VALUES (?, ?)
                 ON CONFLICT (survey_id, user_id) DO UPDATE SET updated_at = now() RETURNING id'
            );
            $stmt->execute([$surveyId, $userId]);
            $responseId = (int) $stmt->fetchColumn();

            $pdo->prepare('DELETE FROM survey_answers WHERE response_id = ?')->execute([$responseId]);
            $insert = $pdo->prepare('INSERT INTO survey_answers (response_id, question_id, value) VALUES (?, ?, CAST(? AS jsonb))');

            foreach ($answers as $questionId => $value) {
                $insert->execute([$responseId, $questionId, json_encode($value, JSON_UNESCAPED_UNICODE)]);
            }
        });

        return [];
    }

    /**
     * The results, in the reader's language: for each question what people
     * answered (counts for choices, yes/no and scales, with the average of a
     * scale or number; every text answer with who wrote it), and the list of
     * responses. Choice counts are by key, so every language adds up.
     *
     * @return array{survey:array, responses:list<array>, questions:list<array>}
     */
    public static function results(int $surveyId, ?string $locale = null): array
    {
        $survey = self::localized((array) self::full($surveyId), $locale);
        $pdo    = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT r.id, r.user_id, r.submitted_at, r.updated_at, coalesce(u.display_name, u.username) AS name, u.username
               FROM survey_responses r LEFT JOIN users u ON u.id = r.user_id
              WHERE r.survey_id = ? ORDER BY r.submitted_at'
        );
        $stmt->execute([$surveyId]);
        $responses = $stmt->fetchAll();
        $names     = array_column($responses, 'name', 'id');

        $stmt = $pdo->prepare(
            'SELECT a.response_id, a.question_id, a.value FROM survey_answers a JOIN survey_responses r ON r.id = a.response_id WHERE r.survey_id = ?'
        );
        $stmt->execute([$surveyId]);
        $byQuestion = [];
        $byResponse = [];

        foreach ($stmt->fetchAll() as $row) {
            $value = json_decode((string) $row['value'], true);
            $byQuestion[(int) $row['question_id']][(int) $row['response_id']] = $value;
            $byResponse[(int) $row['response_id']][(int) $row['question_id']] = $value;
        }

        $questions = [];

        foreach ($survey['groups'] as $group) {
            foreach ($group['questions'] as $q) {
                $values  = $byQuestion[$q['id']] ?? [];
                $summary = ['answered' => count($values)];

                if (in_array($q['type'], self::CHOICES, true) || $q['type'] === 'yes_no' || $q['type'] === 'scale') {
                    $labels = match ($q['type']) {
                        'yes_no' => ['yes' => __('ui.support.yes'), 'no' => __('ui.support.no')],
                        'scale'  => array_combine(
                            array_map('strval', range((int) ($q['options']['min'] ?? 1), (int) ($q['options']['max'] ?? 5))),
                            array_map('strval', range((int) ($q['options']['min'] ?? 1), (int) ($q['options']['max'] ?? 5)))
                        ),
                        default  => array_column((array) ($q['options']['choices'] ?? []), 'label', 'key'),
                    };
                    $counts = array_fill_keys(array_map('strval', array_keys($labels)), 0);

                    foreach ($values as $value) {
                        foreach ((array) $value as $one) {
                            if (array_key_exists((string) $one, $counts)) {
                                $counts[(string) $one]++;
                            }
                        }
                    }

                    $summary['counts'] = $counts;
                    $summary['labels'] = array_combine(array_map('strval', array_keys($labels)), array_values($labels));
                }

                if (in_array($q['type'], ['scale', 'number'], true) && $values !== []) {
                    $numbers = array_map('floatval', $values);
                    $summary['average'] = round(array_sum($numbers) / count($numbers), 2);
                    $summary['min'] = min($numbers);
                    $summary['max'] = max($numbers);
                }

                if (in_array($q['type'], ['short_text', 'long_text', 'number', 'date'], true)) {
                    $summary['texts'] = [];

                    foreach ($values as $responseId => $value) {
                        $summary['texts'][] = ['name' => (string) ($names[$responseId] ?? '?'), 'value' => (string) $value];
                    }
                }

                $questions[] = $q + ['group' => $group['title'], 'summary' => $summary];
            }
        }

        foreach ($responses as &$response) {
            $response['answers'] = $byResponse[(int) $response['id']] ?? [];
        }
        unset($response);

        return ['survey' => $survey, 'responses' => $responses, 'questions' => $questions];
    }

    /**
     * An answer as words, for the spreadsheet export: choice keys become
     * their labels.
     */
    public static function answerText(array $question, mixed $value): string
    {
        $labels = $question['summary']['labels'] ?? [];
        $parts  = array_map(static fn (mixed $v): string => (string) ($labels[(string) $v] ?? $v), (array) $value);

        return implode('; ', $parts);
    }

    /**
     * The administrators' list: every questionnaire with how many answered
     * and how many it was for, its title in the reader's language and the
     * languages it is written in.
     *
     * @return list<array<string, mixed>>
     */
    public static function adminList(): array
    {
        $rows = Database::connection()->query(
            "SELECT s.*, (SELECT count(*) FROM survey_responses r WHERE r.survey_id = s.id) AS responses,
                    (SELECT count(*) FROM survey_questions q WHERE q.survey_id = s.id) AS questions,
                    CASE WHEN s.audience = 'everyone' THEN (SELECT count(*) FROM users WHERE is_active)
                         ELSE (SELECT count(*) FROM survey_targets t WHERE t.survey_id = s.id) END AS audience_size
               FROM surveys s ORDER BY (s.status = 'open') DESC, s.updated_at DESC"
        )->fetchAll();

        return array_map(static function (array $s): array {
            $s['texts'] = self::decode($s['title']);
            $s['title'] = self::text($s['texts']);
            $s['missing'] = self::missing((int) $s['id']);

            return $s;
        }, $rows);
    }

    /**
     * How many texts of a questionnaire are still missing in each language
     * (among the ones written in some language).
     *
     * @return array<string, int>
     */
    public static function missing(int $surveyId): array
    {
        $survey  = self::full($surveyId);
        $missing = array_fill_keys(Lang::available(), 0);

        if ($survey === null) {
            return $missing;
        }

        $count = static function (array $texts) use (&$missing): void {
            if (array_filter($texts, static fn ($t): bool => (string) $t !== '') === []) {
                return;
            }

            foreach (array_keys($missing) as $locale) {
                if (($texts[$locale] ?? '') === '') {
                    $missing[$locale]++;
                }
            }
        };

        $count($survey['title']);
        $count($survey['description']);

        foreach ($survey['groups'] as $group) {
            $count($group['title']);
            $count($group['description']);

            foreach ($group['questions'] as $q) {
                $count($q['label']);
                $count($q['help']);

                foreach ((array) ($q['options']['choices'] ?? []) as $choice) {
                    $count((array) ($choice['text'] ?? []));
                }

                foreach (['min_label', 'max_label'] as $end) {
                    $count((array) ($q['options'][$end] ?? []));
                }
            }
        }

        return $missing;
    }

    /**
     * Checks one answer against its question (choices by key).
     *
     * @return array{0:mixed, 1:?string} the value to keep (null for none), and what is wrong
     */
    public static function checkAnswer(array $q, mixed $raw): array
    {
        $keys  = array_map(static fn (array $c): string => (string) $c['key'], (array) ($q['options']['choices'] ?? []));
        $empty = $raw === null || $raw === '' || $raw === [];

        if ($empty) {
            return [null, $q['required'] ? __('ui.message.survey_answer_required') : null];
        }

        switch ($q['type']) {
            case 'short_text':
            case 'long_text':
                $text = trim(str_replace("\r\n", "\n", (string) (is_array($raw) ? '' : $raw)));
                $max  = $q['type'] === 'short_text' ? 300 : 5000;

                if ($text === '') {
                    return [null, $q['required'] ? __('ui.message.survey_answer_required') : null];
                }

                return [mb_substr($q['type'] === 'short_text' ? (string) preg_replace('/\s+/u', ' ', $text) : $text, 0, $max), null];

            case 'radio':
            case 'select':
                return in_array((string) $raw, $keys, true) ? [(string) $raw, null] : [null, __('ui.message.survey_answer_invalid')];

            case 'checkbox':
                $picked = array_values(array_intersect($keys, array_map('strval', (array) $raw)));

                return $picked !== [] ? [$picked, null] : [null, $q['required'] ? __('ui.message.survey_answer_required') : null];

            case 'scale':
                $min = (int) ($q['options']['min'] ?? 1);
                $max = (int) ($q['options']['max'] ?? 5);
                $n   = filter_var($raw, FILTER_VALIDATE_INT);

                return $n !== false && $n >= $min && $n <= $max ? [$n, null] : [null, __('ui.message.survey_answer_invalid')];

            case 'yes_no':
                return in_array($raw, ['yes', 'no'], true) ? [(string) $raw, null] : [null, __('ui.message.survey_answer_invalid')];

            case 'number':
                $n = str_replace(',', '.', trim((string) $raw));

                return is_numeric($n) ? [$n + 0, null] : [null, __('ui.message.survey_answer_number')];

            case 'date':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $raw));

                return $d !== false ? [$d->format('Y-m-d'), null] : [null, __('ui.message.survey_answer_invalid')];
        }

        return [null, null];
    }

    /**
     * A text per language from the builder, trimmed and cut, keeping only
     * the app's languages and the ones written. A plain string counts as the
     * current language.
     *
     * @return array<string, string>
     */
    public static function cleanText(mixed $value, int $max, bool $singleLine = false): array
    {
        if (!is_array($value)) {
            $value = [Lang::locale() => (string) $value];
        }

        $out = [];

        foreach (Lang::available() as $locale) {
            $text = trim(str_replace("\r\n", "\n", (string) ($value[$locale] ?? '')));

            if ($singleLine) {
                $text = (string) preg_replace('/\s+/u', ' ', $text);
            }

            if ($text !== '') {
                $out[$locale] = mb_substr($text, 0, $max);
            }
        }

        return $out;
    }

    /**
     * Sections and questions from the builder, checked and trimmed; choices
     * keep their keys (new ones get the next free key).
     *
     * @return list<array{id:?int, title:array, description:array, questions:list<array>}>
     * @throws UserError naming what is wrong
     */
    private static function cleanGroups(array $groups): array
    {
        $out   = [];
        $count = 0;

        foreach (array_values($groups) as $group) {
            if (!is_array($group)) {
                continue;
            }

            $questions = [];

            foreach (array_values((array) ($group['questions'] ?? [])) as $question) {
                if (!is_array($question)) {
                    continue;
                }

                $label = self::cleanText($question['label'] ?? [], 300, true);
                $type  = in_array($question['type'] ?? '', self::TYPES, true) ? (string) $question['type'] : 'short_text';

                if ($label === []) {
                    continue;
                }

                $options = [];

                if (in_array($type, self::CHOICES, true)) {
                    $choices = [];
                    $used    = [];

                    foreach ((array) ($question['options']['choices'] ?? []) as $choice) {
                        $text = self::cleanText(is_array($choice) ? ($choice['text'] ?? []) : $choice, 200, true);

                        if ($text === []) {
                            continue;
                        }

                        $key = is_array($choice) ? (string) ($choice['key'] ?? '') : '';
                        $choices[] = ['key' => preg_match('/^c\d{1,6}$/', $key) && !isset($used[$key]) ? $key : null, 'text' => $text];
                        if ($key !== '') {
                            $used[$key] = true;
                        }
                    }

                    if (count($choices) < 2) {
                        throw new UserError(sprintf(__('ui.message.survey_choices_needed'), self::text($label)));
                    }

                    $next = 1 + max([0, ...array_map(static fn (string $k): int => (int) substr($k, 1), array_keys($used))]);

                    foreach ($choices as &$choice) {
                        $choice['key'] ??= 'c' . $next++;
                    }
                    unset($choice);

                    $options['choices'] = array_slice($choices, 0, 50);
                } elseif ($type === 'scale') {
                    $options = [
                        'min'       => max(0, min(1, (int) ($question['options']['min'] ?? 1))),
                        'max'       => max(2, min(10, (int) ($question['options']['max'] ?? 5))),
                        'min_label' => self::cleanText($question['options']['min_label'] ?? [], 60, true),
                        'max_label' => self::cleanText($question['options']['max_label'] ?? [], 60, true),
                    ];
                }

                $questions[] = [
                    'id'       => filter_var($question['id'] ?? null, FILTER_VALIDATE_INT) ?: null,
                    'type'     => $type,
                    'label'    => $label,
                    'help'     => self::cleanText($question['help'] ?? [], 500, true),
                    'required' => !empty($question['required']),
                    'options'  => $options,
                ];

                if (++$count > self::MAX_QUESTIONS) {
                    throw new UserError(sprintf(__('ui.message.survey_too_many'), self::MAX_QUESTIONS));
                }
            }

            $title = self::cleanText($group['title'] ?? [], 140, true);

            if ($questions === [] && $title === []) {
                continue;
            }

            $out[] = [
                'id'          => filter_var($group['id'] ?? null, FILTER_VALIDATE_INT) ?: null,
                'title'       => $title,
                'description' => self::cleanText($group['description'] ?? [], 1000),
                'questions'   => $questions,
            ];
        }

        return $out;
    }

    /** Updates the row with this id when it belongs to the questionnaire, otherwise inserts one. */
    private static function upsert(PDO $pdo, string $table, ?int $id, int $surveyId, array $columns): int
    {
        $json = ['options', 'title', 'description', 'label', 'help'];
        $mark = static fn (string $c): string => in_array($c, $json, true) ? 'CAST(? AS jsonb)' : '?';

        if ($id !== null) {
            $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = " . $mark($c), array_keys($columns)));
            $stmt = $pdo->prepare("UPDATE {$table} SET {$sets} WHERE id = ? AND survey_id = ?");
            $stmt->execute([...array_values($columns), $id, $surveyId]);

            if ($stmt->rowCount() > 0) {
                return $id;
            }
        }

        $names = implode(', ', ['survey_id', ...array_keys($columns)]);
        $marks = implode(', ', ['?', ...array_map($mark, array_keys($columns))]);
        $stmt  = $pdo->prepare("INSERT INTO {$table} ({$names}) VALUES ({$marks}) RETURNING id");
        $stmt->execute([$surveyId, ...array_values($columns)]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, string> */
    private static function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) ? array_map('strval', $value) : [];
    }

    private static function questionCount(int $surveyId): int
    {
        $stmt = Database::connection()->prepare('SELECT count(*) FROM survey_questions WHERE survey_id = ?');
        $stmt->execute([$surveyId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private static function audienceIds(int $surveyId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT u.id FROM users u, surveys s WHERE s.id = ? AND u.is_active
                AND (s.audience = 'everyone' OR EXISTS (SELECT 1 FROM survey_targets t WHERE t.survey_id = s.id AND t.user_id = u.id))"
        );
        $stmt->execute([$surveyId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function date(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
        } catch (Exception) {
            return null;
        }
    }
}
