<?php

namespace Tests\Unit;

use App\Support\UserAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function agents(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'Chrome · Windows'],
            'edge windows' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0', 'Edge · Windows'],
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox · Linux'],
            'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1', 'Safari · iOS'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', 'Chrome · Android'],
            'safari macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Safari · macOS'],
            'unknown' => ['curl/8.0', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('agents')]
    public function test_summary(?string $userAgent, ?string $expected): void
    {
        $this->assertSame($expected, UserAgent::summary($userAgent));
    }
}
