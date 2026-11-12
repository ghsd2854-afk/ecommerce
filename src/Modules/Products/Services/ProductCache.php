<?php

namespace Modules\Products\Services;

use App\Support\Performance;
use App\Support\ReadYourWrites;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Layer 2 of Requirement 3: Redis cache for the product catalog (cache-aside).
 *
 * WHAT is cached (read a lot, changes rarely, the same for every user):
 *   - product list pages (per filters + page)   TTL performance.ttl.product_list
 *   - one product's data (name, price, ...)     TTL performance.ttl.product
 *   - the list of categories                    TTL performance.ttl.categories
 * WHAT is NOT cached:
 *   - stock quantities: they change with every order and must be exact
 *     (always asked live from the inventory service over gRPC)
 *   - prices used to CREATE an order: read from the primary database at that moment
 *   - anything personal (orders, notifications, tokens)
 *
 * INVALIDATION: every key contains a version number "products:v<N>:...".
 * Any product create / update / delete changes the version, so ALL old entries are
 * ignored at once (and expire by their TTL). The next read rebuilds them from the
 * database. This is simpler and safer than finding and deleting every list page that
 * might contain the changed product.
 *
 * Redis is an optimization, not a dependency: if it is down, the data is read from
 * the database and the API keeps working.
 */
class ProductCache
{
    private const VERSION_KEY = 'products:version';

    /** Read-through: return the cached value, or build it, cache it and return it. */
    public function remember(string $kind, string $key, Closure $build): mixed
    {
        if (! Performance::on('cache')) {
            return $build();
        }

        try {
            $store = Performance::store();
            $fullKey = 'products:v'.$this->version().':'.$kind.':'.$key;

            return $store->remember($fullKey, (int) config("performance.ttl.$kind", 60), $build);
        } catch (Throwable $e) {
            report($e);

            return $build();
        }
    }

    /** Call after EVERY write to products. */
    public function invalidate(): void
    {
        // Until the replica has this write, product reads (and cache rebuilds) use the primary,
        // so the cache is never refilled with an old copy from a replica that is behind.
        ReadYourWrites::remember('products');

        try {
            Performance::store()->forever(self::VERSION_KEY, Str::random(12));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function version(): string
    {
        return (string) Performance::store()->get(self::VERSION_KEY, '0');
    }
}
