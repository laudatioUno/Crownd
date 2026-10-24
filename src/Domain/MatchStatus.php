<?php

declare(strict_types=1);

namespace Crownd\Domain;

/**
 * Poll status of a match: draft (prepared) → open (voting runs) → closed.
 * A closed poll can be reopened, e.g. when it was ended by mistake.
 */
final class MatchStatus
{
    public const DRAFT = 'draft';
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    public static function canOpen(string $status): bool
    {
        return $status === self::DRAFT || $status === self::CLOSED;
    }

    public static function canClose(string $status): bool
    {
        return $status === self::OPEN;
    }

    /** The line-up can be changed until the poll is closed. */
    public static function allowsLineupChanges(string $status): bool
    {
        return $status !== self::CLOSED;
    }

    /**
     * End of the voting in UTC, or null for "until closed by hand".
     */
    public static function closesAt(?int $autoCloseMinutes, int $now): ?string
    {
        if ($autoCloseMinutes === null || $autoCloseMinutes <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $now + $autoCloseMinutes * 60);
    }
}
