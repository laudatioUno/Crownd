<?php

declare(strict_types=1);

namespace Crownd\Security;

use Crownd\Database\Database;
use Crownd\Http\Session;
use Crownd\Repository\AdminUserRepository;

/**
 * Admin login with username and password (Argon2id), account lock after
 * repeated failures and a rate limit per IP address.
 */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const FAILURE_WINDOW = 900;      // 15 minutes
    public const LOCK_SECONDS = 900;        // 15 minutes
    public const IDLE_TIMEOUT = 14400;      // 4 hours: a match day must not log the admin out
    public const ABSOLUTE_TIMEOUT = 43200;  // 12 hours

    private static ?string $dummyHash = null;

    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $current = false;

    public function __construct(
        private readonly Session $session,
        private readonly AdminUserRepository $users,
        private readonly RateLimiter $limiter,
    ) {
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    /** @return string|null translation key of the problem, or null if acceptable */
    public static function passwordProblem(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'auth.password_too_short';
        }
        if ($password !== $confirm) {
            return 'auth.password_mismatch';
        }

        return null;
    }

    /** Returns one of: ok, invalid, locked, rate_limited. */
    public function attempt(string $username, string $password, string $ip): string
    {
        if (!$this->limiter->hit('login_ip', $ip, 30, self::FAILURE_WINDOW)) {
            return 'rate_limited';
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || (int) $user['active'] !== 1) {
            self::$dummyHash ??= self::hashPassword(Tokens::random(16));
            password_verify($password, self::$dummyHash);

            return 'invalid';
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            return 'locked';
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->registerFailure($user);

            return 'invalid';
        }
        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->users->update((int) $user['id'], ['password_hash' => self::hashPassword($password)]);
        }
        $this->login($user);

        return 'ok';
    }

    /** @param array<string, mixed> $user */
    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->set('admin_id', (int) $user['id']);
        $this->session->set('login.login_at', time());
        $this->session->set('login.last_activity', time());
        $this->users->update((int) $user['id'], [
            'last_login_at' => Database::now(),
            'failed_attempts' => 0,
            'first_failed_at' => null,
            'locked_until' => null,
        ]);
        $this->current = false;
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->current = null;
    }

    /** @return array<string, mixed>|null the logged-in admin, null after a timeout */
    public function user(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $id = $this->session->get('admin_id');
        $loginAt = (int) $this->session->get('login.login_at', 0);
        $lastActivity = (int) $this->session->get('login.last_activity', 0);
        $now = time();

        $user = null;
        if (is_int($id) && $now - $lastActivity <= self::IDLE_TIMEOUT && $now - $loginAt <= self::ABSOLUTE_TIMEOUT) {
            $user = $this->users->find($id);
            if ($user !== null && (int) $user['active'] !== 1) {
                $user = null;
            }
        }

        if ($user === null && is_int($id)) {
            $this->session->clear();
        } elseif ($user !== null) {
            $this->session->set('login.last_activity', $now);
        }

        return $this->current = $user;
    }

    /** @param array<string, mixed> $user */
    private function registerFailure(array $user): void
    {
        $now = time();
        $first = $user['first_failed_at'] === null ? null : strtotime($user['first_failed_at'] . ' UTC');
        $count = (int) $user['failed_attempts'];
        if ($first === null || $now - $first > self::FAILURE_WINDOW) {
            $count = 0;
            $first = $now;
        }
        $count++;

        $data = ['failed_attempts' => $count, 'first_failed_at' => gmdate('Y-m-d H:i:s', $first)];
        if ($count >= self::MAX_FAILURES) {
            $data = [
                'failed_attempts' => 0,
                'first_failed_at' => null,
                'locked_until' => gmdate('Y-m-d H:i:s', $now + self::LOCK_SECONDS),
            ];
        }
        $this->users->update((int) $user['id'], $data);
    }
}
