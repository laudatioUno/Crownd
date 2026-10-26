<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\App;
use Crownd\Config;
use Crownd\Database\Database;
use Crownd\Database\Migrator;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Repository\AdminUserRepository;
use Crownd\Repository\SettingsRepository;
use Crownd\Security\Auth;
use Crownd\Security\Crypto;
use Crownd\Validation;

/**
 * Web installer: checks the server, creates the tables, writes config/config.php
 * and the first admin, then logs them in. Locked as soon as config.php exists.
 */
final class InstallController extends Controller
{
    private const FIELDS = ['db_host', 'db_port', 'db_name', 'db_user', 'club_name', 'admin_name', 'admin_username'];

    public function show(Request $request, array $params): Response
    {
        return $this->form(['db_host' => 'localhost', 'db_port' => '3306'], []);
    }

    public function install(Request $request, array $params): Response
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = $request->input($field);
        }
        $input['admin_username'] = strtolower($input['admin_username']);
        $password = $request->raw('password');

        $errors = [];
        if (!$this->requirementsMet()) {
            $errors['_form'] = 'install.requirements_failed';
        }
        foreach (['db_host', 'db_name', 'db_user', 'club_name', 'admin_name'] as $field) {
            if ($e = Validation::required($input[$field])) {
                $errors[$field] = $e;
            }
        }
        if (!preg_match('/^\d{1,5}$/', $input['db_port'])) {
            $errors['db_port'] = 'validation.port';
        }
        if ($e = Validation::username($input['admin_username'])) {
            $errors['admin_username'] = $e;
        }
        if ($e = Auth::passwordProblem($password, $request->raw('password_confirm'))) {
            $errors['password'] = $e;
        }
        if ($errors !== []) {
            return $this->form($input, $errors, 422);
        }

        $dbConfig = [
            'host' => $input['db_host'],
            'port' => (int) $input['db_port'],
            'name' => $input['db_name'],
            'user' => $input['db_user'],
            'pass' => $request->raw('db_pass'),
        ];
        try {
            $db = Database::connect($dbConfig);
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->form($input, ['_form' => 'install.db_failed'], 422, $this->dbHint($e));
        }

        try {
            $hasAdmins = (int) $db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'crownd_admin_users'") > 0
                && (int) $db->value('SELECT COUNT(*) FROM crownd_admin_users') > 0;
            if ($hasAdmins) {
                return $this->form($input, ['_form' => 'install.db_not_empty'], 422);
            }
            (new Migrator($db, $this->app->root . '/migrations'))->migrate();
        } catch (\PDOException $e) {
            $this->app->logError($e);

            return $this->form($input, ['_form' => 'install.migration_failed'], 500, $e->getMessage());
        }

        $key = Crypto::generateKey();
        $configValues = [
            'db' => $dbConfig,
            'app' => [
                'key' => $key,
                'locale' => 'de',
                'timezone' => 'Europe/Zurich',
                'debug' => false,
                'installed_at' => gmdate('c'),
            ],
        ];
        if (!$this->writeConfig(Config::render($configValues))) {
            return $this->form($input, ['_form' => 'install.config_not_writable'], 500);
        }

        (new SettingsRepository($db, new Crypto($key)))->setMany([
            'club_name' => $input['club_name'],
            'primary_color' => '#0B5FA8',
            'award_label' => 'Player of the Match',
        ]);
        $users = new AdminUserRepository($db);
        $id = $users->create([
            'username' => $input['admin_username'],
            'display_name' => $input['admin_name'],
            'password_hash' => Auth::hashPassword($password),
        ]);

        $this->app->reloadConfig();
        $this->app->useDatabase($db);
        $this->app->auth()->login((array) $users->find($id));
        $this->flash('success', 'install.done');

        return $this->redirect('/admin');
    }

    /** @return list<array{label: string, ok: bool, detail: string}> */
    private function requirements(): array
    {
        $root = $this->app->root;
        $ext = static fn (string $name) => extension_loaded($name);

        return [
            ['label' => 'PHP ≥ 8.3', 'ok' => PHP_VERSION_ID >= 80300, 'detail' => PHP_VERSION],
            ['label' => 'pdo_mysql', 'ok' => $ext('pdo_mysql'), 'detail' => ''],
            ['label' => 'sodium', 'ok' => $ext('sodium'), 'detail' => ''],
            ['label' => 'gd', 'ok' => $ext('gd'), 'detail' => ''],
            ['label' => 'mbstring', 'ok' => $ext('mbstring'), 'detail' => ''],
            ['label' => 'config/ ' . $this->t('install.writable'), 'ok' => is_writable($root . '/config'), 'detail' => ''],
            ['label' => 'storage/ ' . $this->t('install.writable'), 'ok' => is_writable($root . '/storage'), 'detail' => ''],
        ];
    }

    private function requirementsMet(): bool
    {
        foreach ($this->requirements() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $input @param array<string, string> $errors */
    private function form(array $input, array $errors, int $status = 200, ?string $detail = null): Response
    {
        return $this->render('install/install.html.twig', [
            'checks' => $this->requirements(),
            'requirements_ok' => $this->requirementsMet(),
            'input' => $input + array_fill_keys(self::FIELDS, ''),
            'errors' => $errors,
            'detail' => $detail,
            'app_version' => App::VERSION,
        ], $status);
    }

    private function writeConfig(string $content): bool
    {
        $path = $this->app->root . '/config/config.php';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }
        $ok = fwrite($handle, $content) === strlen($content);
        fclose($handle);
        @chmod($path, 0640);

        return $ok;
    }

    private function dbHint(\PDOException $e): string
    {
        $code = (string) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            '1045' => $this->t('install.db_hint_access'),
            '1049' => $this->t('install.db_hint_unknown_db'),
            '2002', '2005' => $this->t('install.db_hint_host'),
            default => $this->t('install.db_hint_generic', ['code' => $code]),
        };
    }
}
