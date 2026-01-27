<?php

declare(strict_types=1);

use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceAlertNotification;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->product = Product::factory()->create(['name' => 'Test Dog Food']);
    $this->priceAlert = PriceAlert::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'target_price_pence' => 1500,
    ]);

    $this->notification = new PriceAlertNotification(
        priceAlert: $this->priceAlert,
        productName: 'Test Dog Food',
        productUrl: 'https://example.com/product/123',
        currentPricePence: 1200,
        retailerName: 'Tesco',
    );
});

describe('notification channels', function () {
    test('uses mail and database channels', function () {
        $channels = $this->notification->via($this->user);

        expect($channels)->toBe(['mail', 'database']);
    });
});

describe('mail representation', function () {
    test('contains product name in subject', function () {
        $mail = $this->notification->toMail($this->user);

        expect($mail->subject)->toContain('Test Dog Food');
    });

    test('contains current price in subject', function () {
        $mail = $this->notification->toMail($this->user);

        expect($mail->subject)->toContain('£12.00');
    });

    test('contains greeting', function () {
        $mail = $this->notification->toMail($this->user);

        expect($mail->greeting)->toBe('Good news!');
    });

    test('contains retailer name', function () {
        $mail = $this->notification->toMail($this->user);

        $content = implode(' ', $mail->introLines);

        expect($content)->toContain('Tesco');
    });

    test('contains product URL in action', function () {
        $mail = $this->notification->toMail($this->user);

        expect($mail->actionUrl)->toBe('https://example.com/product/123');
    });

    test('shows savings calculation', function () {
        $mail = $this->notification->toMail($this->user);

        $content = implode(' ', $mail->introLines);

        expect($content)->toContain('£3.00');
    });
});

describe('array representation', function () {
    test('contains all required fields', function () {
        $array = $this->notification->toArray($this->user);

        expect($array)->toHaveKeys([
            'price_alert_id',
            'product_id',
            'product_name',
            'product_url',
            'current_price_pence',
            'target_price_pence',
            'retailer_name',
        ]);
    });

    test('contains correct values', function () {
        $array = $this->notification->toArray($this->user);

        expect($array['price_alert_id'])->toBe($this->priceAlert->id)
            ->and($array['product_id'])->toBe($this->product->id)
            ->and($array['product_name'])->toBe('Test Dog Food')
            ->and($array['product_url'])->toBe('https://example.com/product/123')
            ->and($array['current_price_pence'])->toBe(1200)
            ->and($array['target_price_pence'])->toBe(1500)
            ->and($array['retailer_name'])->toBe('Tesco');
    });
});

describe('queue', function () {
    test('implements ShouldQueue', function () {
        expect($this->notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });
});
