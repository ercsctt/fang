<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

class DataFreshnessAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $criticalIssues
     */
    public function __construct(
        public readonly array $criticalIssues,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('monitoring.notification_channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Data Freshness Alert: Critical Issues Detected')
            ->error()
            ->greeting('Data Freshness Alert')
            ->line('The following critical data freshness issues have been detected:');

        if (isset($this->criticalIssues['stale_products'])) {
            $message->line("**Stale Products:** {$this->criticalIssues['stale_products']['total']} listings not scraped in {$this->criticalIssues['stale_products']['threshold_days']} days");
        }

        if (isset($this->criticalIssues['inactive_retailers'])) {
            $message->line("**Inactive Retailers:** {$this->criticalIssues['inactive_retailers']['total']} retailers with no crawls in {$this->criticalIssues['inactive_retailers']['threshold_hours']} hours");

            foreach ($this->criticalIssues['inactive_retailers']['retailers'] as $retailer) {
                $message->line("  - {$retailer['name']}: {$retailer['last_crawled']} (Failures: {$retailer['consecutive_failures']})");
            }
        }

        if (isset($this->criticalIssues['high_failure_retailers'])) {
            $message->line("**High Failure Rates:** {$this->criticalIssues['high_failure_retailers']['total']} retailers exceeding {$this->criticalIssues['high_failure_retailers']['threshold_percent']}% failure threshold");

            foreach ($this->criticalIssues['high_failure_retailers']['retailers'] as $retailer) {
                $message->line("  - {$retailer['name']}: {$retailer['failure_rate']}% failure rate ({$retailer['failed_listings']}/{$retailer['total_listings']})");
            }
        }

        return $message
            ->line('Please investigate and resolve these issues immediately.')
            ->action('View Dashboard', config('app.url').'/admin/monitoring')
            ->salutation('Fang Data Monitoring');
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $message = (new SlackMessage)
            ->error()
            ->content('🚨 Data Freshness Alert: Critical Issues Detected');

        if (isset($this->criticalIssues['stale_products'])) {
            $message->attachment(function ($attachment) {
                $data = $this->criticalIssues['stale_products'];
                $attachment
                    ->title('Stale Products')
                    ->color('danger')
                    ->fields([
                        'Total Stale Listings' => (string) $data['total'],
                        'Threshold' => $data['threshold_days'].' days',
                    ]);
            });
        }

        if (isset($this->criticalIssues['inactive_retailers'])) {
            $message->attachment(function ($attachment) {
                $data = $this->criticalIssues['inactive_retailers'];
                $retailers = array_map(
                    fn ($r) => "{$r['name']} ({$r['last_crawled']})",
                    array_slice($data['retailers'], 0, 5)
                );

                $attachment
                    ->title('Inactive Retailers')
                    ->color('danger')
                    ->fields([
                        'Total Inactive' => (string) $data['total'],
                        'Threshold' => $data['threshold_hours'].' hours',
                        'Affected Retailers' => implode("\n", $retailers),
                    ]);
            });
        }

        if (isset($this->criticalIssues['high_failure_retailers'])) {
            $message->attachment(function ($attachment) {
                $data = $this->criticalIssues['high_failure_retailers'];
                $retailers = array_map(
                    fn ($r) => "{$r['name']}: {$r['failure_rate']}% ({$r['failed_listings']}/{$r['total_listings']})",
                    array_slice($data['retailers'], 0, 5)
                );

                $attachment
                    ->title('High Failure Rates')
                    ->color('danger')
                    ->fields([
                        'Total Affected' => (string) $data['total'],
                        'Threshold' => $data['threshold_percent'].'%',
                        'Retailers' => implode("\n", $retailers),
                    ]);
            });
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'critical_issues' => $this->criticalIssues,
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}
