<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RetailerStatus;
use App\Models\Retailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResumeExpiredPausedRetailersCommand extends Command
{
    protected $signature = 'retailers:resume-expired';

    protected $description = 'Auto-resume retailers whose pause period has expired';

    public function handle(): int
    {
        // Find retailers with Paused status whose pause has expired
        $retailers = Retailer::query()
            ->where('status', RetailerStatus::Paused)
            ->where('paused_until', '<=', now())
            ->get();

        if ($retailers->isEmpty()) {
            $this->info('No expired paused retailers found.');

            return self::SUCCESS;
        }

        $this->info("Found {$retailers->count()} expired paused retailer(s) to resume...");

        $resumed = 0;
        $failed = 0;

        foreach ($retailers as $retailer) {
            try {
                // Transition from Paused to Active
                if ($retailer->status->canTransitionTo(RetailerStatus::Active)) {
                    $retailer->update([
                        'status' => RetailerStatus::Active,
                        'paused_until' => null,
                    ]);

                    $this->line("✓ Resumed: {$retailer->name}");
                    $resumed++;

                    Log::info('Auto-resumed paused retailer', [
                        'retailer' => $retailer->slug,
                        'retailer_id' => $retailer->id,
                        'was_paused_until' => $retailer->paused_until?->toIso8601String(),
                    ]);
                } else {
                    $this->warn("✗ Cannot resume {$retailer->name}: Invalid transition from {$retailer->status->value}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("✗ Failed to resume {$retailer->name}: {$e->getMessage()}");
                $failed++;

                Log::error('Failed to auto-resume paused retailer', [
                    'retailer' => $retailer->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("Resumed: {$resumed} | Failed: {$failed}");

        return self::SUCCESS;
    }
}
