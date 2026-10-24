<?php

declare(strict_types=1);

namespace Crownd\Repository;

use Crownd\Database\Database;

final class AdminUserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM admin_users WHERE username = ?', [strtolower(trim($username))]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM admin_users ORDER BY active DESC, display_name');
    }

    public function countActive(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM admin_users WHERE active = 1');
    }

    /** @param array{username: string, display_name: string, password_hash: string} $data */
    public function create(array $data): int
    {
        return $this->db->insert('admin_users', $data + ['active' => 1, 'created_at' => Database::now()]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('admin_users', $data, ['id' => $id]);
    }
}
