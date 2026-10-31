<?php

declare(strict_types=1);

namespace VKU\Allocator\Domain\Enum;

enum UserRole: string
{
    case Student = 'STUDENT';
    case Researcher = 'RESEARCHER';
    case Admin = 'ADMIN';

    /**
     * Role-based anti-hoarding limit: maximum nodes staged/leased in a single job.
     */
    public function maxNodesPerJob(): int
    {
        return match ($this) {
            self::Student => 1,
            self::Researcher, self::Admin => 3,
        };
    }

    /**
     * Role-based anti-hoarding limit: maximum concurrent SCHEDULED/ACTIVE leases across the system.
     * Null means no global cap beyond credit balance and per-job limits.
     */
    public function maxConcurrentActiveLeases(): ?int
    {
        return match ($this) {
            self::Student => 1,
            self::Researcher, self::Admin => null,
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
