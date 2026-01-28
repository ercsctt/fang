<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

/**
 * Unified state machine for retailer status.
 *
 * Replaces the previous is_active (bool), health_status (enum), and paused_until (datetime) fields
 * with a single status that represents the complete state of a retailer.
 *
 * State Transitions:
 * - Active: Can transition to Paused, Disabled, Degraded, Failed
 * - Paused: Can transition to Active, Disabled (auto-resumes to Active when pause expires)
 * - Disabled: Can transition to Active (manual re-enable only)
 * - Degraded: Can transition to Active, Paused, Disabled, Failed
 * - Failed: Can transition to Active, Disabled (needs intervention to resume)
 */
enum RetailerStatus: string
{
    /**
     * Retailer is available for crawling.
     * This is the normal operational state.
     */
    case Active = 'active';

    /**
     * Temporarily disabled, will auto-resume after pause expires.
     * Used for rate limiting, temporary maintenance, or cooldown periods.
     */
    case Paused = 'paused';

    /**
     * Manually disabled by an administrator.
     * Will not crawl until manually re-enabled.
     */
    case Disabled = 'disabled';

    /**
     * Experiencing issues but still attempting crawls.
     * Indicates degraded performance or partial failures.
     */
    case Degraded = 'degraded';

    /**
     * Too many failures, needs manual intervention.
     * Circuit breaker has tripped - crawling is stopped.
     */
    case Failed = 'failed';

    /**
     * Get a human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Disabled => 'Disabled',
            self::Degraded => 'Degraded',
            self::Failed => 'Failed',
        };
    }

    /**
     * Get a color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Paused => 'yellow',
            self::Disabled => 'gray',
            self::Degraded => 'orange',
            self::Failed => 'red',
        };
    }

    /**
     * Get a description of what this status means.
     */
    public function description(): string
    {
        return match ($this) {
            self::Active => 'Available for crawling',
            self::Paused => 'Temporarily disabled, will auto-resume',
            self::Disabled => 'Manually disabled by administrator',
            self::Degraded => 'Experiencing issues but still crawling',
            self::Failed => 'Too many failures, needs intervention',
        };
    }

    /**
     * Check if the retailer is available for crawling in this status.
     */
    public function isAvailableForCrawling(): bool
    {
        return match ($this) {
            self::Active, self::Degraded => true,
            self::Paused, self::Disabled, self::Failed => false,
        };
    }

    /**
     * Check if this status indicates the retailer is experiencing problems.
     */
    public function hasIssues(): bool
    {
        return match ($this) {
            self::Active => false,
            self::Paused, self::Disabled, self::Degraded, self::Failed => true,
        };
    }

    /**
     * Check if this status requires manual intervention to resume.
     */
    public function requiresIntervention(): bool
    {
        return match ($this) {
            self::Disabled, self::Failed => true,
            self::Active, self::Paused, self::Degraded => false,
        };
    }

    /**
     * Get all statuses that this status can transition to.
     *
     * @return array<RetailerStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [
                self::Paused,
                self::Disabled,
                self::Degraded,
                self::Failed,
            ],
            self::Paused => [
                self::Active,
                self::Disabled,
            ],
            self::Disabled => [
                self::Active,
            ],
            self::Degraded => [
                self::Active,
                self::Paused,
                self::Disabled,
                self::Failed,
            ],
            self::Failed => [
                self::Active,
                self::Disabled,
            ],
        };
    }

    /**
     * Check if a transition to the given status is allowed.
     */
    public function canTransitionTo(self $status): bool
    {
        if ($this === $status) {
            return true;
        }

        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Validate a transition to the given status.
     *
     * @throws InvalidArgumentException if the transition is not allowed
     */
    public function transitionTo(self $status): self
    {
        if (! $this->canTransitionTo($status)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid status transition from "%s" to "%s". Allowed transitions: %s',
                    $this->value,
                    $status->value,
                    implode(', ', array_map(fn (self $s) => $s->value, $this->allowedTransitions()))
                )
            );
        }

        return $status;
    }

    /**
     * Get the default status for a new retailer.
     */
    public static function default(): self
    {
        return self::Active;
    }

    /**
     * Get all statuses that allow crawling.
     *
     * @return array<RetailerStatus>
     */
    public static function crawlableStatuses(): array
    {
        return [
            self::Active,
            self::Degraded,
        ];
    }

    /**
     * Get all statuses that indicate problems.
     *
     * @return array<RetailerStatus>
     */
    public static function problemStatuses(): array
    {
        return [
            self::Paused,
            self::Degraded,
            self::Failed,
        ];
    }

    /**
     * Get the icon name for UI display.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Active => 'check-circle',
            self::Paused => 'pause-circle',
            self::Disabled => 'minus-circle',
            self::Degraded => 'exclamation-triangle',
            self::Failed => 'x-circle',
        };
    }

    /**
     * Get the badge/pill CSS classes for Tailwind styling.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            self::Paused => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
            self::Disabled => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
            self::Degraded => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
            self::Failed => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
        };
    }
}
