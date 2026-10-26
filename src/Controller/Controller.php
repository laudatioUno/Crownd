<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\App;
use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Response;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->app->view()->render($template, $data, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->app->request->url($path));
    }

    /** @param array<string, string|int> $params */
    protected function flash(string $type, string $key, array $params = []): void
    {
        $this->app->session->flash($type, $this->app->translator->t($key, $params));
    }

    /** @param array<string, string|int> $params */
    protected function t(string $key, array $params = []): string
    {
        return $this->app->translator->t($key, $params);
    }

    /**
     * Returns the logged-in admin or throws a redirect to the login page.
     * After an update with new migrations, they are run automatically.
     *
     * @return array<string, mixed>
     */
    protected function admin(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            $this->app->session->set('login.return_to', $this->app->request->path);
            throw new RedirectException($this->app->request->url('/admin/login'));
        }
        $this->runPendingMigrations();

        return $user;
    }

    /** @param array<string, string> $params */
    protected static function id(array $params, string $key = 'id'): int
    {
        return (int) ($params[$key] ?? 0);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    protected static function found(?array $row): array
    {
        if ($row === null) {
            throw new HttpException(404);
        }

        return $row;
    }

    /** @return list<int> positive integers from a checkbox list like players[] */
    protected static function idList(Request $request, string $key): array
    {
        $raw = $request->post[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Stores an uploaded image field; returns the new file name, null when no file was
     * chosen, or adds an error and returns false.
     *
     * @param array<string, string> $errors
     */
    protected function upload(Request $request, string $field, array &$errors, bool $square = false): string|false|null
    {
        $file = $request->file($field);
        if ($file === null) {
            return null;
        }
        try {
            return $square ? $this->app->images()->storeSquare($file) : $this->app->images()->store($file);
        } catch (\InvalidArgumentException $e) {
            $errors[$field] = $e->getMessage();

            return false;
        }
    }

    private function runPendingMigrations(): void
    {
        $migrator = $this->app->migrator();
        if ($migrator->pending() !== []) {
            $migrator->migrate();
        }
    }
}
