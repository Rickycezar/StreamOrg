<?php
declare(strict_types=1);

/** Uploaded pictures are re-encoded as a 256×256 WebP; anything else is refused. */
final class AvatarsTest extends DatabaseTestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            @unlink(dirname(__DIR__, 2) . '/public/' . $this->path);
        }

        parent::tearDown();
    }

    public function testImageBecomesASquareWebp(): void
    {
        $user = $this->createUser('phpunit_avatar');

        $image = imagecreatetruecolor(640, 360);
        ob_start();
        imagejpeg($image);
        Avatars::store($user, (string) ob_get_clean());

        $this->path = (string) $this->pdo->query("SELECT avatar_path FROM users WHERE id = {$user}")->fetchColumn();
        $info = getimagesize(dirname(__DIR__, 2) . '/public/' . $this->path);

        self::assertMatchesRegularExpression('#^media/avatars/' . $user . '-[0-9a-f]{12}\.webp$#', $this->path);
        self::assertSame([256, 256, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
    }

    public function testNonImagesAreRefused(): void
    {
        $user = $this->createUser('phpunit_avatar');

        $this->expectException(UserError::class);
        Avatars::store($user, "<?php echo 'not an image';");
    }
}
