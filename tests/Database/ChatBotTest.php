<?php
declare(strict_types=1);

/** Chat bot commands: defaults, a streamer's own versions, and channels. */
final class ChatBotTest extends DatabaseTestCase
{
    private const INPUT = ['trigger' => '!Ping', 'response' => '  pong   {user} ', 'permission' => 'moderator', 'cooldown_seconds' => '5', 'is_enabled' => '1'];

    public function testHeartbeatIsTheOnlyDefault(): void
    {
        $defaults = ChatBot::defaults();

        self::assertSame(['heartbeat'], array_keys($defaults));
        self::assertSame('heartbeat', $defaults['heartbeat']['trigger']);
    }

    public function testCommandInputIsCleanedUp(): void
    {
        self::assertSame(
            ['trigger' => 'ping', 'response' => 'pong {user}', 'is_enabled' => true, 'permission' => 'moderator', 'cooldown_seconds' => 5],
            ChatBot::commandFromInput('heartbeat', self::INPUT)
        );
    }

    public function testBadCommandInputIsRefused(): void
    {
        foreach ([['trigger' => 'two words'], ['response' => ''], ['permission' => 'admin'], ['cooldown_seconds' => '-1']] as $bad) {
            try {
                ChatBot::commandFromInput('heartbeat', $bad + self::INPUT);
                self::fail('Accepted ' . json_encode($bad));
            } catch (UserError) {
                self::assertTrue(true);
            }
        }

        $this->expectException(UserError::class);
        ChatBot::commandFromInput('no_such_command', self::INPUT);
    }

    public function testAStreamersVersionReplacesTheDefaultForThemOnly(): void
    {
        $mine  = $this->createUser('phpunit_bot_mine');
        $other = $this->createUser('phpunit_bot_other');

        ChatBot::saveCommand($mine, 'heartbeat', ChatBot::commandFromInput('heartbeat', self::INPUT));

        self::assertSame('ping', ChatBot::commandsFor($mine)['heartbeat']['trigger']);
        self::assertTrue(ChatBot::commandsFor($mine)['heartbeat']['personal']);
        self::assertSame('heartbeat', ChatBot::commandsFor($other)['heartbeat']['trigger']);

        ChatBot::resetCommand($mine, 'heartbeat');

        self::assertSame('heartbeat', ChatBot::commandsFor($mine)['heartbeat']['trigger']);
        self::assertFalse(ChatBot::commandsFor($mine)['heartbeat']['personal']);
    }

    public function testChannelsAreAddedByTheStreamerAndBlockedByAnAdmin(): void
    {
        $user = $this->createUser('phpunit_bot_channel');

        self::assertNull(ChatBot::channel($user));

        ChatBot::setChannel($user, true);
        ChatBot::setBlocked($user, true);

        $channel = ChatBot::channel($user);
        self::assertTrue((bool) $channel['is_enabled']);
        self::assertTrue((bool) $channel['is_blocked']);

        ChatBot::setChannel($user, false);
        self::assertFalse((bool) ChatBot::channel($user)['is_enabled']);
        self::assertNotEmpty(ChatBot::recentLog($user));
    }

    public function testAdminsSeeEveryTwitchChannelAndCanAddTheBot(): void
    {
        $user = $this->createUser('phpunit_bot_admin_add');
        $this->pdo->prepare(
            "INSERT INTO twitch_connections (user_id, twitch_user_id, twitch_login, access_token, refresh_token, expires_at, scopes)
             VALUES (?, 'tw-phpunit', 'phpunit_channel', 'x', 'x', now(), '')"
        )->execute([$user]);

        $row = array_values(array_filter(ChatBot::channels(), static fn (array $c): bool => (int) $c['user_id'] === $user))[0] ?? null;
        self::assertNotNull($row);
        self::assertFalse((bool) $row['added']);

        ChatBot::setChannel($user, true, true);

        self::assertTrue((bool) ChatBot::channel($user)['is_enabled']);
        self::assertSame('Added to the channel by an admin', ChatBot::recentLog($user)[0]['message']);
    }

    public function testStatisticsSummaryCountsOneChannelOrAll(): void
    {
        $user = $this->createUser('phpunit_bot_stats');
        ChatBot::setChannel($user, true);

        $broadcast = (int) $this->pdo->query(
            "INSERT INTO twitch_broadcasts (user_id, twitch_stream_id, started_at) VALUES ({$user}, 'phpunit-s', now()) RETURNING id"
        )->fetchColumn();
        $this->pdo->exec("INSERT INTO chat_viewers (twitch_user_id, login) VALUES ('phpunit-v1', 'ana'), ('phpunit-v2', 'bia')");
        $this->pdo->exec(
            "INSERT INTO chat_viewer_stats (broadcast_id, user_id, viewer_id, category_id, watch_seconds, messages)
             VALUES ({$broadcast}, {$user}, 'phpunit-v1', '1', 120, 2), ({$broadcast}, {$user}, 'phpunit-v2', '1', 60, 0)"
        );

        $mine = ChatBot::statsSummary($user);
        self::assertSame(1, $mine['broadcasts']);
        self::assertSame(2, $mine['viewers']);
        self::assertTrue($mine['live']);
        self::assertNull($mine['bytes']);
        self::assertGreaterThan(0, ChatBot::statsSummary()['bytes']);
        self::assertSame('unknown', ChatBot::channel($user)['access']);
    }

    public function testRecheckForgetsWhatTheBotFoundOnlyWhereItIsAdded(): void
    {
        $user  = $this->createUser('phpunit_bot_recheck');
        $other = $this->createUser('phpunit_bot_recheck_off');

        ChatBot::setChannel($user, true);
        $this->pdo->prepare("UPDATE bot_channels SET access = 'none', access_checked_at = now() WHERE user_id = ?")->execute([$user]);

        ChatBot::recheck($user);
        ChatBot::recheck($other);

        self::assertSame('unknown', ChatBot::channel($user)['access']);
        self::assertNull(ChatBot::channel($other));
    }

    public function testCustomCommandsAreTheStreamersAndNeverShareATrigger(): void
    {
        $user  = $this->createUser('phpunit_bot_custom');
        $other = $this->createUser('phpunit_bot_custom_other');
        $input = ['trigger' => 'discord', 'response' => 'Join us', 'permission' => 'everyone', 'cooldown_seconds' => '30', 'is_enabled' => '1'];

        ChatBot::saveCustom($user, null, ChatBot::customFromInput($input));
        $mine = ChatBot::customCommands($user);
        self::assertSame('discord', $mine[0]['trigger']);

        foreach ([['trigger' => 'heartbeat'], ['trigger' => 'discord']] as $clash) {
            try {
                ChatBot::saveCustom($user, null, ChatBot::customFromInput($clash + $input));
                self::fail('Accepted a taken trigger: ' . $clash['trigger']);
            } catch (UserError) {
                self::assertTrue(true);
            }
        }

        ChatBot::saveCustom($user, (int) $mine[0]['id'], ChatBot::customFromInput(['response' => 'Join the server'] + $input));
        self::assertSame('Join the server', ChatBot::customCommands($user)[0]['response']);

        try {
            ChatBot::saveCustom($other, (int) $mine[0]['id'], ChatBot::customFromInput(['trigger' => 'mine'] + $input));
            self::fail('Changed someone else\'s command');
        } catch (UserError) {
            self::assertSame('discord', ChatBot::customCommands($user)[0]['trigger']);
        }

        ChatBot::deleteCustom($other, (int) $mine[0]['id']);
        self::assertCount(1, ChatBot::customCommands($user));
        ChatBot::deleteCustom($user, (int) $mine[0]['id']);
        self::assertSame([], ChatBot::customCommands($user));
    }

    public function testRenamingABuiltInCommandCannotTakeACustomTrigger(): void
    {
        $user = $this->createUser('phpunit_bot_clash');
        ChatBot::saveCustom($user, null, ChatBot::customFromInput(self::INPUT));

        $this->expectException(UserError::class);
        ChatBot::saveCommand($user, 'heartbeat', ChatBot::commandFromInput('heartbeat', self::INPUT));
    }

    public function testTimedMessagesAreValidatedAndOwned(): void
    {
        $user  = $this->createUser('phpunit_bot_timer');
        $timer = ChatBot::timerFromInput(['message' => ' Follow   me! ', 'interval_minutes' => '15', 'min_messages' => '5', 'is_enabled' => '1']);

        self::assertSame(['message' => 'Follow me!', 'interval_minutes' => 15, 'min_messages' => 5, 'is_enabled' => true], $timer);

        foreach ([['interval_minutes' => '4'], ['interval_minutes' => '1441'], ['min_messages' => '-1'], ['message' => '']] as $bad) {
            try {
                ChatBot::timerFromInput($bad + ['message' => 'x', 'interval_minutes' => '15', 'min_messages' => '5']);
                self::fail('Accepted ' . json_encode($bad));
            } catch (UserError) {
                self::assertTrue(true);
            }
        }

        ChatBot::saveTimer($user, null, $timer);
        $saved = ChatBot::timers($user)[0];
        ChatBot::saveTimer($user, (int) $saved['id'], ['interval_minutes' => 30] + $timer);
        self::assertSame(30, (int) ChatBot::timers($user)[0]['interval_minutes']);

        ChatBot::deleteTimer($user, (int) $saved['id']);
        self::assertSame([], ChatBot::timers($user));
    }

    public function testPrefixMustBeASymbol(): void
    {
        $this->expectException(UserError::class);
        ChatBot::saveSettings(true, 'go');
    }
}
