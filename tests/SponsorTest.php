<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\Service\Branding;
use PHPUnit\Framework\TestCase;

final class SponsorTest extends TestCase
{
    public function testPlacesAreFilteredAndOrdered(): void
    {
        self::assertSame(['waiting', 'ballot', 'poster'], Branding::parsePlaces('poster, ballot,waiting'));
        self::assertSame(['results'], Branding::parsePlaces('results,bogus,<script>'));
        self::assertSame([], Branding::parsePlaces(''));
    }

    public function testNoSponsorWithoutSettings(): void
    {
        self::assertNull((new Branding(null))->sponsor());
    }
}
