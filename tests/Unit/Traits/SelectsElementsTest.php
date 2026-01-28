<?php

declare(strict_types=1);

use App\Crawler\Extractors\Concerns\SelectsElements;
use Symfony\Component\DomCrawler\Crawler;

beforeEach(function () {
    $this->selectorHelper = new class
    {
        use SelectsElements;

        public function pickFirst(Crawler $crawler, array $selectors, ?callable $accept = null): ?Crawler
        {
            return $this->selectFirst($crawler, $selectors, 'Test', $accept);
        }

        public function pickAll(Crawler $crawler, array $selectors): ?Crawler
        {
            return $this->selectAll($crawler, $selectors, 'Test');
        }
    };
});

it('selects the first matching element that passes acceptance', function () {
    $html = <<<'HTML'
        <div class="title"></div>
        <h1>Good Title</h1>
    HTML;

    $crawler = new Crawler($html);

    $element = $this->selectorHelper->pickFirst(
        $crawler,
        ['.title', 'h1'],
        fn (Crawler $node): bool => trim($node->text()) !== ''
    );

    expect($element)->not->toBeNull();
    expect($element->text())->toBe('Good Title');
});

it('selects all elements from the first matching selector', function () {
    $html = <<<'HTML'
        <ul>
            <li class="item">One</li>
            <li class="item">Two</li>
        </ul>
    HTML;

    $crawler = new Crawler($html);

    $elements = $this->selectorHelper->pickAll($crawler, ['.item', 'li']);

    expect($elements)->not->toBeNull();
    expect($elements->count())->toBe(2);
});

it('returns null when no selectors match', function () {
    $crawler = new Crawler('<div></div>');

    $element = $this->selectorHelper->pickFirst($crawler, ['.missing']);
    $elements = $this->selectorHelper->pickAll($crawler, ['.missing']);

    expect($element)->toBeNull();
    expect($elements)->toBeNull();
});
