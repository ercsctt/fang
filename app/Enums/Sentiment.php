<?php

declare(strict_types=1);

namespace App\Enums;

enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::Positive => 'Positive',
            self::Neutral => 'Neutral',
            self::Negative => 'Negative',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Positive => '👍',
            self::Neutral => '😐',
            self::Negative => '👎',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Positive => 'green',
            self::Neutral => 'gray',
            self::Negative => 'red',
        };
    }

    /**
     * Get score range for this sentiment.
     *
     * @return array{min: float, max: float}
     */
    public function scoreRange(): array
    {
        return match ($this) {
            self::Positive => ['min' => 0.3, 'max' => 1.0],
            self::Neutral => ['min' => -0.3, 'max' => 0.3],
            self::Negative => ['min' => -1.0, 'max' => -0.3],
        };
    }

    /**
     * Determine sentiment from a score (-1.0 to 1.0).
     */
    public static function fromScore(float $score): self
    {
        if ($score >= 0.3) {
            return self::Positive;
        }

        if ($score <= -0.3) {
            return self::Negative;
        }

        return self::Neutral;
    }
}
