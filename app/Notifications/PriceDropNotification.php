<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage as SlackBlockMessage;

class PriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $productListingId,
        public readonly string $productTitle,
        public readonly string $retailerName,
        public readonly string $productUrl,
        public readonly int $oldPricePence,
        public readonly int $newPricePence,
        public readonly float $dropPercentage,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channel = config('services.price_alerts.notification_channel', 'log');

        return match ($channel) {
            'mail' => ['mail'],
            'slack' => ['slack'],
            'all' => ['mail', 'slack'],
            default => [],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $oldPrice = $this->formatPrice($this->oldPricePence);
        $newPrice = $this->formatPrice($this->newPricePence);
        $savings = $this->formatPrice($this->oldPricePence - $this->newPricePence);

        return (new MailMessage)
            ->subject("Price Drop Alert: {$this->productTitle}")
            ->greeting('Price Drop Detected!')
            ->line("A significant price drop has been detected for a product at {$this->retailerName}.")
            ->line("**Product:** {$this->productTitle}")
            ->line("**Old Price:** {$oldPrice}")
            ->line("**New Price:** {$newPrice}")
            ->line("**Savings:** {$savings} ({$this->dropPercentage}% off)")
            ->action('View Product', $this->productUrl)
            ->line('This alert was triggered because the price dropped by more than '.config('services.price_alerts.threshold_percent').'%.');
    }

    public function toSlack(object $notifiable): SlackBlockMessage
    {
        $oldPrice = $this->formatPrice($this->oldPricePence);
        $newPrice = $this->formatPrice($this->newPricePence);
        $savings = $this->formatPrice($this->oldPricePence - $this->newPricePence);

        return (new SlackBlockMessage)
            ->text("Price drop detected for {$this->productTitle}")
            ->headerBlock('Price Drop Alert')
            ->contextBlock(function (ContextBlock $block) {
                $block->text("{$this->retailerName}");
            })
            ->sectionBlock(function (SectionBlock $block) use ($oldPrice, $newPrice) {
                $block->text($this->productTitle);
                $block->field("*Old Price:*\n{$oldPrice}")->markdown();
                $block->field("*New Price:*\n{$newPrice}")->markdown();
            })
            ->dividerBlock()
            ->sectionBlock(function (SectionBlock $block) use ($savings) {
                $block->text("Save {$savings} ({$this->dropPercentage}% off)");
            });
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_listing_id' => $this->productListingId,
            'product_title' => $this->productTitle,
            'retailer_name' => $this->retailerName,
            'product_url' => $this->productUrl,
            'old_price_pence' => $this->oldPricePence,
            'new_price_pence' => $this->newPricePence,
            'drop_percentage' => $this->dropPercentage,
        ];
    }

    private function formatPrice(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }
}
