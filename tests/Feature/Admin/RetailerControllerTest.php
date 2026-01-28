<?php

declare(strict_types=1);

use App\Enums\RetailerStatus;
use App\Models\ProductListing;
use App\Models\Retailer;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

test('retailers page requires authentication', function () {
    $response = $this->get('/admin/retailers');

    $response->assertRedirect('/login');
});

test('retailers page loads for authenticated users', function () {
    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Retailers/Index')
            ->has('retailers')
            ->has('statusCounts')
            ->has('summaryStats')
            ->has('filters')
            ->has('statuses')
        );
});

test('retailers page shows all retailers', function () {
    Retailer::factory()->create(['name' => 'Store A']);
    Retailer::factory()->create(['name' => 'Store B']);
    Retailer::factory()->create(['name' => 'Store C']);

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Retailers/Index')
            ->has('retailers', 3)
        );
});

test('retailers page shows retailer with correct status information', function () {
    $retailer = Retailer::factory()->create([
        'name' => 'Test Retailer',
        'slug' => 'test-retailer',
        'base_url' => 'https://test.example.com',
        'status' => RetailerStatus::Active,
    ]);

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Retailers/Index')
            ->has('retailers', 1)
            ->where('retailers.0.name', 'Test Retailer')
            ->where('retailers.0.slug', 'test-retailer')
            ->where('retailers.0.base_url', 'https://test.example.com')
            ->where('retailers.0.status', 'active')
            ->where('retailers.0.status_label', 'Active')
        );
});

test('retailers page filters by status', function () {
    Retailer::factory()->create(['name' => 'Active Store', 'status' => RetailerStatus::Active]);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);
    Retailer::factory()->disabled()->create(['name' => 'Disabled Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?status=active');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 1)
            ->where('retailers.0.name', 'Active Store')
            ->where('filters.status', 'active')
        );

    $responsePaused = $this->actingAs($this->user)->get('/admin/retailers?status=paused');

    $responsePaused->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 1)
            ->where('retailers.0.name', 'Paused Store')
            ->where('filters.status', 'paused')
        );
});

test('retailers page filters by search query', function () {
    Retailer::factory()->create(['name' => 'Tesco', 'slug' => 'tesco']);
    Retailer::factory()->create(['name' => 'Asda', 'slug' => 'asda']);
    Retailer::factory()->create(['name' => 'Sainsburys', 'slug' => 'sainsburys']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?search=tesco');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 1)
            ->where('retailers.0.name', 'Tesco')
            ->where('filters.search', 'tesco')
        );
});

test('retailers page sorts by name ascending by default', function () {
    Retailer::factory()->create(['name' => 'Zebra Store']);
    Retailer::factory()->create(['name' => 'Alpha Store']);
    Retailer::factory()->create(['name' => 'Middle Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('retailers.0.name', 'Alpha Store')
            ->where('retailers.1.name', 'Middle Store')
            ->where('retailers.2.name', 'Zebra Store')
            ->where('filters.sort', 'name')
            ->where('filters.dir', 'asc')
        );
});

test('retailers page sorts by specified field and direction', function () {
    Retailer::factory()->create(['name' => 'Store A', 'consecutive_failures' => 5]);
    Retailer::factory()->create(['name' => 'Store B', 'consecutive_failures' => 10]);
    Retailer::factory()->create(['name' => 'Store C', 'consecutive_failures' => 0]);

    $response = $this->actingAs($this->user)->get('/admin/retailers?sort=consecutive_failures&dir=desc');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('retailers.0.name', 'Store B')
            ->where('retailers.1.name', 'Store A')
            ->where('retailers.2.name', 'Store C')
            ->where('filters.sort', 'consecutive_failures')
            ->where('filters.dir', 'desc')
        );
});

test('retailers page shows correct status counts', function () {
    Retailer::factory()->count(3)->create(['status' => RetailerStatus::Active]);
    Retailer::factory()->count(2)->paused()->create();
    Retailer::factory()->count(1)->disabled()->create();
    Retailer::factory()->count(1)->degraded()->create();
    Retailer::factory()->count(1)->failed()->create();

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('statusCounts.all', 8)
            ->where('statusCounts.active', 3)
            ->where('statusCounts.paused', 2)
            ->where('statusCounts.disabled', 1)
            ->where('statusCounts.degraded', 1)
            ->where('statusCounts.failed', 1)
        );
});

test('retailers page shows correct summary stats', function () {
    Retailer::factory()->create([
        'status' => RetailerStatus::Active,
        'last_crawled_at' => now()->subHours(12),
    ]);
    Retailer::factory()->degraded()->create([
        'last_crawled_at' => now()->subHours(6),
    ]);
    Retailer::factory()->paused()->create([
        'last_crawled_at' => now()->subDays(2),
    ]);
    Retailer::factory()->disabled()->create([
        'last_crawled_at' => null,
    ]);

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summaryStats.total', 4)
            ->where('summaryStats.crawlable', 2)
            ->where('summaryStats.with_problems', 2)
            ->where('summaryStats.recently_crawled', 2)
        );
});

test('retailers page shows product count', function () {
    $retailer = Retailer::factory()->create(['name' => 'Store With Products']);
    ProductListing::factory()->count(5)->for($retailer)->create();

    $emptyRetailer = Retailer::factory()->create(['name' => 'Empty Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $storeWithProducts = $retailers->firstWhere('name', 'Store With Products');
    $emptyStore = $retailers->firstWhere('name', 'Empty Store');

    expect($storeWithProducts['product_listings_count'])->toBe(5);
    expect($emptyStore['product_listings_count'])->toBe(0);
});

test('retailers page shows available statuses for filtering', function () {
    $response = $this->actingAs($this->user)->get('/admin/retailers');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('statuses', 5)
            ->where('statuses.0.value', 'active')
            ->where('statuses.0.label', 'Active')
            ->where('statuses.1.value', 'paused')
            ->where('statuses.1.label', 'Paused')
        );
});

test('retailers page shows can_pause flag correctly', function () {
    Retailer::factory()->create(['name' => 'Active Store', 'status' => RetailerStatus::Active]);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);
    Retailer::factory()->disabled()->create(['name' => 'Disabled Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?sort=name&dir=asc');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $activeRetailer = $retailers->firstWhere('name', 'Active Store');
    $pausedRetailer = $retailers->firstWhere('name', 'Paused Store');
    $disabledRetailer = $retailers->firstWhere('name', 'Disabled Store');

    expect($activeRetailer['can_pause'])->toBeTrue();
    expect($pausedRetailer['can_pause'])->toBeFalse();
    expect($disabledRetailer['can_pause'])->toBeFalse();
});

test('retailers page shows can_resume flag correctly', function () {
    Retailer::factory()->create(['name' => 'Active Store', 'status' => RetailerStatus::Active]);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);
    Retailer::factory()->disabled()->create(['name' => 'Disabled Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?sort=name&dir=asc');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $activeRetailer = $retailers->firstWhere('name', 'Active Store');
    $pausedRetailer = $retailers->firstWhere('name', 'Paused Store');
    $disabledRetailer = $retailers->firstWhere('name', 'Disabled Store');

    expect($activeRetailer['can_resume'])->toBeFalse();
    expect($pausedRetailer['can_resume'])->toBeTrue();
    expect($disabledRetailer['can_resume'])->toBeFalse();
});

test('retailers page shows can_enable flag correctly', function () {
    Retailer::factory()->create(['name' => 'Active Store', 'status' => RetailerStatus::Active]);
    Retailer::factory()->disabled()->create(['name' => 'Disabled Store']);
    Retailer::factory()->failed()->create(['name' => 'Failed Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?sort=name&dir=asc');

    $response->assertOk();

    $inertiaProps = $response->original->getData()['page']['props'];
    $retailers = collect($inertiaProps['retailers']);

    $activeRetailer = $retailers->firstWhere('name', 'Active Store');
    $disabledRetailer = $retailers->firstWhere('name', 'Disabled Store');
    $failedRetailer = $retailers->firstWhere('name', 'Failed Store');

    expect($activeRetailer['can_enable'])->toBeFalse();
    expect($disabledRetailer['can_enable'])->toBeTrue();
    expect($failedRetailer['can_enable'])->toBeTrue();
});

test('retailers page returns all filter when status is all', function () {
    Retailer::factory()->create(['name' => 'Active Store', 'status' => RetailerStatus::Active]);
    Retailer::factory()->paused()->create(['name' => 'Paused Store']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?status=all');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 2)
            ->where('filters.status', 'all')
        );
});

test('retailers page search is case insensitive', function () {
    Retailer::factory()->create(['name' => 'TESCO']);
    Retailer::factory()->create(['name' => 'Asda']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?search=tesco');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 1)
            ->where('retailers.0.name', 'TESCO')
        );

    $responseUpper = $this->actingAs($this->user)->get('/admin/retailers?search=ASDA');

    $responseUpper->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('retailers', 1)
            ->where('retailers.0.name', 'Asda')
        );
});

test('retailers page ignores invalid sort field', function () {
    Retailer::factory()->create(['name' => 'Zebra']);
    Retailer::factory()->create(['name' => 'Alpha']);

    $response = $this->actingAs($this->user)->get('/admin/retailers?sort=invalid_field');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('retailers.0.name', 'Alpha')
            ->where('retailers.1.name', 'Zebra')
        );
});

describe('create', function () {
    test('create page requires authentication', function () {
        $response = $this->get('/admin/retailers/create');

        $response->assertRedirect('/login');
    });

    test('create page loads for authenticated users', function () {
        $response = $this->actingAs($this->user)->get('/admin/retailers/create');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Retailers/Create')
                ->has('crawlerClasses')
                ->has('statuses')
                ->has('defaultStatus')
            );
    });

    test('create page includes available crawler classes', function () {
        $response = $this->actingAs($this->user)->get('/admin/retailers/create');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('crawlerClasses')
                ->where('crawlerClasses', fn ($crawlers) => count($crawlers) > 0)
            );
    });
});

describe('store', function () {
    test('store requires authentication', function () {
        $response = $this->post('/admin/retailers', [
            'name' => 'Test Store',
            'base_url' => 'https://test.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $response->assertRedirect('/login');
    });

    test('store creates a new retailer', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'New Test Store',
            'base_url' => 'https://newstore.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1500,
            'status' => 'active',
        ]);

        $retailer = Retailer::where('name', 'New Test Store')->first();

        expect($retailer)->not->toBeNull();
        expect($retailer->slug)->toBe('new-test-store');
        expect($retailer->base_url)->toBe('https://newstore.example.com');
        expect($retailer->crawler_class)->toBe('App\\Crawler\\Scrapers\\TescoCrawler');
        expect($retailer->rate_limit_ms)->toBe(1500);
        expect($retailer->status)->toBe(RetailerStatus::Active);

        $response->assertRedirect("/admin/retailers/{$retailer->id}/edit");
    });

    test('store creates retailer with custom slug', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Store With Custom Slug',
            'slug' => 'custom-slug',
            'base_url' => 'https://custom.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $retailer = Retailer::where('slug', 'custom-slug')->first();

        expect($retailer)->not->toBeNull();
        expect($retailer->name)->toBe('Store With Custom Slug');
    });

    test('store validates required fields', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', []);

        $response->assertSessionHasErrors(['name', 'base_url', 'crawler_class', 'rate_limit_ms', 'status']);
    });

    test('store validates name uniqueness', function () {
        Retailer::factory()->create(['name' => 'Existing Store']);

        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Existing Store',
            'base_url' => 'https://test.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['name']);
    });

    test('store validates base_url format', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Test Store',
            'base_url' => 'not-a-valid-url',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['base_url']);
    });

    test('store validates rate_limit_ms range', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Test Store',
            'base_url' => 'https://test.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 50,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['rate_limit_ms']);

        $responseTooHigh = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Test Store 2',
            'base_url' => 'https://test2.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 100000,
            'status' => 'active',
        ]);

        $responseTooHigh->assertSessionHasErrors(['rate_limit_ms']);
    });

    test('store validates status is valid enum', function () {
        $response = $this->actingAs($this->user)->post('/admin/retailers', [
            'name' => 'Test Store',
            'base_url' => 'https://test.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'invalid-status',
        ]);

        $response->assertSessionHasErrors(['status']);
    });
});

describe('edit', function () {
    test('edit page requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->get("/admin/retailers/{$retailer->id}/edit");

        $response->assertRedirect('/login');
    });

    test('edit page loads for authenticated users', function () {
        $retailer = Retailer::factory()->create(['name' => 'Test Retailer']);

        $response = $this->actingAs($this->user)->get("/admin/retailers/{$retailer->id}/edit");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Retailers/Edit')
                ->has('retailer')
                ->has('crawlerClasses')
                ->has('statuses')
                ->has('statistics')
                ->has('failureHistory')
                ->where('retailer.name', 'Test Retailer')
            );
    });

    test('edit page shows retailer statistics', function () {
        $retailer = Retailer::factory()->create();
        ProductListing::factory()->count(10)->for($retailer)->create();

        $response = $this->actingAs($this->user)->get("/admin/retailers/{$retailer->id}/edit");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('statistics.product_count', 10)
            );
    });

    test('edit page returns 404 for non-existent retailer', function () {
        $response = $this->actingAs($this->user)->get('/admin/retailers/999999/edit');

        $response->assertNotFound();
    });
});

describe('update', function () {
    test('update requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->put("/admin/retailers/{$retailer->id}", [
            'name' => 'Updated Store',
            'base_url' => 'https://updated.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 2000,
            'status' => 'active',
        ]);

        $response->assertRedirect('/login');
    });

    test('update modifies retailer', function () {
        $retailer = Retailer::factory()->create([
            'name' => 'Original Store',
            'slug' => 'original-store',
            'base_url' => 'https://original.example.com',
            'rate_limit_ms' => 1000,
        ]);

        $response = $this->actingAs($this->user)->put("/admin/retailers/{$retailer->id}", [
            'name' => 'Updated Store',
            'base_url' => 'https://updated.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\AsdaCrawler',
            'rate_limit_ms' => 2000,
            'status' => 'paused',
        ]);

        $retailer->refresh();

        expect($retailer->name)->toBe('Updated Store');
        expect($retailer->slug)->toBe('updated-store');
        expect($retailer->base_url)->toBe('https://updated.example.com');
        expect($retailer->crawler_class)->toBe('App\\Crawler\\Scrapers\\AsdaCrawler');
        expect($retailer->rate_limit_ms)->toBe(2000);
        expect($retailer->status)->toBe(RetailerStatus::Paused);

        $response->assertRedirect("/admin/retailers/{$retailer->id}/edit");
    });

    test('update allows same name for the same retailer', function () {
        $retailer = Retailer::factory()->create(['name' => 'Same Store']);

        $response = $this->actingAs($this->user)->put("/admin/retailers/{$retailer->id}", [
            'name' => 'Same Store',
            'base_url' => 'https://updated.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $response->assertRedirect("/admin/retailers/{$retailer->id}/edit");
        $response->assertSessionHasNoErrors();
    });

    test('update validates name uniqueness against other retailers', function () {
        Retailer::factory()->create(['name' => 'Existing Store']);
        $retailer = Retailer::factory()->create(['name' => 'My Store']);

        $response = $this->actingAs($this->user)->put("/admin/retailers/{$retailer->id}", [
            'name' => 'Existing Store',
            'base_url' => 'https://test.example.com',
            'crawler_class' => 'App\\Crawler\\Scrapers\\TescoCrawler',
            'rate_limit_ms' => 1000,
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['name']);
    });

    test('update validates required fields', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->actingAs($this->user)->put("/admin/retailers/{$retailer->id}", []);

        $response->assertSessionHasErrors(['name', 'base_url', 'crawler_class', 'rate_limit_ms', 'status']);
    });
});

describe('test connection', function () {
    test('test connection requires authentication', function () {
        $retailer = Retailer::factory()->create();

        $response = $this->postJson("/admin/retailers/{$retailer->id}/test-connection");

        $response->assertUnauthorized();
    });

    test('test connection fails for retailer without crawler class', function () {
        $retailer = Retailer::factory()->create(['crawler_class' => null]);

        $response = $this->actingAs($this->user)
            ->postJson("/admin/retailers/{$retailer->id}/test-connection");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Crawler class not found or not configured.',
            ]);
    });

    test('test connection fails for invalid crawler class', function () {
        $retailer = Retailer::factory()->create(['crawler_class' => 'App\\Invalid\\NonExistentCrawler']);

        $response = $this->actingAs($this->user)
            ->postJson("/admin/retailers/{$retailer->id}/test-connection");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Crawler class not found or not configured.',
            ]);
    });
});
