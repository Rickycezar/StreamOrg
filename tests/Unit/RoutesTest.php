<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every route in public/index.php leads to a public static method that exists. */
final class RoutesTest extends TestCase
{
    public function testEveryRouteHasItsMethod(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        preg_match_all('/\[\s*([A-Za-z_\\\\]+)::class\s*,\s*\'([A-Za-z_]+)\'\s*\]/', $source, $routes, PREG_SET_ORDER);

        self::assertGreaterThan(50, count($routes), 'The routes were not found in public/index.php.');

        $missing = [];

        foreach ($routes as [, $class, $method]) {
            if (!method_exists($class, $method) || !(new ReflectionMethod($class, $method))->isPublic()) {
                $missing[] = "{$class}::{$method}";
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'Routes pointing at methods that do not exist.');
    }
}
