<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Security\Auth;
use Crownd\Validation;

/** Admin accounts: every admin can manage all accounts; the last active one cannot be deactivated. */
final class UserController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $this->admin();

        return $this->list([], []);
    }

    public function store(Request $request, array $params): Response
    {
        $this->admin();
        $input = [
            'display_name' => $request->input('display_name'),
            'username' => strtolower($request->input('username')),
        ];
        $password = $request->raw('password');
        $errors = [];
        if ($e = Validation::required($input['display_name'])) {
            $errors['display_name'] = $e;
        }
        if ($e = Validation::username($input['username'])) {
            $errors['username'] = $e;
        } elseif ($this->app->adminUsers()->findByUsername($input['username']) !== null) {
            $errors['username'] = 'users.username_taken';
        }
        if ($e = Auth::passwordProblem($password, $request->raw('password_confirm'))) {
            $errors['password'] = $e;
        }
        if ($errors !== []) {
            return $this->list($input, $errors, 422);
        }
        $this->app->adminUsers()->create($input + ['password_hash' => Auth::hashPassword($password)]);
        $this->flash('success', 'users.created', ['name' => $input['display_name']]);

        return $this->redirect('/admin/users');
    }

    public function toggle(Request $request, array $params): Response
    {
        $me = $this->admin();
        $user = self::found($this->app->adminUsers()->find(self::id($params)));
        if ((int) $user['id'] === (int) $me['id']) {
            throw new HttpException(403);
        }
        $activate = (int) $user['active'] !== 1;
        $this->app->adminUsers()->update((int) $user['id'], ['active' => $activate ? 1 : 0]);
        $this->flash('success', $activate ? 'users.activated' : 'users.deactivated', ['name' => (string) $user['display_name']]);

        return $this->redirect('/admin/users');
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $this->admin();
        $user = self::found($this->app->adminUsers()->find(self::id($params)));
        $password = $request->raw('password');
        if ($e = Auth::passwordProblem($password, $password)) {
            $this->flash('error', $e);

            return $this->redirect('/admin/users');
        }
        $this->app->adminUsers()->update((int) $user['id'], [
            'password_hash' => Auth::hashPassword($password),
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);
        $this->flash('success', 'users.password_reset', ['name' => (string) $user['display_name']]);

        return $this->redirect('/admin/users');
    }

    public function account(Request $request, array $params): Response
    {
        $me = $this->admin();

        return $this->render('admin/account.html.twig', ['values' => ['display_name' => $me['display_name']], 'errors' => []]);
    }

    public function saveAccount(Request $request, array $params): Response
    {
        $me = $this->admin();
        $values = ['display_name' => $request->input('display_name')];
        $errors = [];
        if ($e = Validation::required($values['display_name'])) {
            $errors['display_name'] = $e;
        }
        $new = $request->raw('new_password');
        if ($new !== '') {
            if (!password_verify($request->raw('current_password'), (string) $me['password_hash'])) {
                $errors['current_password'] = 'auth.current_password_wrong';
            }
            if ($e = Auth::passwordProblem($new, $request->raw('new_password_confirm'))) {
                $errors['new_password'] = $e;
            }
        }
        if ($errors !== []) {
            return $this->render('admin/account.html.twig', ['values' => $values, 'errors' => $errors], 422);
        }
        $data = ['display_name' => $values['display_name']];
        if ($new !== '') {
            $data['password_hash'] = Auth::hashPassword($new);
        }
        $this->app->adminUsers()->update((int) $me['id'], $data);
        $this->flash('success', 'users.account_saved');

        return $this->redirect('/admin/account');
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $errors
     */
    private function list(array $input, array $errors, int $status = 200): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $this->app->adminUsers()->all(),
            'input' => $input + ['display_name' => '', 'username' => ''],
            'errors' => $errors,
        ], $status);
    }
}
