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

    public function testPrefixMustBeASymbol(): void
    {
        $this->expectException(UserError::class);
        ChatBot::saveSettings(true, 'go');
    }
}
