<?php

declare(strict_types=1);

use App\Notifications\PriceDropNotification;
use Illuminate\Notifications\Messages\MailMessage;

describe('notification properties', function () {
    test('stores all constructor parameters correctly', function () {
        $notification = new PriceDropNotification(
            productListingId: 123,
            productTitle: 'Pedigree Adult Dog Food 12kg',
            retailerName: 'B&M',
            productUrl: 'https://www.bmstores.co.uk/product/test',
            oldPricePence: 2500,
            newPricePence: 2000,
            dropPercentage: 20.0,
        );

        expect($notification->productListingId)->toBe(123)
            ->and($notification->productTitle)->toBe('Pedigree Adult Dog Food 12kg')
            ->and($notification->retailerName)->toBe('B&M')
            ->and($notification->productUrl)->toBe('https://www.bmstores.co.uk/product/test')
            ->and($notification->oldPricePence)->toBe(2500)
            ->and($notification->newPricePence)->toBe(2000)
            ->and($notification->dropPercentage)->toBe(20.0);
    });
});

describe('via method', function () {
    test('returns mail channel when configured', function () {
        config(['services.price_alerts.notification_channel' => 'mail']);

        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $channels = $notification->via(new stdClass);

        expect($channels)->toBe(['mail']);
    });

    test('returns slack channel when configured', function () {
        config(['services.price_alerts.notification_channel' => 'slack']);

        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $channels = $notification->via(new stdClass);

        expect($channels)->toBe(['slack']);
    });

    test('returns both channels when configured as all', function () {
        config(['services.price_alerts.notification_channel' => 'all']);

        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $channels = $notification->via(new stdClass);

        expect($channels)->toBe(['mail', 'slack']);
    });

    test('returns empty array for log channel', function () {
        config(['services.price_alerts.notification_channel' => 'log']);

        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $channels = $notification->via(new stdClass);

        expect($channels)->toBe([]);
    });
});

describe('toMail method', function () {
    test('returns MailMessage with correct subject', function () {
        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Pedigree Adult Dog Food 12kg',
            retailerName: 'B&M',
            productUrl: 'https://www.bmstores.co.uk/product/test',
            oldPricePence: 2500,
            newPricePence: 2000,
            dropPercentage: 20.0,
        );

        $mail = $notification->toMail(new stdClass);

        expect($mail)->toBeInstanceOf(MailMessage::class)
            ->and($mail->subject)->toBe('Price Drop Alert: Pedigree Adult Dog Food 12kg');
    });

    test('includes product url as action', function () {
        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test Product',
            retailerName: 'Test Retailer',
            productUrl: 'https://www.example.com/product/123',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $mail = $notification->toMail(new stdClass);

        expect($mail->actionUrl)->toBe('https://www.example.com/product/123')
            ->and($mail->actionText)->toBe('View Product');
    });
});

describe('toArray method', function () {
    test('returns array with all notification data', function () {
        $notification = new PriceDropNotification(
            productListingId: 123,
            productTitle: 'Test Product',
            retailerName: 'Test Retailer',
            productUrl: 'https://example.com/product',
            oldPricePence: 2500,
            newPricePence: 2000,
            dropPercentage: 20.0,
        );

        $array = $notification->toArray(new stdClass);

        expect($array)->toBe([
            'product_listing_id' => 123,
            'product_title' => 'Test Product',
            'retailer_name' => 'Test Retailer',
            'product_url' => 'https://example.com/product',
            'old_price_pence' => 2500,
            'new_price_pence' => 2000,
            'drop_percentage' => 20.0,
        ]);
    });
});

describe('price formatting', function () {
    test('formats prices correctly in mail', function () {
        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 2499,
            newPricePence: 1999,
            dropPercentage: 20.0,
        );

        $mail = $notification->toMail(new stdClass);

        // Check the intro lines contain formatted prices
        $introData = implode(' ', $mail->introLines);

        expect($introData)->toContain('£24.99')
            ->and($introData)->toContain('£19.99')
            ->and($introData)->toContain('£5.00');
    });

    test('handles pence values correctly', function () {
        $notification = new PriceDropNotification(
            productListingId: 1,
            productTitle: 'Test',
            retailerName: 'Test',
            productUrl: 'https://example.com',
            oldPricePence: 99,
            newPricePence: 50,
            dropPercentage: 49.49,
        );

        $mail = $notification->toMail(new stdClass);

        $introData = implode(' ', $mail->introLines);

        expect($introData)->toContain('£0.99')
            ->and($introData)->toContain('£0.50');
    });
});
