<?php

declare(strict_types=1);

namespace Crownd\Service;

/**
 * Stores uploaded images (logos, player photos) under storage/uploads/.
 *
 * PNG, JPG and WebP are accepted (checked by content, not by name). Every image is
 * re-encoded with GD, which removes metadata and anything that is not an image.
 * Phone photos are turned upright using their EXIF orientation.
 *
 * Logos are stored as PNG (transparency). A player photo is stored twice, as JPEG:
 * the original (max. 1600 px) and the square cut-out shown in the app (600 × 600 px).
 * The original allows changing the cut-out later without uploading the photo again.
 */
final class ImageUpload
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const PHOTO_SIZE = 600;
    public const ORIGINAL_EDGE = 1600;

    /** Without a chosen cut-out, a portrait photo is cut this far down (0 = top, 1 = bottom): faces are usually in the upper part. */
    public const DEFAULT_TOP = 0.12;

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
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, $maxEdge / max($w, $h));
        $dst = $this->resample($src, 0, 0, $w, $h, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), true);

        return $this->save($dst, 'png');
    }

    /**
     * Stores a player photo: the original and the round-ready square cut-out.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @param array{x: float, y: float, size: float}|null $crop cut-out as fractions of the image (see cropRect)
     * @return array{photo: string, original: string}
     */
    public function storePhoto(array $file, ?array $crop): array
    {
        $src = $this->load($file);
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1.0, self::ORIGINAL_EDGE / max($w, $h));
        $original = $this->resample($src, 0, 0, $w, $h, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), false);

        $originalName = $this->save($original, 'jpg');
        $photoName = $this->cut($original, $crop);

        return ['photo' => $photoName, 'original' => $originalName];
    }

    /**
     * Cuts a new square from a stored original.
     *
     * @param array{x: float, y: float, size: float}|null $crop
     * @return string|null new photo file name, null if the original is missing
     */
    public function recrop(string $originalName, ?array $crop): ?string
    {
        $path = $this->path($originalName);
        if ($path === null) {
            return null;
        }
        $src = @imagecreatefromjpeg($path);

        return $src === false ? null : $this->cut($src, $crop);
    }

    /**
     * The square to cut, in pixels. $crop holds the top-left corner and the edge length
     * as fractions: x of the width, y of the height, size of the shorter edge (1 = as large
     * as possible). Values are clamped, so the square always lies inside the image.
     * Without $crop: the largest square, centered horizontally, near the top.
     *
     * @param array{x: float, y: float, size: float}|null $crop
     * @return array{0: int, 1: int, 2: int} x, y, edge
     */
    public static function cropRect(int $w, int $h, ?array $crop): array
    {
        $short = min($w, $h);
        if ($crop === null) {
            return [intdiv($w - $short, 2), (int) round(($h - $short) * self::DEFAULT_TOP), $short];
        }
        $edge = (int) round(max(0.05, min(1.0, $crop['size'])) * $short);
        $edge = max(16, min($short, $edge));
        $x = (int) round($crop['x'] * $w);
        $y = (int) round($crop['y'] * $h);

        return [max(0, min($w - $edge, $x)), max(0, min($h - $edge, $y)), $edge];
    }

    /**
     * Reads the cut-out sent by the crop tool (hidden fields crop_x, crop_y, crop_size).
     *
     * @return array{x: float, y: float, size: float}|null null when missing or not plausible
     */
    public static function parseCrop(string $x, string $y, string $size): ?array
    {
        $values = [];
        foreach (['x' => $x, 'y' => $y, 'size' => $size] as $key => $value) {
            if (!preg_match('/^\d(\.\d{1,8})?$/', $value)) {
                return null;
            }
            $values[$key] = (float) $value;
        }
        if ($values['x'] > 1 || $values['y'] > 1 || $values['size'] <= 0 || $values['size'] > 1) {
            return null;
        }

        return $values;
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
        return (bool) preg_match('/^[a-f0-9]{32}\.(png|jpg)$/', $name);
    }

    public static function contentType(string $name): string
    {
        return str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png';
    }

    /** @param array{x: float, y: float, size: float}|null $crop */
    private function cut(\GdImage $src, ?array $crop): string
    {
        [$x, $y, $edge] = self::cropRect(imagesx($src), imagesy($src), $crop);
        $target = min(self::PHOTO_SIZE, $edge);

        return $this->save($this->resample($src, $x, $y, $edge, $edge, $target, $target, false), 'jpg');
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

    /** Copies a region into a new image; transparent areas become white for JPEG. */
    private function resample(\GdImage $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh, bool $alpha): \GdImage
    {
        $dst = imagecreatetruecolor($dw, $dh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    private function save(\GdImage $image, string $type): string
    {
        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new \InvalidArgumentException('upload.failed');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $type;
        $path = $this->uploadDir . '/' . $name;
        $ok = $type === 'jpg' ? imagejpeg($image, $path, 86) : imagepng($image, $path, 9);
        if (!$ok) {
            throw new \InvalidArgumentException('upload.failed');
        }

        return $name;
    }
}
