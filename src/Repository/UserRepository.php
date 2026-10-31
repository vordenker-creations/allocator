<?php

declare(strict_types=1);

namespace VKU\Allocator\Repository;

use PDO;

final readonly class UserRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $trimmed = trim($identifier);
        $stmt = $this->pdo->prepare(
            'SELECT id, student_code, full_name, email, password_hash, credit_balance, ssh_public_key, role, created_at
             FROM users
             WHERE email = :email OR student_code = :code
             LIMIT 1'
        );
        $stmt->execute([
            ':email' => $trimmed,
            ':code' => strtoupper($trimmed),
        ]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, student_code, full_name, email, password_hash, credit_balance, ssh_public_key, role, created_at
             FROM users
             WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Locks user row FOR UPDATE inside a transaction to prevent concurrent credit balance race conditions.
     *
     * @return array<string, mixed>|null
     */
    public function lockUserForUpdate(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, student_code, full_name, email, credit_balance, ssh_public_key, role
             FROM users
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function updateSshKey(int $userId, string $sshPublicKey): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET ssh_public_key = :ssh_key WHERE id = :id'
        );
        $stmt->execute([
            ':ssh_key' => trim($sshPublicKey),
            ':id' => $userId,
        ]);
    }

    public function adjustCredits(int $userId, float $delta): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET credit_balance = credit_balance + :delta
             WHERE id = :id'
        );
        $stmt->execute([
            ':delta' => number_format($delta, 2, '.', ''),
            ':id' => $userId,
        ]);
    }

    public function updatePasswordHash(int $userId, string $newHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([
            ':hash' => $newHash,
            ':id' => $userId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, student_code, full_name, email, credit_balance, ssh_public_key, role, created_at
             FROM users
             ORDER BY role DESC, student_code ASC'
        );
        return $stmt->fetchAll();
    }
}
