<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which Requirement-3 optimizations are switched on.
 *
 * Normally ALL of them are on. "php artisan perf:mode baseline" switches them off so
 * the SAME code can be measured before and after (measure -> improve ONE thing -> measure):
 *
 *   cache          Redis cache of products / categories / sales report      (layer 2)
 *   etag           ETag + 304 Not Modified                                  (layer 1)
 *   eager_loading  orders are loaded with their items in 2 queries, not N+1 (layer 3)
 *   indexes        the performance indexes exist (changed with migrations)  (layer 3)
 *   load_test      raises the API rate limit while k6 runs (local only)
 *
 * The state is kept in Redis (key "performance:mode"), so switching does not need a restart.
 * If Redis is not reachable, everything is simply ON (the safe, normal behaviour).
 */
final class Performance
{
    public const FEATURES = ['cache', 'etag', 'eager_loading', 'indexes'];

    private const KEY = 'performance:mode';

    /** Loaded once per request. */
    private ?array $state = null;

    public static function on(string $feature): bool
    {
        return (bool) (app(self::class)->state()[$feature] ?? true);
    }

    public static function store(): Repository
    {
        return Cache::store(config('performance.cache_store'));
    }

    public static function defaults(): array
    {
        return array_fill_keys(self::FEATURES, true) + ['load_test' => false];
    }

    public function state(): array
    {
        if ($this->state === null) {
            try {
                $saved = self::store()->get(self::KEY);
            } catch (Throwable) {
                $saved = null;
            }

            $this->state = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        }

        return $this->state;
    }

    public function save(array $state): void
    {
        $this->state = array_merge(self::defaults(), array_intersect_key($state, self::defaults()));
        self::store()->forever(self::KEY, $this->state);
    }

    /** Changes a feature for THIS process only (used by perf:nplus1, not saved). */
    public function override(string $feature, bool $on): void
    {
        $this->state();
        $this->state[$feature] = $on;
    }

    /** API requests per minute. Only in APP_ENV=local can a load test lift the limit. */
    public static function apiRateLimit(): int
    {
        if (self::on('load_test') && app()->environment('local')) {
            return 1_000_000;
        }

        return (int) config('performance.api_rate_limit', 60);
    }
}
