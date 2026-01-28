<?php

declare(strict_types=1);

use App\Notifications\CrawlFailureAlertNotification;
use Illuminate\Notifications\AnonymousNotifiable;

describe('CrawlFailureAlertNotification', function () {
    test('has correct properties', function () {
        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 15,
            lastUrl: 'https://www.bmstores.co.uk/product/test',
            lastErrorMessage: 'Connection timeout',
        );

        expect($notification->retailerSlug)->toBe('bm')
            ->and($notification->failureCount)->toBe(15)
            ->and($notification->lastUrl)->toBe('https://www.bmstores.co.uk/product/test')
            ->and($notification->lastErrorMessage)->toBe('Connection timeout');
    });

    test('toArray returns correct structure', function () {
        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'tesco',
            failureCount: 12,
            lastUrl: 'https://www.tesco.com/product/test',
            lastErrorMessage: 'Server error 500',
        );

        $array = $notification->toArray(new AnonymousNotifiable);

        expect($array)->toHaveKey('retailer_slug', 'tesco')
            ->and($array)->toHaveKey('failure_count', 12)
            ->and($array)->toHaveKey('last_url', 'https://www.tesco.com/product/test')
            ->and($array)->toHaveKey('last_error_message', 'Server error 500');
    });

    test('via returns slack channel when webhook configured', function () {
        config(['services.slack.notifications.webhook' => 'https://hooks.slack.com/test']);
        config(['mail.crawl_alerts_to' => null]);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: 'https://example.com',
            lastErrorMessage: 'Test error',
        );

        $channels = $notification->via(new AnonymousNotifiable);

        expect($channels)->toContain('slack')
            ->and($channels)->not->toContain('mail');
    });

    test('via returns mail channel when email configured', function () {
        config(['services.slack.notifications.webhook' => null]);
        config(['mail.crawl_alerts_to' => 'alerts@example.com']);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: 'https://example.com',
            lastErrorMessage: 'Test error',
        );

        $channels = $notification->via(new AnonymousNotifiable);

        expect($channels)->toContain('mail')
            ->and($channels)->not->toContain('slack');
    });

    test('via returns both channels when both configured', function () {
        config(['services.slack.notifications.webhook' => 'https://hooks.slack.com/test']);
        config(['mail.crawl_alerts_to' => 'alerts@example.com']);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: 'https://example.com',
            lastErrorMessage: 'Test error',
        );

        $channels = $notification->via(new AnonymousNotifiable);

        expect($channels)->toContain('slack')
            ->and($channels)->toContain('mail');
    });

    test('via returns empty array when no channels configured', function () {
        config(['services.slack.notifications.webhook' => null]);
        config(['mail.crawl_alerts_to' => null]);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: 'https://example.com',
            lastErrorMessage: 'Test error',
        );

        $channels = $notification->via(new AnonymousNotifiable);

        expect($channels)->toBe([]);
    });

    test('toMail contains retailer and failure count', function () {
        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'amazon-uk',
            failureCount: 25,
            lastUrl: 'https://www.amazon.co.uk/product/test',
            lastErrorMessage: 'Rate limit exceeded',
        );

        $mail = $notification->toMail(new AnonymousNotifiable);

        expect($mail->subject)->toContain('amazon-uk')
            ->and($mail->subject)->toContain('25');
    });

    test('toSlack returns array with alert content', function () {
        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'sainsburys',
            failureCount: 15,
            lastUrl: 'https://www.sainsburys.co.uk/product/test',
            lastErrorMessage: 'Network timeout',
        );

        $slack = $notification->toSlack(new AnonymousNotifiable);

        expect($slack)->toBeArray()
            ->and($slack['text'])->toContain('sainsburys')
            ->and($slack['attachments'][0]['color'])->toBe('danger');
    });
});

describe('URL and error truncation', function () {
    test('truncates long URLs in Slack message', function () {
        $longUrl = 'https://www.example.com/'.str_repeat('a', 100);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: $longUrl,
            lastErrorMessage: 'Error',
        );

        $array = $notification->toArray(new AnonymousNotifiable);

        expect($array['last_url'])->toBe($longUrl);
    });

    test('truncates long error messages', function () {
        $longError = str_repeat('Error message ', 50);

        $notification = new CrawlFailureAlertNotification(
            retailerSlug: 'bm',
            failureCount: 10,
            lastUrl: 'https://example.com',
            lastErrorMessage: $longError,
        );

        $array = $notification->toArray(new AnonymousNotifiable);

        expect($array['last_error_message'])->toBe($longError);
    });
});
