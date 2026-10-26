<?php

declare(strict_types=1);

namespace Crownd;

use Crownd\Database\Database;
use Crownd\Database\Migrator;
use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Http\Router;
use Crownd\Http\Session;
use Crownd\I18n\Formatter;
use Crownd\I18n\Translator;
use Crownd\Repository\AdminUserRepository;
use Crownd\Repository\MatchRepository;
use Crownd\Repository\PlayerRepository;
use Crownd\Repository\SettingsRepository;
use Crownd\Repository\SquadRepository;
use Crownd\Repository\TeamRepository;
use Crownd\Security\Auth;
use Crownd\Security\Crypto;
use Crownd\Security\Csrf;
use Crownd\Security\RateLimiter;
use Crownd\Service\Branding;
use Crownd\Service\ImageUpload;
use Crownd\Service\QrCode;
use Crownd\Service\Results;
use Crownd\Service\VotingService;
use Crownd\View\View;

/**
 * Application kernel: builds services on demand and dispatches the request.
 */
final class App
{
    public const VERSION = '1.0.0';

    public Config $config;
    public readonly Translator $translator;
    public readonly Formatter $formatter;
    public Request $request;
    public Session $session;

    private ?Database $db = null;
    private ?View $view = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct(public readonly string $root)
    {
        $this->config = Config::load($root);
        $timezone = (string) $this->config->get('app.timezone', 'Europe/Zurich');
        date_default_timezone_set($timezone);
        $this->translator = new Translator($root . '/lang', (string) $this->config->get('app.locale', 'de'));
        $this->formatter = new Formatter($timezone);
    }

    public static function boot(string $root): self
    {
        return new self($root);
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->session = new Session($request->isSecure(), $request->basePath === '' ? '/' : $request->basePath);

        try {
            $response = $this->dispatch($request);
        } catch (Controller\RedirectException $e) {
            $response = Response::redirect($e->location);
        } catch (HttpException $e) {
            $response = $this->errorResponse($e->status);
        } catch (\Throwable $e) {
            $this->logError($e);
            $response = $this->errorResponse(500, $this->debug() ? $e::class . ': ' . $e->getMessage() : null);
        }

        return SecurityHeaders::apply($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        $installing = str_starts_with($request->path, '/install');
        $isAsset = str_starts_with($request->path, '/theme.css');
        if (!$this->config->isInstalled() && !$installing && !$isAsset) {
            return Response::redirect($request->url('/install'));
        }
        if ($this->config->isInstalled() && $installing) {
            throw new HttpException(404);
        }

        $router = new Router();
        Routes::register($router, $this);
        [$handler, $params] = $router->match($request->method, $request->path);

        // The ballot checks its token itself, to explain blocked cookies instead of «session expired».
        if ($request->isPost() && $request->path !== '/vote') {
            $this->csrf()->check($request);
        }

        return $handler($request, $params);
    }

    public function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    public function db(): Database
    {
        return $this->db ??= Database::connect($this->config->db());
    }

    /** Used by the installer, which connects before config.php exists. */
    public function useDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function reloadConfig(): void
    {
        $this->config = Config::load($this->root);
        $this->services = [];
    }

    public function view(): View
    {
        return $this->view ??= new View($this);
    }

    public function crypto(): Crypto
    {
        return $this->service(Crypto::class, fn () => new Crypto((string) $this->config->get('app.key')));
    }

    public function settings(): SettingsRepository
    {
        return $this->service(SettingsRepository::class, fn () => new SettingsRepository($this->db(), $this->crypto()));
    }

    public function branding(): Branding
    {
        return $this->service(Branding::class, fn () => new Branding($this->config->isInstalled() ? $this->settings() : null));
    }

    public function adminUsers(): AdminUserRepository
    {
        return $this->service(AdminUserRepository::class, fn () => new AdminUserRepository($this->db()));
    }

    public function teams(): TeamRepository
    {
        return $this->service(TeamRepository::class, fn () => new TeamRepository($this->db()));
    }

    public function players(): PlayerRepository
    {
        return $this->service(PlayerRepository::class, fn () => new PlayerRepository($this->db()));
    }

    public function squads(): SquadRepository
    {
        return $this->service(SquadRepository::class, fn () => new SquadRepository($this->db()));
    }

    public function matches(): MatchRepository
    {
        return $this->service(MatchRepository::class, fn () => new MatchRepository($this->db()));
    }

    public function voting(): VotingService
    {
        return $this->service(VotingService::class, fn () => new VotingService($this->db(), $this->matches(), $this->rateLimiter()));
    }

    public function results(): Results
    {
        return $this->service(Results::class, fn () => new Results($this->db(), $this->matches()));
    }

    public function qr(): QrCode
    {
        return $this->service(QrCode::class, fn () => new QrCode());
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->service(RateLimiter::class, fn () => new RateLimiter($this->db()));
    }

    public function csrf(): Csrf
    {
        return $this->service(Csrf::class, fn () => new Csrf($this->session));
    }

    public function images(): ImageUpload
    {
        return $this->service(ImageUpload::class, fn () => new ImageUpload($this->root . '/storage/uploads'));
    }

    public function migrator(): Migrator
    {
        return $this->service(Migrator::class, fn () => new Migrator($this->db(), $this->root . '/migrations'));
    }

    public function auth(): Auth
    {
        return $this->service(Auth::class, fn () => new Auth($this->session, $this->adminUsers(), $this->rateLimiter()));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function service(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }

    public function errorResponse(int $status, ?string $detail = null): Response
    {
        $key = in_array($status, [403, 404, 405, 419, 429, 503], true) ? (string) $status : '500';
        try {
            return $this->view()->render('error.html.twig', [
                'status' => $status,
                'title' => $this->translator->t('errors.' . $key . '.title'),
                'message' => $this->translator->t('errors.' . $key . '.text'),
                'detail' => $detail,
            ], $status);
        } catch (\Throwable $e) {
            $this->logError($e);

            return Response::text($this->translator->t('errors.500.title'), $status);
        }
    }

    public function logError(\Throwable $e): void
    {
        $dir = $this->root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n",
            gmdate('c'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
