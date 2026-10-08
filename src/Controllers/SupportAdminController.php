<?php
declare(strict_types=1);

/**
 * Administration → Support: the bug tracker, the feedback inbox and the
 * questionnaire builder with its results (see BugReports, Feedback and
 * Surveys).
 */

final class SupportAdminController
{
    /** GET /admin/bugs — the tracker: reports by status, priority and impact. */
    public static function bugs(): void
    {
        Auth::requireAdmin();

        $show     = (string) ($_GET['show'] ?? 'open');
        $priority = (string) ($_GET['priority'] ?? '');
        $impact   = (string) ($_GET['impact'] ?? '');
        $query    = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);

        View::render('admin/bugs', [
            'bugs'     => BugReports::adminList($show, $priority, $impact, $query),
            'counts'   => BugReports::counts(),
            'show'     => $show,
            'priority' => $priority,
            'impact'   => $impact,
            'query'    => $query,
        ], __('ui.nav.bug_tracker'));
    }

    /** GET /admin/bugs/view?id= — one report, with what can be done on it. */
    public static function bug(): void
    {
        Auth::requireAdmin();
        $id  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $bug = BugReports::find($id, (int) Auth::id(), true);

        if ($bug === null) {
            flash('error', __('ui.message.bug_not_found'));
            redirect('/admin/bugs');
        }

        BugReports::markSeen($id, true);

        $stmt = Database::connection()->prepare('SELECT count(*) FROM bug_reports WHERE user_id = ?');
        $stmt->execute([$bug['user_id']]);

        View::render('admin/bug', [
            'bug'       => $bug,
            'timeline'  => BugReports::timeline($id, true),
            'images'    => SupportImages::forBug($id),
            'admin'     => true,
            'reports'   => (int) $stmt->fetchColumn(),
        ], '#' . $id . ' · ' . $bug['title']);
    }

    /** POST /admin/bugs/update — reply, note, status, priority. */
    public static function bugUpdate(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        $pins = [];
        foreach ((array) ($_POST['image_pins'] ?? []) as $imageId => $json) {
            $pins[(int) $imageId] = SupportImages::cleanPins((string) $json);
        }

        try {
            BugReports::adminUpdate((int) Auth::id(), $id, [
                'body'         => (string) ($_POST['body'] ?? ''),
                'internal'     => !empty($_POST['internal']),
                'status'       => (string) ($_POST['status'] ?? ''),
                'priority'     => (string) ($_POST['priority'] ?? ''),
                'duplicate_of' => $_POST['duplicate_of'] ?? null,
                'images'       => (array) ($_POST['images'] ?? []),
                'pins'         => $pins,
            ]);
            flash('success', __('ui.support.admin_updated'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/bugs/view?id=' . $id);
    }

    /** GET /admin/feedback — the inbox, with the open thread beside it. */
    public static function feedback(): void
    {
        Auth::requireAdmin();

        $box      = in_array($_GET['box'] ?? '', ['inbox', 'unread', 'starred', 'archived'], true) ? (string) $_GET['box'] : 'inbox';
        $category = (string) ($_GET['category'] ?? '');
        $query    = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $openId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $thread   = $openId !== null ? Feedback::thread($openId, (int) Auth::id(), true) : null;

        if ($thread !== null && $thread['admin_unread']) {
            Feedback::markRead($openId, true);
            $thread['admin_unread'] = false;
        }

        View::render('admin/feedback', [
            'threads'  => Feedback::inbox($box, $category, $query),
            'counts'   => Feedback::boxCounts(),
            'thread'   => $thread,
            'box'      => $box,
            'category' => $category,
            'query'    => $query,
        ], __('ui.nav.feedback_inbox'));
    }

    /** POST /admin/feedback/reply */
    public static function feedbackReply(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        try {
            Feedback::reply((int) Auth::id(), $id, (string) ($_POST['body'] ?? ''), true);
            flash('success', __('ui.support.reply_sent'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect(self::inboxBack($id));
    }

    /** POST /admin/feedback/flag — star, archive, mark unread (or undo). */
    public static function feedbackFlag(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $id   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $flag = (string) ($_POST['flag'] ?? '');

        Feedback::flag($id, $flag, ($_POST['on'] ?? '') === '1');

        redirect(self::inboxBack(in_array($flag, ['archive', 'unread'], true) && ($_POST['on'] ?? '') === '1' ? null : $id));
    }

    /** GET /admin/surveys — every questionnaire, with its answers so far. */
    public static function surveys(): void
    {
        Auth::requireAdmin();

        View::render('admin/surveys', ['surveys' => Surveys::adminList()], __('ui.nav.surveys'));
    }

    /** GET /admin/surveys/edit?id= — the builder (a new questionnaire without id). */
    public static function surveyEdit(): void
    {
        Auth::requireAdmin();
        $id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $survey = $id !== null ? Surveys::full($id) : null;

        if ($id !== null && $survey === null) {
            flash('error', __('ui.message.survey_not_found'));
            redirect('/admin/surveys');
        }

        $responses = 0;

        if ($survey !== null) {
            $stmt = Database::connection()->prepare('SELECT count(*) FROM survey_responses WHERE survey_id = ?');
            $stmt->execute([$id]);
            $responses = (int) $stmt->fetchColumn();
        }

        View::render('admin/survey_edit', [
            'survey'    => $survey ?? ['id' => null, 'title' => [], 'description' => [], 'audience' => 'everyone', 'status' => 'draft',
                                       'closes_at' => null, 'users' => [], 'groups' => [['id' => null, 'title' => [], 'description' => [], 'questions' => []]]],
            'responses' => $responses,
        ], $survey !== null ? Surveys::text($survey['title']) : __('ui.support.survey_new'));
    }

    /** POST /admin/surveys/save — the whole questionnaire from the builder (JSON). */
    public static function surveySave(): void
    {
        Auth::requireAdmin();
        Csrf::verify(json: true);

        $data = json_decode((string) ($_POST['survey'] ?? ''), true);

        if (!is_array($data)) {
            json_response(['ok' => false, 'error' => __('ui.message.invalid_input')], 400);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        try {
            $id = Surveys::save((int) Auth::id(), $id, $data);

            if (in_array($_POST['status'] ?? '', ['open', 'closed'], true)) {
                Surveys::setStatus($id, (string) $_POST['status']);
            }
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        flash('success', __('ui.message.saved'));
        json_response(['ok' => true, 'id' => $id, 'redirect' => url('/admin/surveys/edit?id=' . $id)]);
    }

    /** POST /admin/surveys/status — open, close or delete. */
    public static function surveyStatus(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $status = (string) ($_POST['status'] ?? '');

        try {
            if ($status === 'delete') {
                Surveys::delete($id);
                flash('success', __('ui.support.survey_deleted'));
            } elseif (in_array($status, Surveys::STATUSES, true)) {
                Surveys::setStatus($id, $status);
                flash('success', __('ui.support.survey_status_' . $status));
            }
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect('/admin/surveys');
    }

    /** GET /admin/surveys/results?id= */
    public static function surveyResults(): void
    {
        Auth::requireAdmin();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

        if (Surveys::find($id) === null) {
            flash('error', __('ui.message.survey_not_found'));
            redirect('/admin/surveys');
        }

        $results = Surveys::results($id);

        View::render('admin/survey_results', $results, $results['survey']['title']);
    }

    /** GET /admin/surveys/export?id= — every response as a spreadsheet (CSV). */
    public static function surveyExport(): void
    {
        Auth::requireAdmin();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

        if (Surveys::find($id) === null) {
            http_response_code(404);
            exit;
        }

        $results = Surveys::results($id);
        $slug    = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', (string) $results['survey']['title']))), '-');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($slug ?: 'questionnaire') . '-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('ui.support.r_person'), __('ui.support.r_when'), ...array_column($results['questions'], 'label')]);

        foreach ($results['responses'] as $response) {
            $row = [(string) $response['name'], (string) $response['updated_at']];

            foreach ($results['questions'] as $q) {
                $row[] = Surveys::answerText($q, $response['answers'][$q['id']] ?? '');
            }

            fputcsv($out, array_map(static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row));
        }

        fclose($out);
        exit;
    }

    private static function inboxBack(?int $id): string
    {
        $query = array_filter([
            'box'      => in_array($_POST['box'] ?? '', ['inbox', 'unread', 'starred', 'archived'], true) ? $_POST['box'] : null,
            'category' => in_array($_POST['category'] ?? '', Feedback::CATEGORIES, true) ? $_POST['category'] : null,
            'q'        => trim((string) ($_POST['q'] ?? '')) ?: null,
            'id'       => $id,
        ]);

        return '/admin/feedback' . ($query ? '?' . http_build_query($query) : '');
    }
}
