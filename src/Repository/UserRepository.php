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

    /**
     * Finds an existing user by email or auto-provisions a new VKU Allocator account for Google OAuth sign-ins.
     *
     * @return array{user: array<string, mixed>, created: bool}
     */
    public function findOrCreateOAuthUser(string $email, string $fullName, ?string $adminEmailsCsv = null): array
    {
        $cleanEmail = strtolower(trim($email));
        $existing = $this->findByIdentifier($cleanEmail);
        if ($existing !== null) {
            return ['user' => $existing, 'created' => false];
        }

        $adminCsv = $adminEmailsCsv ?? (string) ($_ENV['GOOGLE_ADMIN_EMAILS'] ?? getenv('GOOGLE_ADMIN_EMAILS') ?: '');
        $adminList = array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', $adminCsv)));
        $isAdmin = in_array($cleanEmail, $adminList, true);

        $role = $isAdmin ? 'ADMIN' : 'STUDENT';
        $initialCredits = $isAdmin ? 9999.00 : 240.00;
        $studentCode = $this->deriveUniqueStudentCode($cleanEmail);
        $cleanName = trim($fullName) !== '' ? substr(trim($fullName), 0, 100) : $studentCode;
        $randomHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (student_code, full_name, email, password_hash, credit_balance, role)
             VALUES (:code, :name, :email, :hash, :credits, :role)'
        );
        $stmt->execute([
            ':code' => $studentCode,
            ':name' => $cleanName,
            ':email' => $cleanEmail,
            ':hash' => $randomHash,
            ':credits' => number_format($initialCredits, 2, '.', ''),
            ':role' => $role,
        ]);

        $newId = (int) $this->pdo->lastInsertId();
        $createdUser = $this->findById($newId);

        return [
            'user' => $createdUser ?? [
                'id' => $newId,
                'student_code' => $studentCode,
                'full_name' => $cleanName,
                'email' => $cleanEmail,
                'credit_balance' => number_format($initialCredits, 2, '.', ''),
                'ssh_public_key' => null,
                'role' => $role,
            ],
            'created' => true,
        ];
    }

    private function deriveUniqueStudentCode(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? 'USER';

        // Check if local part already contains a VKU student code like 22it001
        if (preg_match('/([0-9]{2}[a-zA-Z]{2,4}[0-9]{3,4})/', $localPart, $m)) {
            $candidate = strtoupper(substr($m[1], 0, 20));
            if ($this->findByIdentifier($candidate) === null) {
                return $candidate;
            }
        }

        $sanitized = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $localPart) ?: 'VKU');
        $base = substr($sanitized, 0, 12);
        if (strlen($base) < 3) {
            $base = 'VKU' . $base;
        }

        if ($this->findByIdentifier($base) === null) {
            return $base;
        }

        for ($i = 0; $i < 10; $i++) {
            $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            $candidate = substr($base, 0, 15) . '_' . $suffix;
            if ($this->findByIdentifier($candidate) === null) {
                return $candidate;
            }
        }

        return 'G_' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
    }
}
