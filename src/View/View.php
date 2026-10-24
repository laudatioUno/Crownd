<?php

declare(strict_types=1);

namespace Crownd\View;

use Crownd\App;
use Crownd\Http\Response;
use Crownd\Security\Csrf;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig setup. Autoescaping is on for all templates.
 */
final class View
{
    private readonly Environment $twig;

    public function __construct(private readonly App $app)
    {
        $cacheDir = $app->root . '/storage/cache/twig';
        $useCache = !$app->debug() && (is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir);

        $this->twig = new Environment(new FilesystemLoader($app->root . '/templates'), [
            'autoescape' => 'html',
            'cache' => $useCache ? $cacheDir : false,
            'strict_variables' => $app->debug(),
            'auto_reload' => true,
        ]);

        $t = $app->translator;
        $f = $app->formatter;
        $this->twig->addFunction(new TwigFunction('t', static fn (string $key, array $params = []) => $t->t($key, $params)));
        $this->twig->addFunction(new TwigFunction('url', fn (string $path = '/') => $this->app->request->url($path)));
        $this->twig->addFunction(new TwigFunction('absolute_url', fn (string $path = '/') => $this->app->request->absoluteUrl($path)));
        $this->twig->addFunction(new TwigFunction('asset', fn (string $path) => $this->asset($path)));
        $this->twig->addFunction(new TwigFunction('media', fn (?string $file) => $file ? $this->app->request->url('/media/' . $file) : ''));
        $this->twig->addFunction(new TwigFunction('csrf_field', fn () => new Markup(
            '<input type="hidden" name="' . Csrf::FIELD . '" value="' . htmlspecialchars($this->app->csrf()->token(), ENT_QUOTES) . '">',
            'UTF-8',
        )));
        $this->twig->addFilter(new TwigFilter('datetime', static fn (?string $v) => $f->datetime($v)));
        $this->twig->addFilter(new TwigFilter('time', static fn (?string $v) => $f->time($v)));
        $this->twig->addFilter(new TwigFilter('kickoff', static fn (?string $v) => $f->kickoff($v)));
        $this->twig->addFilter(new TwigFilter('num', static fn (int|float $v, int $d = 0) => $f->number($v, $d)));
        $this->twig->addFilter(new TwigFilter('initials', static fn (string $name) => self::initials($name)));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->renderString($template, $data), $status);
    }

    /** @param array<string, mixed> $data */
    public function renderString(string $template, array $data = []): string
    {
        $user = null;
        if ($this->app->config->isInstalled() && isset($this->app->session)) {
            try {
                $user = $this->app->auth()->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        $globals = [
            'version' => App::VERSION,
            'brand' => $this->safeBranding(),
            'current_user' => $user,
            'flashes' => isset($this->app->session) ? $this->app->session->takeFlashes() : [],
            'current_path' => isset($this->app->request) ? $this->app->request->path : '/',
            'lang' => $this->app->translator->locale,
            'theme_version' => $this->themeVersion(),
        ];

        return $this->twig->render($template, $data + $globals);
    }

    /** "Michael Studer" → "MS", "Nico" → "NI". */
    public static function initials(string $name): string
    {
        $parts = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }

    private function asset(string $path): string
    {
        $file = $this->app->root . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : App::VERSION;

        return $this->app->request->url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
    }

    /** Changes whenever the club color or a team color changes, so /theme.css is reloaded. */
    private function themeVersion(): string
    {
        if (!$this->app->config->isInstalled()) {
            return 'install';
        }
        try {
            return substr(md5($this->app->branding()->club()['primary_color'] . '|' . $this->app->teams()->colorFingerprint()), 0, 10);
        } catch (\Throwable) {
            return 'x';
        }
    }

    /** @return array<string, string> */
    private function safeBranding(): array
    {
        try {
            return $this->app->branding()->club();
        } catch (\Throwable) {
            return (new \Crownd\Service\Branding(null))->club();
        }
    }
}
