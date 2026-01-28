<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrawlFailureAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $retailerSlug,
        public readonly int $failureCount,
        public readonly string $lastUrl,
        public readonly string $lastErrorMessage,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (config('services.slack.notifications.webhook')) {
            $channels[] = 'slack';
        }

        if (config('mail.crawl_alerts_to')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Crawl Alert: {$this->retailerSlug} has {$this->failureCount} failures")
            ->error()
            ->greeting('Crawl Failure Alert')
            ->line("Retailer **{$this->retailerSlug}** has exceeded the failure threshold.")
            ->line("**Failures in last hour:** {$this->failureCount}")
            ->line("**Last Failed URL:** {$this->lastUrl}")
            ->line("**Last Error:** {$this->lastErrorMessage}")
            ->line('Please investigate immediately.')
            ->salutation('Fang Crawler Monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSlack(object $notifiable): array
    {
        return [
            'text' => "🚨 Crawl Failure Alert: {$this->retailerSlug}",
            'attachments' => [
                [
                    'title' => "Retailer: {$this->retailerSlug}",
                    'color' => 'danger',
                    'fields' => [
                        [
                            'title' => 'Failures (last hour)',
                            'value' => (string) $this->failureCount,
                            'short' => true,
                        ],
                        [
                            'title' => 'Last Failed URL',
                            'value' => $this->truncateUrl($this->lastUrl),
                            'short' => false,
                        ],
                        [
                            'title' => 'Last Error',
                            'value' => $this->truncateError($this->lastErrorMessage),
                            'short' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'retailer_slug' => $this->retailerSlug,
            'failure_count' => $this->failureCount,
            'last_url' => $this->lastUrl,
            'last_error_message' => $this->lastErrorMessage,
        ];
    }

    private function truncateUrl(string $url): string
    {
        return strlen($url) > 80 ? substr($url, 0, 77).'...' : $url;
    }

    private function truncateError(string $error): string
    {
        return strlen($error) > 150 ? substr($error, 0, 147).'...' : $error;
    }
}
