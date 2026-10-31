<?php

declare(strict_types=1);

namespace VKU\Allocator\Domain\Enum;

enum NodeStatus: string
{
    case Online = 'ONLINE';
    case Maintenance = 'MAINTENANCE';
    case Offline = 'OFFLINE';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'ONLINE',
            self::Maintenance => 'MAINTENANCE',
            self::Offline => 'OFFLINE',
        };
    }

    public function isAllocatable(): bool
    {
        return $this === self::Online;
    }
}
