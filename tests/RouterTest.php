<?php

declare(strict_types=1);

namespace Crownd\Tests;

use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testPlaceholdersAndMethods(): void
    {
        $r = new Router();
        $r->get('/admin/matches/{id}', static fn () => 'show');
        $r->get('/media/{file:[a-f0-9]+\.png}', static fn () => 'media');

        [$h, $p] = $r->match('GET', '/admin/matches/12');
        self::assertSame(['id' => '12'], $p);
        [$h, $p] = $r->match('GET', '/media/' . str_repeat('ab', 16) . '.png');
        self::assertSame('media', $h());

        $this->expectException(HttpException::class);
        $r->match('GET', '/media/../config.php');
    }

    public function testBasePathInSubfolder(): void
    {
        // Project folder uploaded as /mvp, the .htaccess forwards to public/.
        self::assertSame('/mvp', Request::detectBasePath('/mvp/public/index.php', '/mvp'));
        self::assertSame('/mvp', Request::detectBasePath('/mvp/public/index.php', '/mvp/admin/teams'));
        self::assertSame('', Request::detectBasePath('/index.php', '/admin'));
    }
}
