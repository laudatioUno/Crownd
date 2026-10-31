<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\Service\ImageUpload;
use PHPUnit\Framework\TestCase;

final class ImageUploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crownd-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testDefaultCropPrefersTheTopOfPortraits(): void
    {
        self::assertSame([0, 48, 600], ImageUpload::cropRect(600, 1000, null), 'portrait: 12 % of the spare height');
        self::assertSame([100, 0, 400], ImageUpload::cropRect(600, 400, null), 'landscape: centered');
        self::assertSame([0, 0, 500], ImageUpload::cropRect(500, 500, null));
    }

    public function testChosenCropIsClampedIntoTheImage(): void
    {
        self::assertSame([150, 100, 300], ImageUpload::cropRect(600, 1000, ['x' => 0.25, 'y' => 0.1, 'size' => 0.5]));
        self::assertSame([300, 700, 300], ImageUpload::cropRect(600, 1000, ['x' => 0.9, 'y' => 0.95, 'size' => 0.5]), 'moved back inside');
        self::assertSame([0, 0, 600], ImageUpload::cropRect(600, 1000, ['x' => 0, 'y' => 0, 'size' => 1]));
        self::assertSame(30, ImageUpload::cropRect(600, 1000, ['x' => 0, 'y' => 0, 'size' => 0.01])[2], 'at least 5 % of the short edge');
    }

    public function testParseCrop(): void
    {
        self::assertSame(['x' => 0.25, 'y' => 0.1, 'size' => 0.5], ImageUpload::parseCrop('0.25000', '0.10000', '0.50000'));
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'size' => 1.0], ImageUpload::parseCrop('0', '0', '1'));
        self::assertNull(ImageUpload::parseCrop('', '', ''), 'no crop tool (no JavaScript)');
        self::assertNull(ImageUpload::parseCrop('-0.1', '0', '1'));
        self::assertNull(ImageUpload::parseCrop('0.1', '0', '0'));
        self::assertNull(ImageUpload::parseCrop('0.1', '0', '1.5'));
        self::assertNull(ImageUpload::parseCrop('1e3', '0', '1'));
    }

    public function testNames(): void
    {
        self::assertTrue(ImageUpload::validName(str_repeat('a', 32) . '.jpg'));
        self::assertTrue(ImageUpload::validName(str_repeat('0', 32) . '.png'));
        self::assertFalse(ImageUpload::validName(str_repeat('a', 32) . '.php'));
        self::assertFalse(ImageUpload::validName('../' . str_repeat('a', 29) . '.jpg'));
        self::assertSame('image/jpeg', ImageUpload::contentType('x.jpg'));
        self::assertSame('image/png', ImageUpload::contentType('x.png'));
    }

    public function testStorePhotoKeepsOriginalAndCutsSquare(): void
    {
        // 800 × 1200 portrait: red square in the upper part (the "face")
        $im = imagecreatetruecolor(800, 1200);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 60, 160));
        imagefilledrectangle($im, 300, 100, 500, 300, imagecolorallocate($im, 220, 30, 30));
        $tmp = $this->dir . '/upload.jpg';
        imagejpeg($im, $tmp, 95);

        $images = new ImageUpload($this->dir);
        $file = ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
        // Cut-out exactly around the red square: x 300/800, y 100/1200, edge 200/800
        $stored = $images->storePhoto($file, ['x' => 0.375, 'y' => 100 / 1200, 'size' => 0.25]);

        $photo = imagecreatefromjpeg((string) $images->path($stored['photo']));
        self::assertSame([200, 200], [imagesx($photo), imagesy($photo)], 'small cut-outs are not scaled up');
        $center = imagecolorsforindex($photo, imagecolorat($photo, 100, 100));
        self::assertGreaterThan(180, $center['red']);
        self::assertLessThan(80, $center['blue']);

        $original = imagecreatefromjpeg((string) $images->path($stored['original']));
        self::assertSame([800, 1200], [imagesx($original), imagesy($original)]);

        // New cut-out from the original: the whole width, default height
        $recut = $images->recrop($stored['original'], null);
        self::assertNotNull($recut);
        $square = imagecreatefromjpeg((string) $images->path($recut));
        self::assertSame([600, 600], [imagesx($square), imagesy($square)]);
        self::assertNull($images->recrop(str_repeat('f', 32) . '.jpg', null));
    }
}
