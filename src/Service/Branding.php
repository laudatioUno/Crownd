<?php

declare(strict_types=1);

namespace Crownd\Service;

use Crownd\Repository\SettingsRepository;

/**
 * Club data and look of the installation: name, logo, color and the texts
 * shown to voters. Everything has a sensible default, so a fresh install works.
 */
final class Branding
{
    public const DEFAULT_COLOR = '#0B5FA8';

    public function __construct(private readonly ?SettingsRepository $settings)
    {
    }

    /** @return array<string, string> */
    public function club(): array
    {
        $s = fn (string $key, string $default = ''): string => trim($this->settings?->get($key, $default) ?? $default) ?: $default;
        $color = self::validColor($s('primary_color')) ?? self::DEFAULT_COLOR;

        return [
            'club_name' => $s('club_name', 'Crownd'),
            'primary_color' => $color,
            'on_primary' => self::textColorOn($color),
            'primary_dark' => self::darken($color, 0.25),
            'logo' => $s('logo_file'),
            'award_label' => $s('award_label', 'Player of the Match'),
            'inactive_text' => $s('inactive_text'),
            'thanks_text' => $s('thanks_text'),
            'privacy_url' => $s('privacy_url'),
        ];
    }

    /** Only #RRGGBB is accepted, so the value is safe to put into CSS. */
    public static function validColor(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : null;
    }

    /** White or near-black text, whichever has more contrast on the given color (WCAG). */
    public static function textColorOn(string $hex): string
    {
        $l = self::luminance($hex);
        $contrastWhite = 1.05 / ($l + 0.05);
        $contrastDark = ($l + 0.05) / (self::luminance('#14212E') + 0.05);

        return $contrastWhite >= $contrastDark ? '#FFFFFF' : '#14212E';
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function darken(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = max(0.0, 1.0 - $amount);

        return sprintf('#%02X%02X%02X', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
