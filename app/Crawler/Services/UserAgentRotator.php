<?php

declare(strict_types=1);

namespace App\Crawler\Services;

/**
 * Rotating user agent service with realistic, up-to-date user agents.
 * Maintains a pool of common browser user agents from real users.
 */
class UserAgentRotator
{
    private array $userAgents;
    private int $currentIndex = 0;

    public function __construct()
    {
        $this->userAgents = $this->loadUserAgents();
        shuffle($this->userAgents);
    }

    /**
     * Get a random user agent from the pool.
     */
    public function random(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    /**
     * Get the next user agent in rotation.
     */
    public function next(): string
    {
        $userAgent = $this->userAgents[$this->currentIndex];
        $this->currentIndex = ($this->currentIndex + 1) % count($this->userAgents);
        return $userAgent;
    }

    /**
     * Get all user agents in the pool.
     *
     * @return array<string>
     */
    public function all(): array
    {
        return $this->userAgents;
    }

    /**
     * Get the total number of user agents.
     */
    public function count(): int
    {
        return count($this->userAgents);
    }

    /**
     * Load a comprehensive list of realistic user agents.
     *
     * @return array<string>
     */
    private function loadUserAgents(): array
    {
        return [
            // Chrome on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',

            // Chrome on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',

            // Chrome on Linux
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',

            // Firefox on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',

            // Firefox on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:133.0) Gecko/20100101 Firefox/133.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13.6; rv:133.0) Gecko/20100101 Firefox/133.0',

            // Firefox on Linux
            'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',

            // Safari on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Safari/605.1.15',

            // Edge on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',

            // Edge on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',

            // Mobile Chrome (Android)
            'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',

            // Mobile Safari (iOS)
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',

            // Opera
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/107.0.0.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/107.0.0.0',
        ];
    }

    /**
     * Get user agents filtered by platform.
     *
     * @param string $platform 'windows', 'macos', 'linux', 'android', 'ios', 'mobile'
     * @return array<string>
     */
    public function byPlatform(string $platform): array
    {
        return array_filter($this->userAgents, function ($ua) use ($platform) {
            return match (strtolower($platform)) {
                'windows' => str_contains($ua, 'Windows NT'),
                'macos' => str_contains($ua, 'Macintosh'),
                'linux' => str_contains($ua, 'Linux') && !str_contains($ua, 'Android'),
                'android' => str_contains($ua, 'Android'),
                'ios' => str_contains($ua, 'iPhone') || str_contains($ua, 'iPad'),
                'mobile' => str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone'),
                default => true,
            };
        });
    }

    /**
     * Get user agents filtered by browser.
     *
     * @param string $browser 'chrome', 'firefox', 'safari', 'edge', 'opera'
     * @return array<string>
     */
    public function byBrowser(string $browser): array
    {
        return array_filter($this->userAgents, function ($ua) use ($browser) {
            return match (strtolower($browser)) {
                'chrome' => str_contains($ua, 'Chrome') && !str_contains($ua, 'Edg') && !str_contains($ua, 'OPR'),
                'firefox' => str_contains($ua, 'Firefox'),
                'safari' => str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome'),
                'edge' => str_contains($ua, 'Edg'),
                'opera' => str_contains($ua, 'OPR'),
                default => true,
            };
        });
    }
}
