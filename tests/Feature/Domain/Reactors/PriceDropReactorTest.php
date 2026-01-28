<?php

declare(strict_types=1);

use App\Domain\Crawler\Events\PriceDropped;
use App\Domain\Crawler\Reactors\PriceDropReactor;
use App\Models\ProductListing;
use App\Models\Retailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->retailer = Retailer::factory()->create([
        'name' => 'B&M',
        'slug' => 'bm',
    ]);

    $this->listing = ProductListing::factory()->for($this->retailer)->create([
        'title' => 'Pedigree Adult Dog Food 12kg',
        'url' => 'https://www.bmstores.co.uk/product/pedigree-adult-123456',
        'price_pence' => 2000,
    ]);
});

describe('calculateDropPercentage', function () {
    test('calculates correct percentage drop', function () {
        $oldPrice = 1000;
        $newPrice = 800;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(20.0);
    });

    test('calculates correct percentage for large drop', function () {
        $oldPrice = 5000;
        $newPrice = 2500;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(50.0);
    });

    test('returns zero when no drop', function () {
        $oldPrice = 1000;
        $newPrice = 1000;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(0.0);
    });

    test('returns zero when price increased', function () {
        $oldPrice = 1000;
        $newPrice = 1200;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(0.0);
    });

    test('returns zero when old price is zero', function () {
        $oldPrice = 0;
        $newPrice = 1000;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(0.0);
    });

    test('returns zero when old price is negative', function () {
        $oldPrice = -1000;
        $newPrice = 500;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(0.0);
    });

    test('rounds to two decimal places', function () {
        $oldPrice = 3333;
        $newPrice = 2222;

        $drop = PriceDropReactor::calculateDropPercentage($oldPrice, $newPrice);

        expect($drop)->toBe(33.33);
    });
});

describe('PriceDropped event handling', function () {
    test('logs when price drop is below threshold', function () {
        config(['services.price_alerts.threshold_percent' => 20]);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Price drop below threshold, skipping notification'
                    && $context['drop_percentage'] === 15.0
                    && $context['threshold'] === 20;
            });

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1700,
            dropPercentage: 15.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });

    test('logs and sends notification when price drop meets threshold', function () {
        config([
            'services.price_alerts.threshold_percent' => 20,
            'services.price_alerts.notification_channel' => 'log',
        ]);

        $channelMock = Mockery::mock();
        $channelMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'PRICE DROP ALERT'
                    && $context['drop_percentage'] === '25%';
            });

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Significant price drop detected, sending notification'
                    && $context['drop_percentage'] === 25.0;
            });

        Log::shouldReceive('channel')
            ->with('single')
            ->andReturn($channelMock);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });

    test('respects configurable threshold', function () {
        config([
            'services.price_alerts.threshold_percent' => 10,
            'services.price_alerts.notification_channel' => 'log',
        ]);

        $channelMock = Mockery::mock();
        $channelMock->shouldReceive('info')->once();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Significant price drop detected, sending notification'
                    && $context['threshold'] === 10;
            });

        Log::shouldReceive('channel')
            ->with('single')
            ->andReturn($channelMock);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1700,
            dropPercentage: 15.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });
});

describe('notification channels', function () {
    test('sends notification via mail when configured', function () {
        config([
            'services.price_alerts.threshold_percent' => 20,
            'services.price_alerts.notification_channel' => 'mail',
            'mail.from.address' => 'test@example.com',
        ]);

        Notification::fake();
        Log::shouldReceive('info')->once();

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);

        Notification::assertSentOnDemand(
            \App\Notifications\PriceDropNotification::class,
        );
    });

    test('uses log channel by default', function () {
        config(['services.price_alerts.notification_channel' => 'log']);

        $channelMock = Mockery::mock();
        $channelMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message) {
                return $message === 'PRICE DROP ALERT';
            });

        Log::shouldReceive('info')->once();
        Log::shouldReceive('channel')
            ->with('single')
            ->andReturn($channelMock);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });
});

describe('event data', function () {
    test('PriceDropped event contains all required data', function () {
        $event = new PriceDropped(
            productListingId: 123,
            productTitle: 'Test Product',
            retailerName: 'Test Retailer',
            productUrl: 'https://example.com/product',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        expect($event->productListingId)->toBe(123)
            ->and($event->productTitle)->toBe('Test Product')
            ->and($event->retailerName)->toBe('Test Retailer')
            ->and($event->productUrl)->toBe('https://example.com/product')
            ->and($event->oldPricePence)->toBe(2000)
            ->and($event->newPricePence)->toBe(1500)
            ->and($event->dropPercentage)->toBe(25.0);
    });
});

describe('edge cases', function () {
    test('handles exactly threshold percentage', function () {
        config([
            'services.price_alerts.threshold_percent' => 20,
            'services.price_alerts.notification_channel' => 'log',
        ]);

        $channelMock = Mockery::mock();
        $channelMock->shouldReceive('info')->once();

        Log::shouldReceive('info')->once();
        Log::shouldReceive('channel')
            ->with('single')
            ->andReturn($channelMock);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2500,
            newPricePence: 2000,
            dropPercentage: 20.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });

    test('handles just below threshold percentage', function () {
        config(['services.price_alerts.threshold_percent' => 20]);

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Price drop below threshold, skipping notification';
            });

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2500,
            newPricePence: 2001,
            dropPercentage: 19.96,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });

    test('handles very large percentage drops', function () {
        config([
            'services.price_alerts.threshold_percent' => 20,
            'services.price_alerts.notification_channel' => 'log',
        ]);

        $channelMock = Mockery::mock();
        $channelMock->shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $context['drop_percentage'] === '90%';
            });

        Log::shouldReceive('info')->once();
        Log::shouldReceive('channel')
            ->with('single')
            ->andReturn($channelMock);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 10000,
            newPricePence: 1000,
            dropPercentage: 90.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);
    });
});
