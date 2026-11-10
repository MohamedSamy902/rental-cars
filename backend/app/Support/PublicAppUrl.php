<?php

namespace App\Support;

/**
 * Absolute origin for links a phone must open (vehicle QR visitor card).
 *
 * The value comes from PUBLIC_APP_URL, then from APP_URL when that host is
 * not loopback. Request Host and X-Forwarded-* are never consulted, so a
 * client cannot point the QR at an arbitrary site.
 */
final class PublicAppUrl
{
    public static function to(string $pathAndQuery): string
    {
        $origin = self::origin();
        $pathAndQuery = ltrim($pathAndQuery, '/');

        return $pathAndQuery === '' ? $origin : $origin.'/'.$pathAndQuery;
    }

    public static function origin(): string
    {
        $configured = self::normalize((string) config('app.public_url', ''));
        if ($configured !== null) {
            return $configured;
        }

        $app = self::normalize((string) config('app.url', ''));
        if ($app !== null && ! self::isLoopback($app)) {
            return $app;
        }

        return $app ?? 'http://localhost';
    }

    private static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || strpbrk($host, " \t\r\n/@") !== false) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return $scheme.'://'.$host.$port.$path;
    }

    private static function isLoopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true);
    }
}
