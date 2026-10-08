<?php
declare(strict_types=1);

/** Support: bug reports and their life, feedback threads, and questionnaires from building to results. */
final class SupportTest extends DatabaseTestCase
{
    private int $user;
    private int $admin;

    /** @var list<string> */
    private array $filesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        Lang::setLocale('en');
        $this->pdo->exec("UPDATE users SET role = 'user' WHERE role = 'admin'");
        $this->user  = $this->createUser('phpunit_reporter');
        $this->admin = $this->createUser('phpunit_admin');
        $this->pdo->exec("UPDATE users SET role = 'admin' WHERE id = {$this->admin}");
        $this->filesBefore = self::supportFiles();
    }

    protected function tearDown(): void
    {
        foreach (array_diff(self::supportFiles(), $this->filesBefore) as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private static function supportFiles(): array
    {
        return glob(dirname(__DIR__, 2) . '/tmp/support/*/*.webp') ?: [];
    }

    private function notices(int $userId): array
    {
        return $this->pdo->query(
            "SELECT t.title FROM notification_recipients r JOIN notification_texts t ON t.notification_id = r.notification_id AND t.locale = 'en'
              WHERE r.user_id = {$userId} ORDER BY r.notification_id"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function image(int $userId): int
    {
        $png = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();

        return SupportImages::store($userId, $bytes)['id'];
    }

    public function testAReportNeedsATitleAndWhatHappened(): void
    {
        try {
            BugReports::fromInput(['title' => 'x', 'description' => 'it broke']);
            self::fail('A one-letter title went through.');
        } catch (UserError) {
        }

        $report = BugReports::fromInput(['title' => '  Save   button  ', 'description' => 'It broke', 'steps' => ['Open the page', '', ' Click save '], 'impact' => 'weird']);

        self::assertSame('Save button', $report['title']);
        self::assertSame("Open the page\nClick save", $report['steps']);
        self::assertSame('annoying', $report['impact']);
    }

    public function testAReportWithScreenshotsAndPinsReachesTheAdmins(): void
    {
        $image = $this->image($this->user);
        $id = BugReports::create($this->user, BugReports::fromInput(['title' => 'Calendar crash', 'description' => 'Blank page', 'impact' => 'blocking']),
            ['browser' => 'Test'], [$image], [$image => [['x' => 1.7, 'y' => 0.25, 'note' => 'here'], ['x' => 'no']]]);

        $images = SupportImages::forBug($id);
        self::assertEquals([['x' => 1, 'y' => 0.25, 'note' => 'here']], $images[0][0]['pins'], 'Pins are kept inside the image.');
        self::assertNotNull(SupportImages::fileFor($image, $this->user, false));
        self::assertNull(SupportImages::fileFor($image, $this->createUser('phpunit_stranger'), false), 'Others cannot open the screenshot.');
        self::assertNotNull(SupportImages::fileFor($image, $this->admin, true));
        self::assertSame(['New bug report #' . $id . ': Calendar crash'], $this->notices($this->admin));
    }

    public function testTheReporterFollowsTheFixAndCanReopenIt(): void
    {
        $id = BugReports::create($this->user, BugReports::fromInput(['title' => 'Wrong date', 'description' => 'Off by one day']));

        BugReports::adminUpdate($this->admin, $id, ['body' => 'Checking internally', 'internal' => '1']);
        self::assertSame([], $this->notices($this->user), 'Internal notes are not told to the reporter.');
        self::assertCount(0, BugReports::timeline($id, false));

        BugReports::adminUpdate($this->admin, $id, ['status' => 'fixed', 'body' => 'Fixed in today\'s update.']);
        $bug = BugReports::find($id, $this->user, false);
        self::assertSame('fixed', $bug['status']);
        self::assertNotNull($bug['fixed_at']);
        self::assertSame(['Bug #' . $id . ' was fixed'], $this->notices($this->user));

        BugReports::answerFix($this->user, $id, false, 'Still off by one.');
        self::assertSame('confirmed', BugReports::find($id, $this->user, false)['status']);

        BugReports::adminUpdate($this->admin, $id, ['status' => 'fixed']);
        BugReports::answerFix($this->user, $id, true);
        self::assertSame('closed', BugReports::find($id, $this->user, false)['status']);

        $this->expectException(UserError::class);
        BugReports::answerFix($this->user, $id, true);
    }

    public function testAnswerToAQuestionSendsTheReportBack(): void
    {
        $id = BugReports::create($this->user, BugReports::fromInput(['title' => 'Login loop', 'description' => 'Keeps asking']));
        BugReports::adminUpdate($this->admin, $id, ['status' => 'need_info', 'body' => 'Which browser?']);
        BugReports::userComment($this->user, $id, 'Firefox 150');

        self::assertSame('confirmed', BugReports::find($id, $this->admin, true)['status']);
        self::assertNull(BugReports::find($id, $this->createUser('phpunit_other'), false), 'Only the reporter sees it.');
    }

    public function testFeedbackThreadGoesBothWays(): void
    {
        $id = Feedback::create($this->user, 'Dark mode', 'idea', 'Darker calendar please');
        self::assertSame(1, Feedback::boxCounts()['unread']);

        Feedback::reply($this->admin, $id, 'Noted!', true);
        self::assertTrue((bool) Feedback::forUser($this->user)[0]['unread']);
        self::assertSame(['Reply to your message: Dark mode'], $this->notices($this->user));

        Feedback::flag($id, 'archive', true);
        Feedback::reply($this->user, $id, 'Thanks', false);
        self::assertFalse((bool) Feedback::thread($id, $this->admin, true)['is_archived'], 'A reply brings it back to the inbox.');
        self::assertCount(3, Feedback::thread($id, $this->user, false)['messages']);
        self::assertNull(Feedback::thread($id, $this->createUser('phpunit_nosy'), false));
    }

    public function testQuestionnaireFromBuilderToResults(): void
    {
        $id = Surveys::save($this->admin, null, [
            'title' => ['en' => 'How is StreamOrg?', 'pt-BR' => 'Como está o StreamOrg?', 'xx' => 'ignored'],
            'audience' => 'users',
            'users' => [$this->user],
            'groups' => [
                ['title' => ['en' => 'You'], 'questions' => [
                    ['type' => 'radio', 'label' => ['en' => 'Main platform', 'pt-BR' => 'Plataforma principal'], 'required' => true,
                     'options' => ['choices' => [['text' => ['en' => 'Twitch']], ['text' => ['en' => 'Other', 'pt-BR' => 'Outra']], ['text' => []]]]],
                    ['type' => 'scale', 'label' => ['en' => 'How happy'], 'options' => ['min' => 1, 'max' => 5, 'max_label' => ['en' => 'Very', 'pt-BR' => 'Muito']]],
                ]],
                ['title' => ['en' => 'More'], 'questions' => [
                    ['type' => 'checkbox', 'label' => ['en' => 'Features used'], 'options' => ['choices' => [['text' => ['en' => 'Calendar']], ['text' => ['en' => 'Keys']], ['text' => ['en' => 'Bot']]]]],
                    ['type' => 'long_text', 'label' => ['en' => 'Anything else?']],
                ]],
            ],
        ]);

        $survey = Surveys::full($id);
        self::assertSame(['en' => 'How is StreamOrg?', 'pt-BR' => 'Como está o StreamOrg?'], $survey['title'], 'Only the app\'s languages are kept.');
        self::assertSame(['c1', 'c2'], array_column($survey['groups'][0]['questions'][0]['options']['choices'], 'key'), 'Choices get keys; empty ones are dropped.');

        $pt = Surveys::localized($survey, 'pt-BR');
        self::assertSame('Plataforma principal', $pt['groups'][0]['questions'][0]['label']);
        self::assertSame(['Twitch', 'Outra'], array_column($pt['groups'][0]['questions'][0]['options']['choices'], 'label'), 'A missing translation falls back to English.');
        self::assertSame('Muito', $pt['groups'][0]['questions'][1]['options']['max_label']);
        self::assertSame(['en' => 0, 'pt-BR' => 9], Surveys::missing($id));
        self::assertSame([], Surveys::forUser($this->createUser('phpunit_not_asked')), 'A draft, and not for them.');

        Surveys::setStatus($id, 'open');
        self::assertSame(['New questionnaire: How is StreamOrg?'], $this->notices($this->user));
        self::assertSame('Novo questionário: Como está o StreamOrg?', (string) $this->pdo->query(
            "SELECT t.title FROM notification_recipients r JOIN notification_texts t ON t.notification_id = r.notification_id AND t.locale = 'pt-BR' WHERE r.user_id = {$this->user}"
        )->fetchColumn(), 'Each language gets the title in its own language.');
        self::assertSame(1, Surveys::pendingFor($this->user));

        [$q1, $q2] = array_column($survey['groups'][0]['questions'], 'id');
        [$q3, $q4] = array_column($survey['groups'][1]['questions'], 'id');

        $errors = Surveys::submit($this->user, $id, [$q1 => 'Twitch', $q2 => '9']);
        self::assertArrayHasKey($q1, $errors, 'Choices are answered by key, not by text.');
        self::assertArrayHasKey($q2, $errors, '9 is off the scale.');

        self::assertSame([], Surveys::submit($this->user, $id, [$q1 => 'c2', $q2 => '4', $q3 => ['c2', 'c9', 'c3'], $q4 => 'Great']));
        self::assertSame(0, Surveys::pendingFor($this->user));
        self::assertSame(['c2', 'c3'], Surveys::answersOf($id, $this->user)[$q3]);

        $other = $this->createUser('phpunit_other_lang');
        Surveys::save($this->admin, $id, array_merge($survey, ['users' => [$this->user, $other]]));
        Surveys::submit($other, $id, [$q1 => 'c2']);

        $results = Surveys::results($id, 'pt-BR');
        self::assertSame(['c1' => 0, 'c2' => 2], $results['questions'][0]['summary']['counts'], 'Answers in any language add up.');
        self::assertSame('Outra', $results['questions'][0]['summary']['labels']['c2']);
        self::assertEquals(4, $results['questions'][1]['summary']['average']);
        self::assertSame('Keys; Bot', Surveys::answerText(Surveys::results($id, 'en')['questions'][2], ['c2', 'c3']));

        $survey['groups'][0]['questions'][1]['label'] = ['en' => 'How happy are you'];
        array_pop($survey['groups'][1]['questions']);
        Surveys::save($this->admin, $id, $survey + ['users' => [$this->user]]);
        self::assertSame(['c2', 4, ['c2', 'c3']], array_values(Surveys::answersOf($id, $this->user)), 'Answers to kept questions stay.');

        Surveys::setStatus($id, 'closed');
        $this->expectException(UserError::class);
        Surveys::submit($this->user, $id, [$q1 => 'c1']);
    }
}
