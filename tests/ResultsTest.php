<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\Service\Results;
use PHPUnit\Framework\TestCase;

final class ResultsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function lineup(): array
    {
        return [
            ['id' => 1, 'name' => 'Lena', 'number' => 1],
            ['id' => 2, 'name' => 'Nico', 'number' => 7],
            ['id' => 3, 'name' => 'Jonas', 'number' => 10],
        ];
    }

    public function testWinnerIsOnTopWithPercentAndBar(): void
    {
        $r = Results::rank(self::lineup(), [2 => 6, 3 => 2]);

        self::assertSame(8, $r['votes']);
        self::assertFalse($r['tie']);
        self::assertCount(1, $r['winners']);
        self::assertSame('Nico', $r['winners'][0]['name']);
        self::assertSame(75.0, $r['players'][0]['percent']);
        self::assertSame(100.0, $r['players'][0]['bar']);
        self::assertSame(['Nico', 'Jonas', 'Lena'], array_column($r['players'], 'name'));
        self::assertSame([1, 2, 3], array_column($r['players'], 'rank'));
        self::assertSame(0, $r['players'][2]['votes']);
    }

    public function testTieGivesSeveralWinnersAndSharedRank(): void
    {
        $r = Results::rank(self::lineup(), [1 => 3, 3 => 3, 2 => 1]);

        self::assertTrue($r['tie']);
        self::assertSame(['Lena', 'Jonas'], array_column($r['winners'], 'name'), 'line-up order on a tie');
        self::assertSame([1, 1, 3], array_column($r['players'], 'rank'));
    }

    public function testNoVotesMeansNoWinner(): void
    {
        $r = Results::rank(self::lineup(), []);

        self::assertSame([], $r['winners']);
        self::assertFalse($r['tie']);
        self::assertSame([0.0, 0.0, 0.0], array_column($r['players'], 'percent'));
        self::assertSame(['Lena', 'Nico', 'Jonas'], array_column($r['players'], 'name'));
    }
}
