<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Database\Database;
use Crownd\Domain\MatchStatus;
use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Repository\PlayerRepository;
use Crownd\Validation;

/**
 * Matches: one poll each. Created from two teams (and optionally a squad
 * template per team), then the line-up is fine-tuned and the voting started.
 */
final class MatchController extends Controller
{
    private const FIELDS = ['home_team_id', 'away_team_id', 'home_squad_id', 'away_squad_id', 'competition', 'venue', 'kickoff_at', 'auto_close_minutes'];

    public function index(Request $request, array $params): Response
    {
        $this->admin();

        return $this->render('admin/matches/index.html.twig', ['matches' => $this->app->matches()->all()]);
    }

    public function create(Request $request, array $params): Response
    {
        $this->admin();

        return $this->form(null, array_fill_keys(self::FIELDS, '') + ['kickoff_at' => (new \DateTimeImmutable('today 17:00'))->format('Y-m-d\TH:i')], []);
    }

    public function store(Request $request, array $params): Response
    {
        $this->admin();
        [$values, $errors] = $this->input($request, true);
        if ($errors !== []) {
            return $this->form(null, $values, $errors, 422);
        }

        $home = (int) $values['home_team_id'];
        $away = (int) $values['away_team_id'];
        $id = $this->app->db()->transaction(function () use ($values, $home, $away): int {
            $id = $this->app->matches()->create(self::matchData($values) + ['home_team_id' => $home, 'away_team_id' => $away]);
            foreach (['home' => $home, 'away' => $away] as $side => $teamId) {
                $this->app->matches()->setLineup($id, $teamId, $this->startingLineup($teamId, $values[$side . '_squad_id']));
            }

            return $id;
        });
        $this->flash('success', 'matches.created');

        return $this->redirect('/admin/matches/' . $id);
    }

    public function show(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $sides = [];
        foreach (['home', 'away'] as $side) {
            $teamId = (int) $match[$side . '_team_id'];
            $lineup = $this->app->matches()->lineupIds((int) $match['id'], $teamId);
            $players = $this->app->players()->forTeam($teamId, true);
            $sides[] = [
                'side' => $side,
                'team_id' => $teamId,
                'name' => $match[$side . '_name'],
                'logo' => $match[$side . '_logo'],
                // Archived players only appear when they are part of this line-up.
                'players' => array_values(array_filter($players, static fn (array $p) => (int) $p['archived'] === 0 || in_array((int) $p['id'], $lineup, true))),
                'lineup' => $lineup,
                'squads' => $this->app->squads()->forTeam($teamId),
            ];
        }
        $url = rtrim($request->absoluteUrl('/'), '/');

        return $this->render('admin/matches/show.html.twig', [
            'match' => $match,
            'sides' => $sides,
            'other_open' => $this->otherOpen((int) $match['id']),
            'vote_url' => $url,
            'qr' => $this->app->qr()->dataUri($url, 8),
            'lineup_locked' => !MatchStatus::allowsLineupChanges((string) $match['status']),
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));

        return $this->form($match, [
            'home_team_id' => (string) $match['home_team_id'],
            'away_team_id' => (string) $match['away_team_id'],
            'home_squad_id' => '',
            'away_squad_id' => '',
            'competition' => (string) $match['competition'],
            'venue' => (string) $match['venue'],
            'kickoff_at' => $this->app->formatter->kickoffInput($match['kickoff_at']),
            'auto_close_minutes' => $match['auto_close_minutes'] === null ? '' : (string) $match['auto_close_minutes'],
        ], []);
    }

    public function update(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        [$values, $errors] = $this->input($request, false);
        $values['home_team_id'] = (string) $match['home_team_id'];
        $values['away_team_id'] = (string) $match['away_team_id'];
        if ($errors !== []) {
            return $this->form($match, $values, $errors, 422);
        }
        $this->app->matches()->update((int) $match['id'], self::matchData($values));
        $this->flash('success', 'matches.saved');

        return $this->redirect('/admin/matches/' . $match['id']);
    }

    public function saveLineup(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        if (!MatchStatus::allowsLineupChanges((string) $match['status'])) {
            $this->flash('error', 'matches.lineup_locked');

            return $this->redirect('/admin/matches/' . $match['id']);
        }
        $teamId = (int) $request->input('team');
        if (!in_array($teamId, [(int) $match['home_team_id'], (int) $match['away_team_id']], true)) {
            return $this->redirect('/admin/matches/' . $match['id']);
        }

        $squadId = $request->input('apply_squad');
        if ($squadId !== '' && ctype_digit($squadId)) {
            $squad = $this->app->squads()->find((int) $squadId);
            if ($squad !== null && (int) $squad['team_id'] === $teamId) {
                $this->app->matches()->setLineup((int) $match['id'], $teamId, $this->startingLineup($teamId, $squadId));
                $this->flash('success', 'matches.squad_applied', ['name' => (string) $squad['name']]);
            }
        } else {
            $this->app->matches()->setLineup((int) $match['id'], $teamId, self::idList($request, 'players'));
            $this->flash('success', 'matches.lineup_saved');
        }

        return $this->redirect('/admin/matches/' . $match['id'] . '#team-' . $teamId);
    }

    /** Adds players to a team and straight into this line-up (e.g. opponents named on the match sheet). */
    public function addPlayers(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $teamId = (int) $request->input('team');
        if (!MatchStatus::allowsLineupChanges((string) $match['status'])
            || !in_array($teamId, [(int) $match['home_team_id'], (int) $match['away_team_id']], true)) {
            return $this->redirect('/admin/matches/' . $match['id']);
        }
        $count = 0;
        foreach (array_slice(PlayerRepository::parseList($request->input('list')), 0, 60) as $player) {
            $playerId = $this->app->players()->create($player + ['team_id' => $teamId]);
            $this->app->matches()->addToLineup((int) $match['id'], $teamId, $playerId);
            $count++;
        }
        $this->flash($count > 0 ? 'success' : 'warning', $count > 0 ? 'players.bulk_created' : 'players.bulk_empty', ['count' => $count]);

        return $this->redirect('/admin/matches/' . $match['id'] . '#team-' . $teamId);
    }

    public function open(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $back = $request->input('back') === 'home' ? '/' : '/admin/matches/' . $match['id'];

        if (!MatchStatus::canOpen((string) $match['status'])) {
            return $this->redirect($back);
        }
        if ($this->otherOpen((int) $match['id']) !== null) {
            $this->flash('error', 'matches.other_open');

            return $this->redirect($back);
        }
        $players = count($this->app->matches()->lineupIds((int) $match['id'], (int) $match['home_team_id']))
            + count($this->app->matches()->lineupIds((int) $match['id'], (int) $match['away_team_id']));
        if ($players === 0) {
            $this->flash('error', 'matches.no_players');

            return $this->redirect('/admin/matches/' . $match['id']);
        }

        $minutes = $match['auto_close_minutes'] === null ? null : (int) $match['auto_close_minutes'];
        $this->app->matches()->update((int) $match['id'], [
            'status' => MatchStatus::OPEN,
            'opened_at' => Database::now(),
            'closes_at' => MatchStatus::closesAt($minutes, time()),
            'closed_at' => null,
        ]);
        $this->flash('success', 'matches.opened');

        return $this->redirect($back);
    }

    public function close(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        if (MatchStatus::canClose((string) $match['status'])) {
            $this->app->matches()->update((int) $match['id'], [
                'status' => MatchStatus::CLOSED,
                'closed_at' => Database::now(),
                'closes_at' => null,
            ]);
            $this->flash('success', 'matches.closed');
        }

        return $this->redirect($request->input('back') === 'results' ? '/admin/matches/' . $match['id'] . '/results' : '/admin/matches/' . $match['id']);
    }

    public function visibility(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $public = $request->input('public') === '1';
        $this->app->matches()->update((int) $match['id'], ['results_public' => $public ? 1 : 0]);
        $this->flash('success', $public ? 'matches.results_shared' : 'matches.results_hidden');

        return $this->redirect($request->input('back') === 'results' ? '/admin/matches/' . $match['id'] . '/results' : '/admin/matches/' . $match['id']);
    }

    public function reset(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $this->app->matches()->deleteVotes((int) $match['id']);
        $this->flash('success', 'matches.votes_deleted');

        return $this->redirect('/admin/matches/' . $match['id']);
    }

    public function delete(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));
        $this->app->matches()->delete((int) $match['id']);
        $this->flash('success', 'matches.deleted');

        return $this->redirect('/admin/matches');
    }

    public function results(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));

        return $this->render('admin/matches/results.html.twig', [
            'match' => $match,
            'results' => $this->app->results()->forMatch($match),
            'live_url' => '/admin/matches/' . $match['id'] . '/results/live',
        ]);
    }

    public function resultsLive(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));

        return $this->render('partials/results.html.twig', [
            'match' => $match,
            'results' => $this->app->results()->forMatch($match),
            'present' => $request->input('present') === '1',
        ]);
    }

    /** Full-screen view for a projector or the stadium screen. */
    public function present(Request $request, array $params): Response
    {
        $this->admin();
        $match = self::found($this->app->matches()->find(self::id($params)));

        return $this->render('admin/matches/present.html.twig', [
            'match' => $match,
            'results' => $this->app->results()->forMatch($match),
            'live_url' => '/admin/matches/' . $match['id'] . '/results/live?present=1',
            'present' => true,
        ]);
    }

    /** @return array<string, mixed>|null another match whose voting runs */
    private function otherOpen(int $matchId): ?array
    {
        $open = $this->app->matches()->open();

        return $open !== null && (int) $open['id'] !== $matchId ? $open : null;
    }

    /**
     * Players to start with: the members of the chosen template, or all active players.
     *
     * @return list<int>
     */
    private function startingLineup(int $teamId, string $squadId): array
    {
        $active = array_map(static fn (array $p) => (int) $p['id'], $this->app->players()->forTeam($teamId));
        if ($squadId === '' || !ctype_digit($squadId)) {
            return $active;
        }
        $squad = $this->app->squads()->find((int) $squadId);
        if ($squad === null || (int) $squad['team_id'] !== $teamId) {
            return $active;
        }

        return array_values(array_intersect($this->app->squads()->playerIds((int) $squad['id']), $active));
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function input(Request $request, bool $creating): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $request->input($field);
        }
        $errors = [];
        if ($creating) {
            foreach (['home_team_id', 'away_team_id'] as $field) {
                $team = ctype_digit($values[$field]) ? $this->app->teams()->find((int) $values[$field]) : null;
                if ($team === null) {
                    $errors[$field] = 'matches.team_required';
                }
            }
            if (!isset($errors['home_team_id']) && $values['home_team_id'] === $values['away_team_id']) {
                $errors['away_team_id'] = 'matches.same_team';
            }
        }
        if (mb_strlen($values['competition']) > 100) {
            $errors['competition'] = 'validation.too_long';
        }
        if (mb_strlen($values['venue']) > 100) {
            $errors['venue'] = 'validation.too_long';
        }
        if ($e = Validation::optionalDateTime($values['kickoff_at'])) {
            $errors['kickoff_at'] = $e;
        }
        if ($e = Validation::optionalInt($values['auto_close_minutes'], 1, 600)) {
            $errors['auto_close_minutes'] = $e;
        }

        return [$values, $errors];
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private static function matchData(array $values): array
    {
        return [
            'competition' => $values['competition'] !== '' ? $values['competition'] : null,
            'venue' => $values['venue'] !== '' ? $values['venue'] : null,
            'kickoff_at' => $values['kickoff_at'] !== '' ? str_replace('T', ' ', $values['kickoff_at']) . ':00' : null,
            'auto_close_minutes' => $values['auto_close_minutes'] !== '' ? (int) $values['auto_close_minutes'] : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $match
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(?array $match, array $values, array $errors, int $status = 200): Response
    {
        $teams = $this->app->teams()->all();
        $squads = [];
        foreach ($teams as $team) {
            foreach ($this->app->squads()->forTeam((int) $team['id']) as $squad) {
                $squads[] = $squad + ['team_name' => $team['name']];
            }
        }

        return $this->render('admin/matches/form.html.twig', [
            'match' => $match,
            'teams' => $teams,
            'squads' => $squads,
            'values' => $values,
            'errors' => $errors,
            'competitions' => $this->competitions(),
        ], $status);
    }

    /** @return list<string> competitions used before, for the input suggestions */
    private function competitions(): array
    {
        return array_map('strval', array_column($this->app->db()->all(
            'SELECT DISTINCT competition FROM matches WHERE competition IS NOT NULL ORDER BY competition LIMIT 30',
        ), 'competition'));
    }
}
