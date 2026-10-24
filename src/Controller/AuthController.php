<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Http\Request;
use Crownd\Http\Response;

final class AuthController extends Controller
{
    public function showLogin(Request $request, array $params): Response
    {
        if ($this->app->auth()->user() !== null) {
            return $this->redirect('/admin');
        }

        return $this->render('auth/login.html.twig', ['username' => '', 'error' => null]);
    }

    public function login(Request $request, array $params): Response
    {
        $username = strtolower($request->input('username'));
        $result = $this->app->auth()->attempt($username, $request->raw('password'), $request->ip());
        if ($result !== 'ok') {
            return $this->render('auth/login.html.twig', [
                'username' => $username,
                'error' => 'auth.login_' . $result,
            ], $result === 'rate_limited' ? 429 : 422);
        }

        $returnTo = $this->app->session->get('login.return_to');
        $this->app->session->remove('login.return_to');
        $target = is_string($returnTo) && preg_match('#^/admin(/[A-Za-z0-9/_-]*)?$#', $returnTo) ? $returnTo : '/admin';

        return $this->redirect($target);
    }

    public function logout(Request $request, array $params): Response
    {
        $this->app->auth()->logout();
        $this->flash('success', 'auth.logged_out');

        return $this->redirect('/');
    }
}
