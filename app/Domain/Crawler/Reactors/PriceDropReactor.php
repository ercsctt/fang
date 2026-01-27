<?php

declare(strict_types=1);

namespace App\Domain\Crawler\Reactors;

use App\Domain\Crawler\Events\PriceDropped;
use App\Models\PriceAlert;
use App\Models\ProductListing;
use App\Notifications\PriceAlertNotification;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class PriceDropReactor extends Reactor
{
    public function onPriceDropped(PriceDropped $event): void
    {
        $this->handleThresholdNotification($event);
        $this->notifyUserAlerts($event);
    }

    private function handleThresholdNotification(PriceDropped $event): void
    {
        $thresholdPercent = config('services.price_alerts.threshold_percent', 20);

        if ($event->dropPercentage < $thresholdPercent) {
            Log::debug('Price drop below threshold, skipping notification', [
                'product_listing_id' => $event->productListingId,
                'drop_percentage' => $event->dropPercentage,
                'threshold' => $thresholdPercent,
            ]);

            return;
        }

        Log::info('Significant price drop detected, sending notification', [
            'product_listing_id' => $event->productListingId,
            'product_title' => $event->productTitle,
            'retailer' => $event->retailerName,
            'old_price_pence' => $event->oldPricePence,
            'new_price_pence' => $event->newPricePence,
            'drop_percentage' => $event->dropPercentage,
            'threshold' => $thresholdPercent,
        ]);

        $this->sendNotification($event);
    }

    private function notifyUserAlerts(PriceDropped $event): void
    {
        $listing = ProductListing::query()
            ->with('products')
            ->find($event->productListingId);

        if ($listing === null) {
            return;
        }

        $productIds = $listing->products->pluck('id');

        if ($productIds->isEmpty()) {
            return;
        }

        $cooldownHours = config('services.price_alerts.user_cooldown_hours', 24);

        $alerts = PriceAlert::query()
            ->with('user')
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where('target_price_pence', '>=', $event->newPricePence)
            ->where(function ($query) use ($cooldownHours) {
                $query->whereNull('last_notified_at')
                    ->orWhere('last_notified_at', '<', now()->subHours($cooldownHours));
            })
            ->get();

        foreach ($alerts as $alert) {
            $this->sendUserAlert($alert, $event);
        }
    }

    private function sendUserAlert(PriceAlert $alert, PriceDropped $event): void
    {
        $notification = new PriceAlertNotification(
            priceAlert: $alert,
            productName: $event->productTitle,
            productUrl: $event->productUrl,
            currentPricePence: $event->newPricePence,
            retailerName: $event->retailerName,
        );

        $alert->user->notify($notification);

        $alert->update(['last_notified_at' => now()]);

        Log::info('User price alert notification sent', [
            'user_id' => $alert->user_id,
            'product_id' => $alert->product_id,
            'target_price_pence' => $alert->target_price_pence,
            'current_price_pence' => $event->newPricePence,
        ]);
    }

    private function sendNotification(PriceDropped $event): void
    {
        $notification = new PriceDropNotification(
            productListingId: $event->productListingId,
            productTitle: $event->productTitle,
            retailerName: $event->retailerName,
            productUrl: $event->productUrl,
            oldPricePence: $event->oldPricePence,
            newPricePence: $event->newPricePence,
            dropPercentage: $event->dropPercentage,
        );

        $channel = config('services.price_alerts.notification_channel', 'log');

        if ($channel === 'log') {
            Log::channel('single')->info('PRICE DROP ALERT', [
                'product_title' => $event->productTitle,
                'retailer' => $event->retailerName,
                'url' => $event->productUrl,
                'old_price' => '£'.number_format($event->oldPricePence / 100, 2),
                'new_price' => '£'.number_format($event->newPricePence / 100, 2),
                'savings' => '£'.number_format(($event->oldPricePence - $event->newPricePence) / 100, 2),
                'drop_percentage' => $event->dropPercentage.'%',
            ]);

            return;
        }

        Notification::route('mail', config('mail.from.address'))
            ->route('slack', config('services.slack.notifications.channel'))
            ->notify($notification);
    }

    /**
     * Calculate the percentage drop between two prices.
     */
    public static function calculateDropPercentage(int $oldPricePence, int $newPricePence): float
    {
        if ($oldPricePence <= 0) {
            return 0.0;
        }

        $drop = $oldPricePence - $newPricePence;

        if ($drop <= 0) {
            return 0.0;
        }

        return round(($drop / $oldPricePence) * 100, 2);
    }
}
