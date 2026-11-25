<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * كاش قراءة قصير العمر (file/database) لمسارات العدّ/الملخص — بلا Redis.
 * إبطال عبر جيل مشترك عند أي كتابة تؤثر على العدّادات.
 */
final class PerfReadCache
{
    public const TTL_SECONDS = 45;

    private const GEN_KEY = 'perf:read_gen';

    public static function enabled(): bool
    {
        if (app()->environment('testing') && ! (bool) config('rpm.perf_cache_in_tests', false)) {
            return false;
        }

        return (bool) config('rpm.perf_read_cache', true);
    }

    public static function generation(): int
    {
        return (int) Cache::get(self::GEN_KEY, 0);
    }

    public static function bump(): void
    {
        if (! self::enabled() && ! app()->environment('testing')) {
            // ما زال bump آمناً إن عُطّل الكاش — لا يضر.
        }

        try {
            Cache::forever(self::GEN_KEY, self::generation() + 1);
        } catch (\Throwable) {
            // كاش غير متاح (هجرة أولى) — تجاهل.
        }
    }

    /**
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function remember(string $suffix, callable $callback): mixed
    {
        if (! self::enabled()) {
            return $callback();
        }

        $key = 'perf:'.$suffix.':v'.self::generation();

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }
}
