<?php

declare(strict_types=1);

namespace Crownd\Service;

/**
 * Stores uploaded images (logos, player photos) as PNG under storage/uploads/.
 *
 * PNG, JPG and WebP are accepted (checked by content, not by name). The image is
 * re-encoded with GD, which removes metadata and anything that is not an image.
 * Phone photos are turned upright using their EXIF orientation.
 */
final class ImageUpload
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly string $uploadDir)
    {
    }

    /**
     * Scales the image down to a maximum edge length, keeping its proportions (logos).
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return string stored file name (32 hex chars + .png)
     * @throws \InvalidArgumentException with a translation key as message
     */
    public function store(array $file, int $maxEdge = 500): string
    {
        $src = $this->load($file);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxEdge / max($w, $h));

        return $this->save($src, 0, 0, $w, $h, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    }

    /**
     * Cuts a centered square (a little above the middle, where faces usually are)
     * and scales it to $size × $size (player photos).
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     */
    public function storeSquare(array $file, int $size = 480): string
    {
        $src = $this->load($file);
        $w = imagesx($src);
        $h = imagesy($src);
        $edge = min($w, $h);
        $x = intdiv($w - $edge, 2);
        $y = $h > $w ? (int) round(($h - $edge) * 0.3) : 0;
        $target = min($size, $edge);

        return $this->save($src, $x, $y, $edge, $edge, $target, $target);
    }

    public function delete(?string $name): void
    {
        if ($name !== null && self::validName($name) && is_file($this->uploadDir . '/' . $name)) {
            unlink($this->uploadDir . '/' . $name);
        }
    }

    public function path(string $name): ?string
    {
        $path = $this->uploadDir . '/' . $name;

        return self::validName($name) && is_file($path) ? $path : null;
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.png$/', $name);
    }

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
    private function load(array $file): \GdImage
    {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException('upload.too_large');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli')) {
            throw new \InvalidArgumentException('upload.failed');
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('upload.too_large');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
        if ($info === false || !in_array($info[2], $types, true)) {
            throw new \InvalidArgumentException('upload.wrong_type');
        }
        if ($info[0] > 8000 || $info[1] > 8000) {
            throw new \InvalidArgumentException('upload.too_large');
        }

        $src = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        };
        if ($src === false) {
            throw new \InvalidArgumentException('upload.wrong_type');
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $src = $this->orient($src, $file['tmp_name']);
        }

        return $src;
    }

    /** Applies the EXIF orientation of phone photos. */
    private function orient(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private function save(\GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): string
    {
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new \InvalidArgumentException('upload.failed');
        }
        $name = bin2hex(random_bytes(16)) . '.png';
        if (!imagepng($dst, $this->uploadDir . '/' . $name, 9)) {
            throw new \InvalidArgumentException('upload.failed');
        }

        return $name;
    }
}
