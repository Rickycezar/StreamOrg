<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserSessionsTest extends TestCase
{
    public static function agents(): array
    {
        return [
            'Chrome on Linux'    => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36', 'Chrome · Linux'],
            'Firefox on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:141.0) Gecko/20100101 Firefox/141.0', 'Firefox · Windows'],
            'Edge, not Chrome'   => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/140 Safari/537.36 Edg/140', 'Edge · Windows'],
            'Safari on iPhone'   => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari · iPhone'],
            'Android before Linux' => ['Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/140 Mobile Safari/537.36', 'Chrome · Android'],
            'empty'              => ['', '?'],
            'unknown tool'       => ['SomethingElse/1.0', '?'],
        ];
    }

    #[DataProvider('agents')]
    public function testDescribe(string $agent, string $expected): void
    {
        self::assertSame($expected, UserSessions::describe($agent));
    }
}
