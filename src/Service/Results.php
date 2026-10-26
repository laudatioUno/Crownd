<?php

declare(strict_types=1);

namespace Crownd\Service;

use Crownd\Database\Database;
use Crownd\Repository\MatchRepository;

/**
 * Result of a match: per team the players ranked by votes, with the MVP
 * (or several on a tie) at the top.
 */
final class Results
{
    public function __construct(private readonly Database $db, private readonly MatchRepository $matches)
    {
    }

    /**
     * @param array<string, mixed> $match
     * @return array{ballots: int, teams: list<array<string, mixed>>}
     */
    public function forMatch(array $match): array
    {
        $matchId = (int) $match['id'];
        $counts = [];
        foreach ($this->db->all(
            'SELECT team_id, player_id, COUNT(*) AS n FROM crownd_votes WHERE match_id = ? GROUP BY team_id, player_id',
            [$matchId],
        ) as $row) {
            $counts[(int) $row['team_id']][(int) $row['player_id']] = (int) $row['n'];
        }

        $teams = [];
        foreach (['home', 'away'] as $side) {
            $teamId = (int) $match[$side . '_team_id'];
            $teams[] = [
                'side' => $side,
                'team_id' => $teamId,
                'name' => (string) $match[$side . '_name'],
                'short' => (string) ($match[$side . '_short'] ?? ''),
                'logo' => (string) ($match[$side . '_logo'] ?? ''),
                'color' => (string) ($match[$side . '_color'] ?? ''),
            ] + self::rank($this->matches->lineup($matchId, $teamId), $counts[$teamId] ?? []);
        }

        return ['ballots' => (int) ($match['ballot_count'] ?? 0), 'teams' => $teams];
    }

    /**
     * Ranks a line-up by votes. Pure function, so it can be tested without a database.
     *
     * @param list<array<string, mixed>> $lineup players (id, name, number, photo_file …)
     * @param array<int, int> $counts player id => votes
     * @return array{votes: int, players: list<array<string, mixed>>, winners: list<array<string, mixed>>, tie: bool}
     */
    public static function rank(array $lineup, array $counts): array
    {
        $total = array_sum($counts);
        $players = [];
        foreach ($lineup as $index => $player) {
            $votes = $counts[(int) $player['id']] ?? 0;
            $players[] = $player + [
                'votes' => $votes,
                'percent' => $total > 0 ? round($votes * 100 / $total, 1) : 0.0,
                'order' => $index,
            ];
        }
        usort($players, static fn (array $a, array $b) => [$b['votes'], $a['order']] <=> [$a['votes'], $b['order']]);

        $max = $players === [] ? 0 : $players[0]['votes'];
        $rank = 0;
        $previous = null;
        foreach ($players as $i => &$player) {
            if ($player['votes'] !== $previous) {
                $rank = $i + 1;
                $previous = $player['votes'];
            }
            $player['rank'] = $rank;
            $player['is_winner'] = $max > 0 && $player['votes'] === $max;
            // Bar length relative to the leader, so the leader always fills the bar.
            $player['bar'] = $max > 0 ? round($player['votes'] * 100 / $max, 1) : 0.0;
        }
        unset($player);

        $winners = array_values(array_filter($players, static fn (array $p) => $p['is_winner']));

        return [
            'votes' => $total,
            'players' => $players,
            'winners' => $winners,
            'tie' => count($winners) > 1,
        ];
    }
}
