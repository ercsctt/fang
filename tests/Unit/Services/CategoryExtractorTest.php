<?php

declare(strict_types=1);

use App\Crawler\Services\CategoryExtractor;
use Symfony\Component\DomCrawler\Crawler;

beforeEach(function () {
    $this->extractor = new CategoryExtractor;
});

describe('extractFromUrl', function () {
    it('extracts dog-food from URL patterns', function () {
        $urls = [
            'https://example.com/pets/dog-food',
            'https://example.com/pets/puppy-food',
            'https://example.com/dog/food/123',
        ];

        foreach ($urls as $url) {
            expect($this->extractor->extractFromUrl($url))->toBe('dog-food');
        }
    });

    it('extracts dog-treats from URL patterns', function () {
        $urls = [
            'https://example.com/pets/dog-treats',
            'https://example.com/pets/puppy-treats',
        ];

        foreach ($urls as $url) {
            expect($this->extractor->extractFromUrl($url))->toBe('dog-treats');
        }
    });

    it('extracts cat-food from URL patterns', function () {
        $urls = [
            'https://example.com/pets/cat-food',
            'https://example.com/pets/kitten-food',
        ];

        foreach ($urls as $url) {
            expect($this->extractor->extractFromUrl($url))->toBe('cat-food');
        }
    });

    it('extracts cat-treats from URL patterns', function () {
        $urls = [
            'https://example.com/pets/cat-treats',
            'https://example.com/pets/kitten-treats',
        ];

        foreach ($urls as $url) {
            expect($this->extractor->extractFromUrl($url))->toBe('cat-treats');
        }
    });

    it('extracts from aisle paths', function () {
        $url = 'https://groceries.asda.com/aisle/pet-shop/dog/dog-food/123';

        expect($this->extractor->extractFromUrl($url))->toBe('dog food');
    });

    it('extracts from shelf paths', function () {
        $url = 'https://groceries.asda.com/shelf/dog-treats?page=2';

        expect($this->extractor->extractFromUrl($url))->toBe('dog treats');
    });

    it('extracts from super-department paths', function () {
        $url = 'https://groceries.asda.com/super-department/pet-shop';

        expect($this->extractor->extractFromUrl($url))->toBe('pet shop');
    });

    it('extracts from search queries', function () {
        $url = 'https://example.com/search/dog+food';

        expect($this->extractor->extractFromUrl($url))->toBe('dog+food');
    });

    it('extracts from pets category paths', function () {
        $url = 'https://example.com/shop/gb/groceries/pets/dog-food-and-treats';

        expect($this->extractor->extractFromUrl($url))->toBe('dog food and treats');
    });

    it('extracts from gol-ui paths (Sainsburys)', function () {
        $url = 'https://sainsburys.co.uk/gol-ui/product/dry-dog-food';

        expect($this->extractor->extractFromUrl($url))->toBe('dry dog food');
    });

    it('extracts from Ocado browse URLs', function () {
        $url = 'https://ocado.com/browse/pets-20974/dog-111797/dog-food-111800';

        expect($this->extractor->extractFromUrl($url))->toBe('dog-food');
    });

    it('returns null for URLs without recognizable patterns', function () {
        $urls = [
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/contact',
        ];

        foreach ($urls as $url) {
            expect($this->extractor->extractFromUrl($url))->toBeNull();
        }
    });

    it('falls back to general animal categories', function () {
        expect($this->extractor->extractFromUrl('https://example.com/dog/'))->toBe('dog');
        expect($this->extractor->extractFromUrl('https://example.com/cat/'))->toBe('cat');
        expect($this->extractor->extractFromUrl('https://example.com/puppy/'))->toBe('dog');
        expect($this->extractor->extractFromUrl('https://example.com/kitten/'))->toBe('cat');
    });
});

describe('extractFromBreadcrumbs', function () {
    it('extracts category from breadcrumb links', function () {
        $html = '
            <div class="breadcrumbs">
                <a href="/">Home</a>
                <a href="/pets">Pets</a>
                <a href="/pets/dog">Dog</a>
                <a href="/pets/dog/food">Dog Food</a>
                <a href="/pets/dog/food/123">Product Name</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler, ['.breadcrumbs a'], depthFromEnd: 1);

        expect($category)->toBe('Dog Food');
    });

    it('extracts last breadcrumb when depthFromEnd is 0', function () {
        $html = '
            <div class="breadcrumbs">
                <a href="/">Home</a>
                <a href="/pets">Pets</a>
                <a href="/pets/dog">Dog</a>
                <a href="/pets/dog/food">Dog Food</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler, ['.breadcrumbs a'], depthFromEnd: 0);

        expect($category)->toBe('Dog Food');
    });

    it('skips generic terms when extracting from breadcrumbs', function () {
        $html = '
            <div class="breadcrumbs">
                <a href="/">Home</a>
                <a href="/groceries">Groceries</a>
                <a href="/pets">Pets</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler, ['.breadcrumbs a'], depthFromEnd: 1);

        // Should skip "Pets" (generic) and return null since there are no other valid categories
        expect($category)->toBeNull();
    });

    it('tries multiple selectors in order', function () {
        $html = '
            <nav class="breadcrumb">
                <a href="/">Home</a>
                <a href="/pets">Pets</a>
                <a href="/dog">Dog</a>
                <a href="/dog-treats">Dog Treats</a>
            </nav>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs(
            $crawler,
            ['.not-exist a', 'nav.breadcrumb a'],
            depthFromEnd: 1
        );

        expect($category)->toBe('Dog');
    });

    it('uses default selectors when none provided', function () {
        $html = '
            <div class="breadcrumb">
                <a href="/">Home</a>
                <a href="/category">Category</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler);

        expect($category)->toBe('Category');
    });

    it('returns null when breadcrumbs have less than 2 items', function () {
        $html = '
            <div class="breadcrumbs">
                <a href="/">Home</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler, ['.breadcrumbs a']);

        expect($category)->toBeNull();
    });

    it('returns null when no breadcrumb elements found', function () {
        $html = '<div>No breadcrumbs here</div>';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs($crawler, ['.breadcrumbs a']);

        expect($category)->toBeNull();
    });

    it('handles Amazon breadcrumb format', function () {
        $html = '
            <div id="wayfinding-breadcrumbs_feature_div">
                <a href="/">All Categories</a>
                <a href="/pet-supplies">Pet Supplies</a>
                <a href="/dog-food">Dog Food</a>
                <a href="/dry-dog-food">Dry Dog Food</a>
            </div>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs(
            $crawler,
            ['#wayfinding-breadcrumbs_feature_div a'],
            depthFromEnd: 0
        );

        expect($category)->toBe('Dry Dog Food');
    });

    it('handles Tesco breadcrumb format with generic filtering', function () {
        $html = '
            <nav class="beans-breadcrumb">
                <a href="/">Home</a>
                <a href="/groceries">Groceries</a>
                <a href="/pets">Pets</a>
                <a href="/dog">Dog</a>
                <a href="/dog-food">Dog Food & Treats</a>
            </nav>
        ';

        $crawler = new Crawler($html);
        $category = $this->extractor->extractFromBreadcrumbs(
            $crawler,
            ['.beans-breadcrumb a'],
            depthFromEnd: 1
        );

        expect($category)->toBe('Dog');
    });
});

describe('integration with config', function () {
    it('uses patterns from config file', function () {
        // Ensure config is loaded
        expect(config('crawler.category_patterns'))->toBeArray();
        expect(config('crawler.category_filters'))->toBeArray();

        // Test that dog-food pattern works as expected
        $url = 'https://example.com/pets/dog-food';
        expect($this->extractor->extractFromUrl($url))->toBe('dog-food');
    });

    it('filters generic terms from config', function () {
        $genericTerms = config('crawler.category_filters');
        expect($genericTerms)->toContain('home');
        expect($genericTerms)->toContain('groceries');
        expect($genericTerms)->toContain('pets');
    });
});
