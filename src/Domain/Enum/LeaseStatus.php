<?php

declare(strict_types=1);

namespace VKU\Allocator\Domain\Enum;

enum LeaseStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function isTerminable(): bool
    {
        return match ($this) {
            self::Scheduled, self::Active => true,
            self::Expired, self::Cancelled => false,
        };
    }
}
