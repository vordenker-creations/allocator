<?php

declare(strict_types=1);

namespace VKU\Allocator\Service;

use VKU\Allocator\Domain\Enum\UserRole;
use VKU\Allocator\Repository\UserRepository;

final readonly class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
    ) {}

    /**
     * Attempts login by student code or email.
     *
     * @return array{ok: bool, error?: string, user?: array<string, mixed>}
     */
    public function login(string $identifier, string $password): array
    {
        $user = $this->userRepository->findByIdentifier($identifier);
        if ($user === null) {
            return ['ok' => false, 'error' => 'Invalid student code/email or password.'];
        }

        $storedHash = (string) $user['password_hash'];
        $verified = password_verify($password, $storedHash);

        // Fallback for initial demo seed hash before first Argon2id rehash in container
        if (!$verified && $password === 'vku2026' && str_contains($storedHash, 'VktVQWxsb2NhdG9yU2FsdA')) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $this->userRepository->updatePasswordHash((int) $user['id'], $newHash);
            $verified = true;
        }

        if (!$verified) {
            return ['ok' => false, 'error' => 'Invalid student code/email or password.'];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['auth_user_id'] = (int) $user['id'];
        return ['ok' => true, 'user' => $user];
    }

    public function logout(): void
    {
        unset($_SESSION['auth_user_id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $userId = $_SESSION['auth_user_id'] ?? null;
        if (!is_int($userId) && !is_numeric($userId)) {
            return null;
        }

        return $this->userRepository->findById((int) $userId);
    }

    public function currentUserRole(): ?UserRole
    {
        $user = $this->currentUser();
        if ($user === null) {
            return null;
        }
        return UserRole::tryFrom((string) $user['role']);
    }
}
