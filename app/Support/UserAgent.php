<?php

namespace App\Support;

class UserAgent
{
    /**
     * Short device description for the admin table, e.g. "Chrome · Windows".
     */
    public static function summary(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        // Order matters: Edge and Opera also contain "Chrome", Chrome contains "Safari".
        $browser = match (true) {
            str_contains($userAgent, 'Edg') => 'Edge',
            str_contains($userAgent, 'OPR') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => null,
        };

        // Android and iOS user agents also mention Linux / Mac OS X.
        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return implode(' · ', array_filter([$browser, $os])) ?: null;
    }
}
