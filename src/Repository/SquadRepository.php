<?php

declare(strict_types=1);

namespace Crownd\Repository;

use Crownd\Database\Database;

/** Squad templates ("Vorlagen"): a named selection of a team's players, e.g. "NLA". */
final class SquadRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM squads WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function forTeam(int $teamId): array
    {
        return $this->db->all(
            'SELECT s.*, (SELECT COUNT(*) FROM squad_players sp JOIN players p ON p.id = sp.player_id
                          WHERE sp.squad_id = s.id AND p.archived = 0) AS player_count
             FROM squads s WHERE s.team_id = ? ORDER BY s.name',
            [$teamId],
        );
    }

    /** @return list<int> */
    public function playerIds(int $squadId): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT player_id FROM squad_players WHERE squad_id = ?', [$squadId]),
            'player_id',
        ));
    }

    public function create(int $teamId, string $name): int
    {
        $now = Database::now();

        return $this->db->insert('squads', ['team_id' => $teamId, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function rename(int $id, string $name): void
    {
        $this->db->update('squads', ['name' => $name, 'updated_at' => Database::now()], ['id' => $id]);
    }

    /**
     * Replaces the members. Only players of the squad's own team are kept.
     *
     * @param list<int> $playerIds
     */
    public function setPlayers(int $squadId, int $teamId, array $playerIds): void
    {
        $this->db->transaction(function (Database $db) use ($squadId, $teamId, $playerIds): void {
            $db->run('DELETE FROM squad_players WHERE squad_id = ?', [$squadId]);
            foreach (array_unique($playerIds) as $playerId) {
                $db->run(
                    'INSERT INTO squad_players (squad_id, player_id)
                     SELECT ?, id FROM players WHERE id = ? AND team_id = ?',
                    [$squadId, $playerId, $teamId],
                );
            }
        });
    }

    public function addPlayer(int $squadId, int $playerId): void
    {
        $this->db->run('INSERT IGNORE INTO squad_players (squad_id, player_id) VALUES (?, ?)', [$squadId, $playerId]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM squads WHERE id = ?', [$id]);
    }
}
