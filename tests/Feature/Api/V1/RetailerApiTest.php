<?php

use App\Enums\RetailerHealthStatus;
use App\Models\ProductListing;
use App\Models\Retailer;

use function Pest\Laravel\getJson;

describe('GET /api/v1/retailers', function () {
    it('returns a paginated list of retailers', function () {
        Retailer::factory()->count(3)->create();

        $response = getJson(route('api.v1.retailers.index'));

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'base_url',
                        'is_active',
                        'health_status',
                        'health_status_label',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    });

    it('filters retailers by active status', function () {
        Retailer::factory()->create(['is_active' => true]);
        Retailer::factory()->inactive()->create();

        $response = getJson(route('api.v1.retailers.index', ['active' => 'true']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', true);
    });

    it('filters retailers by health status', function () {
        Retailer::factory()->create(['health_status' => RetailerHealthStatus::Healthy]);
        Retailer::factory()->degraded()->create();
        Retailer::factory()->unhealthy()->create();

        $response = getJson(route('api.v1.retailers.index', ['health_status' => 'healthy']));

        $response->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.health_status', 'healthy');
    });

    it('includes product listings count', function () {
        $retailer = Retailer::factory()->create();
        ProductListing::factory()->count(5)->create(['retailer_id' => $retailer->id]);

        $response = getJson(route('api.v1.retailers.index'));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.listings_count', 5);
    });

    it('sorts retailers by name', function () {
        Retailer::factory()->create(['name' => 'Zebra Store']);
        Retailer::factory()->create(['name' => 'Alpha Store']);

        $response = getJson(route('api.v1.retailers.index', [
            'sort' => 'name',
            'direction' => 'asc',
        ]));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.name', 'Alpha Store')
            ->assertJsonPath('data.1.name', 'Zebra Store');
    });

    it('respects per_page parameter', function () {
        Retailer::factory()->count(5)->create();

        $response = getJson(route('api.v1.retailers.index', ['per_page' => 2]));

        $response->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    });

    it('shows paused status for paused retailers', function () {
        $retailer = Retailer::factory()->paused()->create();

        $response = getJson(route('api.v1.retailers.index'));

        $response->assertSuccessful()
            ->assertJsonPath('data.0.is_paused', true)
            ->assertJsonPath('data.0.paused_until', fn ($value) => $value !== null);
    });
});
