<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PriceAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PriceAlert $priceAlert,
        public readonly string $productName,
        public readonly string $productUrl,
        public readonly int $currentPricePence,
        public readonly string $retailerName,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $currentPrice = $this->formatPrice($this->currentPricePence);
        $targetPrice = $this->formatPrice($this->priceAlert->target_price_pence);
        $savings = $this->formatPrice($this->priceAlert->target_price_pence - $this->currentPricePence);

        return (new MailMessage)
            ->subject("Price Alert: {$this->productName} is now {$currentPrice}")
            ->greeting('Good news!')
            ->line("The price for **{$this->productName}** at {$this->retailerName} has dropped below your target price.")
            ->line("**Current Price:** {$currentPrice}")
            ->line("**Your Target Price:** {$targetPrice}")
            ->line("**You're saving:** {$savings}")
            ->action('View Product', $this->productUrl)
            ->line('Happy shopping!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'price_alert_id' => $this->priceAlert->id,
            'product_id' => $this->priceAlert->product_id,
            'product_name' => $this->productName,
            'product_url' => $this->productUrl,
            'current_price_pence' => $this->currentPricePence,
            'target_price_pence' => $this->priceAlert->target_price_pence,
            'retailer_name' => $this->retailerName,
        ];
    }

    private function formatPrice(int $pence): string
    {
        return '£'.number_format($pence / 100, 2);
    }
}
