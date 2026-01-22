<?php

use App\Crawler\Services\UserAgentRotator;

test('rotator loads user agents', function () {
    $rotator = new UserAgentRotator();

    expect($rotator->all())->toBeArray()
        ->not->toBeEmpty()
        ->and($rotator->count())->toBeGreaterThan(0);
});

test('rotator returns random user agent', function () {
    $rotator = new UserAgentRotator();
    $userAgent = $rotator->random();

    expect($userAgent)->toBeString()
        ->toContain('Mozilla')
        ->and(in_array($userAgent, $rotator->all()))->toBeTrue();
});

test('random returns different values', function () {
    $rotator = new UserAgentRotator();

    $agents = [];
    for ($i = 0; $i < 10; $i++) {
        $agents[] = $rotator->random();
    }

    // With multiple calls, we should get some variety (not all the same)
    $uniqueAgents = array_unique($agents);
    expect(count($uniqueAgents))->toBeGreaterThan(1);
});

test('next rotates through user agents sequentially', function () {
    $rotator = new UserAgentRotator();

    $first = $rotator->next();
    $second = $rotator->next();
    $third = $rotator->next();

    expect($first)->toBeString()
        ->and($second)->toBeString()
        ->and($third)->toBeString()
        ->and($first)->not->toBe($second)
        ->and($second)->not->toBe($third);
});

test('next cycles back to start after all agents', function () {
    $rotator = new UserAgentRotator();
    $count = $rotator->count();

    // Get all user agents in order
    $agents = [];
    for ($i = 0; $i < $count; $i++) {
        $agents[] = $rotator->next();
    }

    // Next one should be the first agent again (cycling)
    $nextAgent = $rotator->next();

    expect($nextAgent)->toBe($agents[0]);
});

test('all user agents are valid', function () {
    $rotator = new UserAgentRotator();

    foreach ($rotator->all() as $agent) {
        expect($agent)->toBeString()
            ->toContain('Mozilla')
            ->and(strlen($agent))->toBeGreaterThan(20);
    }
});

test('byPlatform filters Windows user agents', function () {
    $rotator = new UserAgentRotator();
    $windowsAgents = $rotator->byPlatform('windows');

    expect($windowsAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($windowsAgents as $agent) {
        expect($agent)->toContain('Windows NT');
    }
});

test('byPlatform filters macOS user agents', function () {
    $rotator = new UserAgentRotator();
    $macAgents = $rotator->byPlatform('macos');

    expect($macAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($macAgents as $agent) {
        expect($agent)->toContain('Macintosh');
    }
});

test('byPlatform filters Linux user agents', function () {
    $rotator = new UserAgentRotator();
    $linuxAgents = $rotator->byPlatform('linux');

    expect($linuxAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($linuxAgents as $agent) {
        expect($agent)->toContain('Linux')
            ->and($agent)->not->toContain('Android'); // Android should be excluded
    }
});

test('byPlatform filters Android user agents', function () {
    $rotator = new UserAgentRotator();
    $androidAgents = $rotator->byPlatform('android');

    expect($androidAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($androidAgents as $agent) {
        expect($agent)->toContain('Android');
    }
});

test('byPlatform filters iOS user agents', function () {
    $rotator = new UserAgentRotator();
    $iosAgents = $rotator->byPlatform('ios');

    expect($iosAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($iosAgents as $agent) {
        expect($agent)->toMatch('/iPhone|iPad/');
    }
});

test('byPlatform filters mobile user agents', function () {
    $rotator = new UserAgentRotator();
    $mobileAgents = $rotator->byPlatform('mobile');

    expect($mobileAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($mobileAgents as $agent) {
        expect($agent)->toMatch('/Mobile|Android|iPhone/');
    }
});

test('byBrowser filters Chrome user agents', function () {
    $rotator = new UserAgentRotator();
    $chromeAgents = $rotator->byBrowser('chrome');

    expect($chromeAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($chromeAgents as $agent) {
        expect($agent)->toContain('Chrome')
            ->and($agent)->not->toContain('Edg') // Not Edge
            ->and($agent)->not->toContain('OPR'); // Not Opera
    }
});

test('byBrowser filters Firefox user agents', function () {
    $rotator = new UserAgentRotator();
    $firefoxAgents = $rotator->byBrowser('firefox');

    expect($firefoxAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($firefoxAgents as $agent) {
        expect($agent)->toContain('Firefox');
    }
});

test('byBrowser filters Safari user agents', function () {
    $rotator = new UserAgentRotator();
    $safariAgents = $rotator->byBrowser('safari');

    expect($safariAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($safariAgents as $agent) {
        expect($agent)->toContain('Safari')
            ->and($agent)->not->toContain('Chrome'); // Pure Safari, not Chrome
    }
});

test('byBrowser filters Edge user agents', function () {
    $rotator = new UserAgentRotator();
    $edgeAgents = $rotator->byBrowser('edge');

    expect($edgeAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($edgeAgents as $agent) {
        expect($agent)->toContain('Edg');
    }
});

test('byBrowser filters Opera user agents', function () {
    $rotator = new UserAgentRotator();
    $operaAgents = $rotator->byBrowser('opera');

    expect($operaAgents)->toBeArray()
        ->not->toBeEmpty();

    foreach ($operaAgents as $agent) {
        expect($agent)->toContain('OPR');
    }
});

test('count returns correct number of user agents', function () {
    $rotator = new UserAgentRotator();

    expect($rotator->count())->toBe(count($rotator->all()))
        ->and($rotator->count())->toBeGreaterThan(30); // Should have 32 according to docs
});

test('user agents are realistic and current', function () {
    $rotator = new UserAgentRotator();

    // Check that we have recent browser versions
    $allAgentsString = implode(' ', $rotator->all());

    // Should have recent Chrome versions (130+)
    expect($allAgentsString)->toContain('Chrome/13')
        // Should have recent Firefox versions (130+)
        ->and($allAgentsString)->toContain('Firefox/13');

    // Should have recent Safari versions (17+ or 18+)
    $hasSafari17or18 = str_contains($allAgentsString, 'Version/17') ||
                       str_contains($allAgentsString, 'Version/18');
    expect($hasSafari17or18)->toBeTrue();
});
