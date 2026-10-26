<?php

declare(strict_types=1);

namespace Crownd;

use Crownd\Controller\AdminController;
use Crownd\Controller\AssetController;
use Crownd\Controller\AuthController;
use Crownd\Controller\InstallController;
use Crownd\Controller\MatchController;
use Crownd\Controller\PublicController;
use Crownd\Controller\TeamController;
use Crownd\Controller\UserController;
use Crownd\Http\Router;

/**
 * All routes of the application.
 */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $c = static fn (string $class, string $method): \Closure =>
            static fn ($request, array $params) => (new $class($app))->{$method}($request, $params);

        // Voters
        $r->get('/', $c(PublicController::class, 'home'));
        $r->post('/vote', $c(PublicController::class, 'vote'));
        $r->get('/results/{id}', $c(PublicController::class, 'results'));
        $r->get('/results/{id}/live', $c(PublicController::class, 'resultsLive'));

        // Assets
        $r->get('/theme.css', $c(AssetController::class, 'theme'));
        $r->get('/media/{file:[a-f0-9]+\.png}', $c(AssetController::class, 'media'));

        // Installation
        $r->get('/install', $c(InstallController::class, 'show'));
        $r->post('/install', $c(InstallController::class, 'install'));

        // Login
        $r->get('/admin/login', $c(AuthController::class, 'showLogin'));
        $r->post('/admin/login', $c(AuthController::class, 'login'));
        $r->post('/admin/logout', $c(AuthController::class, 'logout'));

        // Admin: overview, settings, poster
        $r->get('/admin', $c(AdminController::class, 'dashboard'));
        $r->get('/admin/settings', $c(AdminController::class, 'settings'));
        $r->post('/admin/settings', $c(AdminController::class, 'saveSettings'));
        $r->get('/admin/poster', $c(AdminController::class, 'poster'));

        // Admin: teams, players, templates
        $r->get('/admin/teams', $c(TeamController::class, 'index'));
        $r->get('/admin/teams/new', $c(TeamController::class, 'create'));
        $r->post('/admin/teams/new', $c(TeamController::class, 'store'));
        $r->get('/admin/teams/{id}', $c(TeamController::class, 'show'));
        $r->post('/admin/teams/{id}', $c(TeamController::class, 'update'));
        $r->post('/admin/teams/{id}/archive', $c(TeamController::class, 'archive'));
        $r->post('/admin/teams/{id}/delete', $c(TeamController::class, 'delete'));
        $r->post('/admin/teams/{id}/players', $c(TeamController::class, 'addPlayer'));
        $r->post('/admin/teams/{id}/players/bulk', $c(TeamController::class, 'addPlayers'));
        $r->get('/admin/players/{id}', $c(TeamController::class, 'editPlayer'));
        $r->post('/admin/players/{id}', $c(TeamController::class, 'updatePlayer'));
        $r->post('/admin/players/{id}/delete', $c(TeamController::class, 'deletePlayer'));
        $r->post('/admin/teams/{id}/squads', $c(TeamController::class, 'createSquad'));
        $r->get('/admin/squads/{id}', $c(TeamController::class, 'editSquad'));
        $r->post('/admin/squads/{id}', $c(TeamController::class, 'updateSquad'));
        $r->post('/admin/squads/{id}/delete', $c(TeamController::class, 'deleteSquad'));

        // Admin: matches and polls
        $r->get('/admin/matches', $c(MatchController::class, 'index'));
        $r->get('/admin/matches/new', $c(MatchController::class, 'create'));
        $r->post('/admin/matches/new', $c(MatchController::class, 'store'));
        $r->get('/admin/matches/{id}', $c(MatchController::class, 'show'));
        $r->get('/admin/matches/{id}/edit', $c(MatchController::class, 'edit'));
        $r->post('/admin/matches/{id}/edit', $c(MatchController::class, 'update'));
        $r->post('/admin/matches/{id}/lineup', $c(MatchController::class, 'saveLineup'));
        $r->post('/admin/matches/{id}/players', $c(MatchController::class, 'addPlayers'));
        $r->post('/admin/matches/{id}/open', $c(MatchController::class, 'open'));
        $r->post('/admin/matches/{id}/close', $c(MatchController::class, 'close'));
        $r->post('/admin/matches/{id}/visibility', $c(MatchController::class, 'visibility'));
        $r->post('/admin/matches/{id}/reset', $c(MatchController::class, 'reset'));
        $r->post('/admin/matches/{id}/delete', $c(MatchController::class, 'delete'));
        $r->get('/admin/matches/{id}/results', $c(MatchController::class, 'results'));
        $r->get('/admin/matches/{id}/results/live', $c(MatchController::class, 'resultsLive'));
        $r->get('/admin/matches/{id}/present', $c(MatchController::class, 'present'));

        // Admin: accounts
        $r->get('/admin/users', $c(UserController::class, 'index'));
        $r->post('/admin/users', $c(UserController::class, 'store'));
        $r->post('/admin/users/{id}/toggle', $c(UserController::class, 'toggle'));
        $r->post('/admin/users/{id}/password', $c(UserController::class, 'resetPassword'));
        $r->get('/admin/account', $c(UserController::class, 'account'));
        $r->post('/admin/account', $c(UserController::class, 'saveAccount'));
    }
}
