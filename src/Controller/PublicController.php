<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Domain\MatchStatus;
use Crownd\Http\HttpException;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Security\Tokens;
use Crownd\Service\VotingService;

/**
 * The page behind the fixed link (e.g. https://example.ch/mvp):
 * «not started yet» → ballot → thank you (with results if the admin shares them).
 */
final class PublicController extends Controller
{
    public function home(Request $request, array $params): Response
    {
        $match = $this->app->matches()->open();
        if ($match === null) {
            return $this->inactive();
        }

        $token = $this->voterToken($request);
        $choices = $this->app->voting()->ballotOf((int) $match['id'], $token);
        $lineups = $this->lineups($match);

        if ($choices !== null) {
            return $this->render('public/thanks.html.twig', [
                'match' => $match,
                'choices' => $this->describeChoices($lineups, $choices),
                'results' => (int) $match['results_public'] === 1 ? $this->app->results()->forMatch($match) : null,
            ]);
        }

        return $this->ballot($match, $lineups, [], null);
    }

    public function vote(Request $request, array $params): Response
    {
        $match = $this->app->matches()->open();
        if ($match === null || (string) $match['id'] !== $request->input('match')) {
            $this->flash('warning', 'vote.closed');

            return $this->redirect('/');
        }
        // Spam bots fill every field; people never see this one.
        if ($request->input('website') !== '') {
            return $this->redirect('/');
        }

        $token = (string) ($request->cookies[VotingService::COOKIE] ?? '');
        if (!VotingService::isValidToken($token)) {
            return $this->ballot($match, $this->lineups($match), [], 'vote.no_cookie', 422);
        }
        $this->app->csrf()->check($request);

        $picks = [];
        foreach ([(int) $match['home_team_id'], (int) $match['away_team_id']] as $teamId) {
            $value = $request->input('pick_' . $teamId);
            if ($value !== '' && ctype_digit($value)) {
                $picks[$teamId] = (int) $value;
            }
        }

        $result = $this->app->voting()->cast($match, $picks, $token, $request->ip());

        return match ($result) {
            'ok', 'already_voted' => $this->redirect('/'),
            'closed' => $this->closedNotice(),
            'rate_limited' => $this->ballot($match, $this->lineups($match), $picks, 'vote.rate_limited', 429),
            'no_cookie' => $this->ballot($match, $this->lineups($match), $picks, 'vote.no_cookie', 422),
            'empty' => $this->ballot($match, $this->lineups($match), $picks, 'vote.empty', 422),
            default => $this->ballot($match, $this->lineups($match), $picks, 'vote.invalid', 422),
        };
    }

    public function results(Request $request, array $params): Response
    {
        $match = $this->publicMatch($params);

        return $this->render('public/results.html.twig', [
            'match' => $match,
            'results' => $this->app->results()->forMatch($match),
            'live_url' => '/results/' . $match['id'] . '/live',
        ]);
    }

    public function resultsLive(Request $request, array $params): Response
    {
        $match = $this->publicMatch($params);

        return $this->render('partials/results.html.twig', [
            'match' => $match,
            'results' => $this->app->results()->forMatch($match),
        ]);
    }

    private function inactive(): Response
    {
        $last = $this->app->matches()->lastClosed();

        return $this->render('public/inactive.html.twig', [
            'upcoming' => $this->app->matches()->upcoming(),
            'last' => $last !== null && (int) $last['results_public'] === 1 ? $last : null,
            'last_results' => $last !== null && (int) $last['results_public'] === 1 ? $this->app->results()->forMatch($last) : null,
        ]);
    }

    /**
     * @param array<string, mixed> $match
     * @param array<int, list<array<string, mixed>>> $lineups
     * @param array<int, int> $picks
     */
    private function ballot(array $match, array $lineups, array $picks, ?string $error, int $status = 200): Response
    {
        return $this->render('public/ballot.html.twig', [
            'match' => $match,
            'lineups' => $lineups,
            'picks' => $picks,
            'error' => $error,
        ], $status);
    }

    private function closedNotice(): Response
    {
        $this->flash('warning', 'vote.closed');

        return $this->redirect('/');
    }

    /**
     * @param array<string, mixed> $match
     * @return array<int, list<array<string, mixed>>> team id => players
     */
    private function lineups(array $match): array
    {
        $out = [];
        foreach ([(int) $match['home_team_id'], (int) $match['away_team_id']] as $teamId) {
            $out[$teamId] = $this->app->matches()->lineup((int) $match['id'], $teamId);
        }

        return $out;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $lineups
     * @param array<int, int> $choices
     * @return array<int, array<string, mixed>> team id => chosen player
     */
    private function describeChoices(array $lineups, array $choices): array
    {
        $out = [];
        foreach ($choices as $teamId => $playerId) {
            foreach ($lineups[$teamId] ?? [] as $player) {
                if ((int) $player['id'] === $playerId) {
                    $out[$teamId] = $player;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> a match whose results the admin has shared */
    private function publicMatch(array $params): array
    {
        $match = $this->app->matches()->find(self::id($params));
        if ($match === null || (int) $match['results_public'] !== 1 || $match['status'] === MatchStatus::DRAFT) {
            throw new HttpException(404);
        }

        return $match;
    }

    /**
     * The device's voter cookie. Created on the first visit while a poll runs,
     * so the browser already has it when the ballot is sent.
     */
    private function voterToken(Request $request): string
    {
        $token = (string) ($request->cookies[VotingService::COOKIE] ?? '');
        if (VotingService::isValidToken($token)) {
            return $token;
        }
        $token = Tokens::random(32);
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(VotingService::COOKIE, $token, [
                'expires' => time() + VotingService::COOKIE_DAYS * 86400,
                'path' => $request->basePath === '' ? '/' : $request->basePath,
                'secure' => $request->isSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $token;
    }
}
