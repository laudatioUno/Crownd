<?php

declare(strict_types=1);

namespace Crownd\I18n;

/**
 * Swiss German formats. UTC timestamps from the database are shown in the
 * installation's time zone; the kick-off time is a local value and shown as is.
 */
final class Formatter
{
    private const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    private readonly \DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new \DateTimeZone($timezone);
    }

    /** UTC "Y-m-d H:i:s" → "24.10.2026 21:41" in local time. */
    public function datetime(?string $utc): string
    {
        $d = $this->fromUtc($utc);

        return $d === null ? '' : $d->format('d.m.Y H:i');
    }

    /** UTC "Y-m-d H:i:s" → "21:41" in local time. */
    public function time(?string $utc): string
    {
        $d = $this->fromUtc($utc);

        return $d === null ? '' : $d->format('H:i');
    }

    /** Local kick-off "Y-m-d H:i:s" → "Sa, 24.10.2026 · 17:00". */
    public function kickoff(?string $local): string
    {
        if ($local === null || $local === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $this->zone);
        if ($d === false) {
            return '';
        }

        return self::WEEKDAYS[(int) $d->format('w')] . ', ' . $d->format('d.m.Y') . ' · ' . $d->format('H:i');
    }

    /** Local kick-off for an <input type="datetime-local">. */
    public function kickoffInput(?string $local): string
    {
        if ($local === null || $local === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local);

        return $d === false ? '' : $d->format('Y-m-d\TH:i');
    }

    public function number(int|float $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, '.', "\u{2019}");
    }

    private function fromUtc(?string $utc): ?\DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));

        return $d === false ? null : $d->setTimezone($this->zone);
    }
}
