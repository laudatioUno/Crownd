<?php

declare(strict_types=1);

namespace Crownd\Repository;

use Crownd\Database\Database;

final class PlayerRepository
{
    private const ORDER = 'p.number IS NULL, p.number, p.name';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT p.* FROM crownd_players p WHERE p.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function forTeam(int $teamId, bool $withArchived = false): array
    {
        return $this->db->all(
            'SELECT p.* FROM crownd_players p WHERE p.team_id = ?' . ($withArchived ? '' : ' AND p.archived = 0')
            . ' ORDER BY p.archived, ' . self::ORDER,
            [$teamId],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = Database::now();

        return $this->db->insert('crownd_players', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('crownd_players', $data + ['updated_at' => Database::now()], ['id' => $id]);
    }

    /** A player who was in a line-up can only be archived, so results stay complete. */
    public function isUsed(int $id): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM crownd_match_players WHERE player_id = ? LIMIT 1', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM crownd_players WHERE id = ?', [$id]);
    }

    /**
     * Parses a pasted list, one player per line: "12 Nico Muster", "#12 Nico Muster",
     * "Nico Muster, 12" or just "Nico Muster".
     *
     * @return list<array{name: string, number: int|null}>
     */
    public static function parseList(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '') {
                continue;
            }
            $number = null;
            if (preg_match('/^#?(\d{1,3})[\s.:)\-]+(.+)$/u', $line, $m)) {
                $number = (int) $m[1];
                $line = $m[2];
            } elseif (preg_match('/^(.+?)[\s,;]+#?(\d{1,3})$/u', $line, $m)) {
                $number = (int) $m[2];
                $line = $m[1];
            }
            $name = trim($line, " \t,;-");
            if ($name !== '') {
                $out[] = ['name' => mb_substr($name, 0, 100), 'number' => $number];
            }
        }

        return $out;
    }
}
