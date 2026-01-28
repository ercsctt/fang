<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceTrendIndicator: string
{
    case Rising = 'rising';
    case Falling = 'falling';
    case Stable = 'stable';
    case Volatile = 'volatile';

    public function label(): string
    {
        return match ($this) {
            self::Rising => 'Rising',
            self::Falling => 'Falling',
            self::Stable => 'Stable',
            self::Volatile => 'Volatile',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Rising => '↑',
            self::Falling => '↓',
            self::Stable => '→',
            self::Volatile => '↕',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Rising => 'red',
            self::Falling => 'green',
            self::Stable => 'gray',
            self::Volatile => 'yellow',
        };
    }
}
