<?php

declare(strict_types=1);

beforeEach(function () {
    // Create a mock extractor for testing canHandle methods
    $this->extractor = new class
    {
        public function canHandle(string $url): bool
        {
            return str_contains($url, 'valid-domain.com');
        }
    };
});

describe('testCanHandleUrls', function () {
    test('validates multiple URLs at once', function () {
        $this->testCanHandleUrls(
            validUrls: [
                'https://valid-domain.com/page',
                'https://valid-domain.com/product/123',
            ],
            invalidUrls: [
                'https://other-domain.com/page',
                'https://invalid.com/product',
            ]
        );
    });
});

describe('assertHandlesUrls alias', function () {
    test('works as alias for testCanHandleUrls', function () {
        $this->assertHandlesUrls(
            validUrls: ['https://valid-domain.com/test'],
            invalidUrls: ['https://other-domain.com/test']
        );
    });
});

describe('commonInvalidUrls', function () {
    test('returns array of common invalid URLs', function () {
        $urls = $this->commonInvalidUrls();

        expect($urls)->toBeArray()
            ->and($urls)->toHaveKey('empty')
            ->and($urls)->toHaveKey('invalid_url')
            ->and($urls)->toHaveKey('about_page')
            ->and($urls)->toHaveKey('contact_page');
    });
});

describe('competitorDomainUrls', function () {
    test('returns all UK retailer competitor URLs', function () {
        $urls = $this->competitorDomainUrls();

        expect($urls)->toBeArray()
            ->and($urls)->toHaveKey('amazon')
            ->and($urls)->toHaveKey('tesco')
            ->and($urls)->toHaveKey('sainsburys')
            ->and($urls)->toHaveKey('morrisons')
            ->and($urls)->toHaveKey('asda')
            ->and($urls)->toHaveKey('ocado')
            ->and($urls)->toHaveKey('waitrose')
            ->and($urls)->toHaveKey('petsathome')
            ->and($urls)->toHaveKey('zooplus')
            ->and($urls)->toHaveKey('bm')
            ->and($urls)->toHaveKey('justforpets');
    });

    test('excludes specified domains', function () {
        $urls = $this->competitorDomainUrls(['amazon', 'tesco']);

        expect($urls)->not->toHaveKey('amazon')
            ->and($urls)->not->toHaveKey('tesco')
            ->and($urls)->toHaveKey('sainsburys');
    });
});

describe('buildCanHandleDataset', function () {
    test('builds dataset with expected result', function () {
        $urls = ['valid_page' => 'https://example.com/page'];
        $dataset = $this->buildCanHandleDataset($urls, true);

        expect($dataset)->toHaveKey('valid_page')
            ->and($dataset['valid_page'])->toBe(['https://example.com/page', true]);
    });

    test('uses URL as key for numeric indexed arrays', function () {
        $urls = ['https://example.com/page'];
        $dataset = $this->buildCanHandleDataset($urls, false);

        expect($dataset)->toHaveKey('https://example.com/page')
            ->and($dataset['https://example.com/page'])->toBe(['https://example.com/page', false]);
    });
});
