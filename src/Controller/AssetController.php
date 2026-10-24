<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Service\Branding;

final class AssetController extends Controller
{
    /**
     * CSS variables for the club color and one class per team color.
     * Generated, because inline styles are blocked by the Content-Security-Policy.
     */
    public function theme(Request $request, array $params): Response
    {
        $b = $this->app->branding()->club();
        $css = ":root{--accent:{$b['primary_color']};--accent-dark:{$b['primary_dark']};--on-accent:{$b['on_primary']};}\n";
        if ($this->app->config->isInstalled()) {
            foreach ($this->app->teams()->colors() as $team) {
                $color = Branding::validColor((string) $team['color']);
                if ($color !== null) {
                    $css .= sprintf(
                        ".team-%d{--team:%s;--team-dark:%s;--on-team:%s;}\n",
                        (int) $team['id'],
                        $color,
                        Branding::darken($color, 0.25),
                        Branding::textColorOn($color),
                    );
                }
            }
        }

        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Uploaded images (logos, player photos), stored outside the web root. */
    public function media(Request $request, array $params): Response
    {
        $path = $this->app->images()->path($params['file']);
        if ($path === null) {
            throw new HttpException(404);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
