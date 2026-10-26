<?php

declare(strict_types=1);

namespace Crownd\Repository;

use Crownd\Database\Database;
use Crownd\Domain\MatchStatus;

/**
 * Matches (one poll each) and their line-ups.
 *
 * A match with an automatic end is closed lazily: whenever it is read after
 * closes_at, it is switched to "closed" (no cron job needed).
 */
final class MatchRepository
{
    private const SELECT = 'SELECT m.*,
            h.name AS home_name, h.short_name AS home_short, h.logo_file AS home_logo, h.color AS home_color,
            a.name AS away_name, a.short_name AS away_short, a.logo_file AS away_logo, a.color AS away_color,
            (SELECT COUNT(*) FROM crownd_ballots b WHERE b.match_id = m.id) AS ballot_count
        FROM crownd_matches m
        JOIN crownd_teams h ON h.id = m.home_team_id
        JOIN crownd_teams a ON a.id = m.away_team_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $this->closeExpired();

        return $this->db->one(self::SELECT . ' WHERE m.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> newest first; upcoming drafts on top */
    public function all(): array
    {
        $this->closeExpired();

        return $this->db->all(self::SELECT . " ORDER BY m.status = 'open' DESC, m.kickoff_at IS NULL, m.kickoff_at DESC, m.id DESC");
    }

    /** The match whose voting is running right now, if any. */
    public function open(): ?array
    {
        $this->closeExpired();

        return $this->db->one(self::SELECT . " WHERE m.status = 'open' ORDER BY m.opened_at DESC LIMIT 1");
    }

    /**
     * The match to show on the start page while no voting runs: the next prepared
     * match (today or later, or without date), otherwise the most recent one.
     */
    public function upcoming(): ?array
    {
        $this->closeExpired();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');

        return $this->db->one(
            self::SELECT . " WHERE m.status = 'draft' AND (m.kickoff_at IS NULL OR m.kickoff_at >= ?)
             ORDER BY m.kickoff_at IS NULL, m.kickoff_at, m.id LIMIT 1",
            [$today],
        );
    }

    /** The most recently closed match. */
    public function lastClosed(): ?array
    {
        $this->closeExpired();

        return $this->db->one(self::SELECT . " WHERE m.status = 'closed' ORDER BY m.closed_at DESC, m.id DESC LIMIT 1");
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Database::now();

        return $this->db->insert('crownd_matches', $data + ['status' => MatchStatus::DRAFT, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('crownd_matches', $data + ['updated_at' => Database::now()], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id): void {
            $db->run('DELETE FROM crownd_votes WHERE match_id = ?', [$id]);
            $db->run('DELETE FROM crownd_ballots WHERE match_id = ?', [$id]);
            $db->run('DELETE FROM crownd_match_players WHERE match_id = ?', [$id]);
            $db->run('DELETE FROM crownd_matches WHERE id = ?', [$id]);
        });
    }

    /**
     * Line-up of one team in a match, sorted by shirt number.
     *
     * @return list<array<string, mixed>>
     */
    public function lineup(int $matchId, int $teamId): array
    {
        return $this->db->all(
            'SELECT p.* FROM crownd_match_players mp JOIN crownd_players p ON p.id = mp.player_id
             WHERE mp.match_id = ? AND mp.team_id = ?
             ORDER BY p.number IS NULL, p.number, p.name',
            [$matchId, $teamId],
        );
    }

    /** @return list<int> */
    public function lineupIds(int $matchId, int $teamId): array
    {
        return array_map('intval', array_column($this->lineup($matchId, $teamId), 'id'));
    }

    /**
     * Replaces a team's line-up. Only players of that team are accepted.
     * Players who already received votes stay in the line-up.
     *
     * @param list<int> $playerIds
     */
    public function setLineup(int $matchId, int $teamId, array $playerIds): void
    {
        $this->db->transaction(function (Database $db) use ($matchId, $teamId, $playerIds): void {
            $db->run(
                'DELETE mp FROM crownd_match_players mp
                 WHERE mp.match_id = ? AND mp.team_id = ?
                   AND NOT EXISTS (SELECT 1 FROM crownd_votes v WHERE v.match_id = mp.match_id AND v.player_id = mp.player_id)',
                [$matchId, $teamId],
            );
            foreach (array_unique($playerIds) as $playerId) {
                $db->run(
                    'INSERT IGNORE INTO crownd_match_players (match_id, team_id, player_id)
                     SELECT ?, team_id, id FROM crownd_players WHERE id = ? AND team_id = ?',
                    [$matchId, $playerId, $teamId],
                );
            }
        });
    }

    public function addToLineup(int $matchId, int $teamId, int $playerId): void
    {
        $this->db->run(
            'INSERT IGNORE INTO crownd_match_players (match_id, team_id, player_id) VALUES (?, ?, ?)',
            [$matchId, $teamId, $playerId],
        );
    }

    public function deleteVotes(int $matchId): void
    {
        $this->db->transaction(function (Database $db) use ($matchId): void {
            $db->run('DELETE FROM crownd_votes WHERE match_id = ?', [$matchId]);
            $db->run('DELETE FROM crownd_ballots WHERE match_id = ?', [$matchId]);
        });
    }

    /** Closes matches whose automatic end has passed. */
    public function closeExpired(): void
    {
        $this->db->run(
            "UPDATE crownd_matches SET status = 'closed', closed_at = closes_at, updated_at = ?
             WHERE status = 'open' AND closes_at IS NOT NULL AND closes_at <= ?",
            [Database::now(), Database::now()],
        );
    }
}
