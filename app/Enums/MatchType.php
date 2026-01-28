<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchType: string
{
    case Exact = 'exact';
    case Fuzzy = 'fuzzy';
    case Barcode = 'barcode';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact Match',
            self::Fuzzy => 'Fuzzy Match',
            self::Barcode => 'Barcode Match',
            self::Manual => 'Manual Match',
        };
    }
}
