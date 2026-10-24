<?php

declare(strict_types=1);

namespace Crownd\Repository;

use Crownd\Database\Database;

final class TeamRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM teams WHERE id = ?', [$id]);
    }

    /**
     * Teams with the number of active players and templates.
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $withArchived = false): array
    {
        return $this->db->all(
            'SELECT t.*,
                (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id AND p.archived = 0) AS player_count,
                (SELECT COUNT(*) FROM squads s WHERE s.team_id = t.id) AS squad_count
             FROM teams t' . ($withArchived ? '' : ' WHERE t.archived = 0') . '
             ORDER BY t.archived, t.name',
        );
    }

    /** @return list<array{id: int, color: string}> teams with an own color, for /theme.css */
    public function colors(): array
    {
        return $this->db->all("SELECT id, color FROM teams WHERE color IS NOT NULL AND color <> '' ORDER BY id");
    }

    public function colorFingerprint(): string
    {
        return (string) $this->db->value("SELECT COALESCE(GROUP_CONCAT(CONCAT(id, color) ORDER BY id SEPARATOR ''), '') FROM teams WHERE color IS NOT NULL");
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Database::now();

        return $this->db->insert('teams', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('teams', $data + ['updated_at' => Database::now()], ['id' => $id]);
    }

    /** A team that was used in a match can only be archived, not deleted. */
    public function isUsed(int $id): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM matches WHERE home_team_id = ? OR away_team_id = ? LIMIT 1',
            [$id, $id],
        );
    }

    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id): void {
            $db->run('DELETE FROM squads WHERE team_id = ?', [$id]);
            $db->run('DELETE FROM players WHERE team_id = ?', [$id]);
            $db->run('DELETE FROM teams WHERE id = ?', [$id]);
        });
    }
}
