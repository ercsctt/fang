<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\RetailerStatus;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RetailerStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Retailer $retailer,
        public RetailerStatus $oldStatus,
        public RetailerStatus $newStatus,
        public ?string $reason = null,
        public ?User $triggeredBy = null
    ) {}
}
