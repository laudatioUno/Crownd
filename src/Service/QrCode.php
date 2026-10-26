<?php

declare(strict_types=1);

namespace Crownd\Service;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/** QR codes for the voting link, generated locally (no external service). */
final class QrCode
{
    /** PNG as data URI, sharp enough to print on A4. */
    public function dataUri(string $text, int $scale = 12): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'quietzoneSize' => 2,
        ]);

        return (new Generator($options))->render($text);
    }
}
