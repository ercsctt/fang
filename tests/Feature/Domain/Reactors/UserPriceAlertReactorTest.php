<?php

declare(strict_types=1);

use App\Domain\Crawler\Events\PriceDropped;
use App\Domain\Crawler\Reactors\PriceDropReactor;
use App\Enums\MatchType;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\ProductListing;
use App\Models\Retailer;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->retailer = Retailer::factory()->create([
        'name' => 'Tesco',
        'slug' => 'tesco',
    ]);

    $this->listing = ProductListing::factory()->for($this->retailer)->create([
        'title' => 'Premium Dog Food 10kg',
        'url' => 'https://www.tesco.com/product/premium-dog-food',
        'price_pence' => 2000,
    ]);
});

describe('user price alerts in PriceDropReactor', function () {
    test('notifies users when price drops below their target', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 1500,
            'is_active' => true,
        ]);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: $this->listing->title,
            retailerName: $this->retailer->name,
            productUrl: $this->listing->url,
            oldPricePence: 2000,
            newPricePence: 1400,
            dropPercentage: 30.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);

        Notification::assertSentTo($user, PriceAlertNotification::class);
    });

    test('does not notify users when price is above their target', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 1000,
            'is_active' => true,
        ]);

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

        Notification::assertNotSentTo($user, PriceAlertNotification::class);
    });

    test('does not notify users with inactive alerts', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->inactive()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
        ]);

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

        Notification::assertNotSentTo($user, PriceAlertNotification::class);
    });

    test('respects cooldown period for user notifications', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
            'is_active' => true,
            'last_notified_at' => now()->subHours(6),
        ]);

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

        Notification::assertNotSentTo($user, PriceAlertNotification::class);
    });

    test('notifies users after cooldown period has passed', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
            'is_active' => true,
            'last_notified_at' => now()->subHours(25),
        ]);

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

        Notification::assertSentTo($user, PriceAlertNotification::class);
    });

    test('updates last_notified_at when sending notification', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        $alert = PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
            'is_active' => true,
            'last_notified_at' => null,
        ]);

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

        $alert->refresh();
        expect($alert->last_notified_at)->not->toBeNull();
    });

    test('notifies multiple users with matching alerts', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->twice();

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user1->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
            'is_active' => true,
        ]);

        PriceAlert::factory()->create([
            'user_id' => $user2->id,
            'product_id' => $product->id,
            'target_price_pence' => 1800,
            'is_active' => true,
        ]);

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

        Notification::assertSentTo($user1, PriceAlertNotification::class);
        Notification::assertSentTo($user2, PriceAlertNotification::class);
    });

    test('does not fail when listing has no linked products', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();

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

        Notification::assertNothingSent();
    });

    test('notification contains correct data', function () {
        config([
            'services.price_alerts.threshold_percent' => 50,
            'services.price_alerts.user_cooldown_hours' => 24,
        ]);

        Notification::fake();
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->listing->products()->attach($product->id, [
            'confidence_score' => 95.0,
            'match_type' => MatchType::Manual->value,
            'matched_at' => now(),
        ]);

        $alert = PriceAlert::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'target_price_pence' => 2000,
            'is_active' => true,
        ]);

        $event = new PriceDropped(
            productListingId: $this->listing->id,
            productTitle: 'Premium Dog Food 10kg',
            retailerName: 'Tesco',
            productUrl: 'https://www.tesco.com/product/premium-dog-food',
            oldPricePence: 2000,
            newPricePence: 1500,
            dropPercentage: 25.0,
        );

        $reactor = new PriceDropReactor;
        $reactor->onPriceDropped($event);

        Notification::assertSentTo($user, PriceAlertNotification::class, function ($notification) use ($alert) {
            return $notification->priceAlert->id === $alert->id
                && $notification->productName === 'Premium Dog Food 10kg'
                && $notification->productUrl === 'https://www.tesco.com/product/premium-dog-food'
                && $notification->currentPricePence === 1500
                && $notification->retailerName === 'Tesco';
        });
    });
});
