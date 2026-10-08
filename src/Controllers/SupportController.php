<?php
declare(strict_types=1);

/**
 * Support, for every user: report a bug, answer questionnaires, write to
 * the administrators, and follow all of it (see BugReports, Surveys and
 * Feedback). Screenshots are uploaded on their own first (images()), then
 * attached when the report or comment is sent.
 */
final class SupportController
{
    /** GET /support — the three ways in, and everything already sent. */
    public static function index(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();

        View::render('support/index', [
            'bugs'      => BugReports::forUser($userId),
            'threads'   => Feedback::forUser($userId),
            'surveys'   => Surveys::forUser($userId),
            'attention' => SupportCenter::attentionFor($userId),
        ], __('ui.nav.support'));
    }

    /** GET /support/bug — the bug report form, on the page the user came from. */
    public static function bugForm(): void
    {
        Auth::requireLogin();

        $code = filter_input(INPUT_GET, 'code', FILTER_VALIDATE_INT, ['options' => ['min_range' => 400, 'max_range' => 599]]) ?: null;
        $ref  = filter_input(INPUT_GET, 'ref', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        View::render('support/bug_form', [
            'page'  => self::pageFrom((string) ($_GET['page'] ?? ''), (string) ($_SERVER['HTTP_REFERER'] ?? '')),
            'pages' => self::pages(),
            'error' => $code !== null ? ['code' => $code, 'ref' => $ref] : null,
        ], __('ui.support.bug_title'));
    }

    /** POST /support/bug */
    public static function bugCreate(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            $id = BugReports::create(
                (int) Auth::id(),
                BugReports::fromInput($_POST),
                BugReports::environment((array) ($_POST['env'] ?? []), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                array_map('intval', (array) ($_POST['images'] ?? [])),
                self::pins()
            );
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            $_SESSION['old_bug'] = array_intersect_key($_POST, array_flip(['title', 'page', 'description', 'steps', 'expected', 'impact']));
            redirect('/support/bug');
        }

        flash('success', __('ui.support.bug_sent'));
        redirect(BugReports::LINK . $id);
    }

    /** GET /support/bug/view?id= — a report as its reporter follows it. */
    public static function bugShow(): void
    {
        Auth::requireLogin();
        $id  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $bug = BugReports::find($id, (int) Auth::id(), false);

        if ($bug === null) {
            flash('error', __('ui.message.bug_not_found'));
            redirect('/support');
        }

        BugReports::markSeen($id, false);

        View::render('support/bug_show', [
            'bug'      => $bug,
            'timeline' => BugReports::timeline($id, false),
            'images'   => SupportImages::forBug($id),
            'admin'    => false,
        ], '#' . $id . ' · ' . $bug['title']);
    }

    /** POST /support/bug/comment */
    public static function bugComment(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        try {
            BugReports::userComment((int) Auth::id(), $id, (string) ($_POST['body'] ?? ''), array_map('intval', (array) ($_POST['images'] ?? [])), self::pins());
            flash('success', __('ui.support.comment_sent'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect(BugReports::LINK . $id);
    }

    /** POST /support/bug/fix — "it works now" or "it still happens". */
    public static function bugAnswerFix(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $works = ($_POST['works'] ?? '') === '1';

        try {
            BugReports::answerFix((int) Auth::id(), $id, $works, (string) ($_POST['body'] ?? ''));
            flash('success', __($works ? 'ui.support.fix_confirmed' : 'ui.support.fix_reopened'));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect(BugReports::LINK . $id);
    }

    /** POST /support/images — one screenshot, kept as a draft until sent (JSON). */
    public static function imageUpload(): void
    {
        Auth::requireLogin();
        Csrf::verify(json: true);

        $file = $_FILES['image'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            json_response(['ok' => false, 'error' => sprintf(__('ui.message.support_image_too_big'), intdiv(SupportImages::MAX_BYTES, 1024 * 1024))], 400);
        }

        try {
            $image = SupportImages::store((int) Auth::id(), (string) file_get_contents($file['tmp_name']));
        } catch (UserError $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }

        json_response(['ok' => true, 'id' => $image['id'], 'url' => url('/support/image?id=' . $image['id']), 'width' => $image['width'], 'height' => $image['height']]);
    }

    /** GET /support/image?id= — a screenshot, for its reporter or an administrator. */
    public static function image(): void
    {
        Auth::requireLogin();
        $file = SupportImages::fileFor(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0, (int) Auth::id(), Auth::isAdmin());

        if ($file === null) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    /** GET /support/feedback — write to the administrators. */
    public static function feedbackForm(): void
    {
        Auth::requireLogin();

        View::render('support/feedback_form', [
            'category' => in_array($_GET['category'] ?? '', Feedback::CATEGORIES, true) ? (string) $_GET['category'] : 'idea',
        ], __('ui.support.feedback_title'));
    }

    /** POST /support/feedback */
    public static function feedbackCreate(): void
    {
        Auth::requireLogin();
        Csrf::verify();

        try {
            $id = Feedback::create((int) Auth::id(), (string) ($_POST['subject'] ?? ''), (string) ($_POST['category'] ?? ''), (string) ($_POST['body'] ?? ''));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            $_SESSION['old_feedback'] = array_intersect_key($_POST, array_flip(['subject', 'category', 'body']));
            redirect('/support/feedback');
        }

        flash('success', __('ui.support.feedback_sent'));
        redirect(Feedback::LINK . $id);
    }

    /** GET /support/message?id= — a conversation with the administrators. */
    public static function thread(): void
    {
        Auth::requireLogin();
        $id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $thread = Feedback::thread($id, (int) Auth::id(), false);

        if ($thread === null) {
            flash('error', __('ui.message.feedback_not_found'));
            redirect('/support');
        }

        Feedback::markRead($id, false);

        View::render('support/thread', ['thread' => $thread], $thread['subject']);
    }

    /** POST /support/message/reply */
    public static function threadReply(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        try {
            Feedback::reply((int) Auth::id(), $id, (string) ($_POST['body'] ?? ''), false);
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        }

        redirect(Feedback::LINK . $id);
    }

    /** GET /support/survey?id= — a questionnaire to answer (or the answers given). */
    public static function survey(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();
        $survey = Surveys::full(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);

        if ($survey === null || !Surveys::visibleTo($survey, $userId)) {
            flash('error', __('ui.message.survey_not_found'));
            redirect('/support');
        }

        $errors = $_SESSION['survey_errors'] ?? [];
        $given  = $_SESSION['survey_input'] ?? null;
        unset($_SESSION['survey_errors'], $_SESSION['survey_input']);

        View::render('support/survey', [
            'survey'     => Surveys::localized($survey),
            'answers'    => $given ?? Surveys::answersOf((int) $survey['id'], $userId),
            'errors'     => $errors,
            'answerable' => Surveys::answerable($survey),
            'answered'   => Surveys::answersOf((int) $survey['id'], $userId) !== [],
        ], Surveys::text($survey['title']));
    }

    /** POST /support/survey */
    public static function surveySubmit(): void
    {
        Auth::requireLogin();
        Csrf::verify();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;

        try {
            $errors = Surveys::submit((int) Auth::id(), $id, (array) ($_POST['q'] ?? []));
        } catch (UserError $e) {
            flash('error', $e->getMessage());
            redirect('/support');
        }

        if ($errors !== []) {
            $_SESSION['survey_errors'] = $errors;
            $_SESSION['survey_input']  = (array) ($_POST['q'] ?? []);
            flash('error', __('ui.support.survey_fix_answers'));
            redirect(Surveys::LINK . $id);
        }

        flash('success', __('ui.support.survey_thanks'));
        redirect('/support');
    }

    /**
     * The pins sent with the form: images_pins[<image id>] = JSON list.
     *
     * @return array<int, list<array>>
     */
    private static function pins(): array
    {
        $out = [];

        foreach ((array) ($_POST['image_pins'] ?? []) as $id => $json) {
            $out[(int) $id] = SupportImages::cleanPins((string) $json);
        }

        return $out;
    }

    /**
     * The page a report is about: the one asked for, or the page the user
     * was on, as a path of this site.
     */
    private static function pageFrom(string $asked, string $referer): string
    {
        foreach ([$asked, $referer] as $candidate) {
            $path = (string) parse_url($candidate, PHP_URL_PATH);
            $host = (string) parse_url($candidate, PHP_URL_HOST);

            if ($path !== '' && ($host === '' || in_array(strtolower($host), app_hosts(), true) || $host === ($_SERVER['HTTP_HOST'] ?? ''))
                && !str_starts_with($path, '/support')) {
                return $path;
            }
        }

        return '';
    }

    /**
     * The app's pages, to choose from when the report is about one of them.
     *
     * @return array<string, string> path => name
     */
    private static function pages(): array
    {
        return [
            '/dashboard'          => __('ui.nav.dashboard'),
            '/content'            => __('ui.nav.content'),
            '/keys'               => __('ui.nav.vault'),
            '/giveaways'          => __('ui.nav.giveaways'),
            '/collabs'            => __('ui.nav.collabs'),
            '/streamers'          => __('ui.nav.streamers'),
            '/embargoes'          => __('ui.nav.embargoes'),
            '/catalog'            => __('ui.nav.catalog'),
            '/notifications'      => __('ui.nav.notifications'),
            '/profile'            => __('ui.nav.profile'),
            '/profile/defaults'   => __('ui.label.profile_defaults'),
            '/profile/bot'        => __('ui.nav.chat_bot'),
        ];
    }
}
