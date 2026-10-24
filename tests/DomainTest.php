<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\Domain\MatchStatus;
use Crownd\Repository\PlayerRepository;
use Crownd\Service\VotingService;
use Crownd\View\View;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function testStatusRules(): void
    {
        self::assertTrue(MatchStatus::canOpen('draft'));
        self::assertTrue(MatchStatus::canOpen('closed'), 'reopen after closing by mistake');
        self::assertFalse(MatchStatus::canOpen('open'));
        self::assertTrue(MatchStatus::canClose('open'));
        self::assertFalse(MatchStatus::canClose('draft'));
        self::assertFalse(MatchStatus::allowsLineupChanges('closed'));
        self::assertTrue(MatchStatus::allowsLineupChanges('open'));
    }

    public function testAutomaticEnd(): void
    {
        self::assertNull(MatchStatus::closesAt(null, 0));
        self::assertNull(MatchStatus::closesAt(0, 0));
        self::assertSame('1970-01-01 00:20:00', MatchStatus::closesAt(20, 0));
    }

    public function testPlayerListParsing(): void
    {
        $list = PlayerRepository::parseList("12 Nico Muster\n#7 Lena Keller\n\n  Jonas   Brunner \nTim Frei, 22\n3. Anna Roth\nOle-Gunnar Solskjær");

        self::assertSame([
            ['name' => 'Nico Muster', 'number' => 12],
            ['name' => 'Lena Keller', 'number' => 7],
            ['name' => 'Jonas Brunner', 'number' => null],
            ['name' => 'Tim Frei', 'number' => 22],
            ['name' => 'Anna Roth', 'number' => 3],
            ['name' => 'Ole-Gunnar Solskjær', 'number' => null],
        ], $list);
    }

    public function testVoterHashDependsOnMatchAndToken(): void
    {
        $token = str_repeat('a', 43);
        self::assertTrue(VotingService::isValidToken($token));
        self::assertFalse(VotingService::isValidToken('short'));
        self::assertFalse(VotingService::isValidToken(str_repeat('a', 40) . '<x>'));
        self::assertNotSame(VotingService::voterHash(1, $token), VotingService::voterHash(2, $token));
        self::assertSame(64, strlen(VotingService::voterHash(1, $token)));
    }

    public function testInitials(): void
    {
        self::assertSame('NM', View::initials('Nico Muster'));
        self::assertSame('JB', View::initials('Jonas van den Brunner'));
        self::assertSame('NI', View::initials('Nico'));
        self::assertSame('ÉL', View::initials('élodie Lambert'));
        self::assertSame('?', View::initials('  '));
    }
}
