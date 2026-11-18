<?php

/*
|--------------------------------------------------------------------------
| Requirement 3: performance settings
|--------------------------------------------------------------------------
| The four layers:
|   1. Browser HTTP cache  -> ETag / If-None-Match -> 304 (App\Http\Middleware\ETag)
|   2. Server cache        -> Redis, with a TTL per kind of data (below)
|   3. Database            -> indexes + no N+1 (module migrations, eager loading)
|   4. Read replica        -> config/database.php (read / write / sticky)
|                             + App\Support\ReadYourWrites (GTID check)
*/

return [

    // Cache store used for the application data cache (products, reports).
    // "redis" in Docker; the tests use "array" (phpunit.xml).
    'cache_store' => env('PERFORMANCE_CACHE_STORE', 'redis'),

    // How long each kind of data may stay in the cache (seconds).
    // Every write ALSO invalidates the products cache immediately (version key),
    // so the TTL is only the upper limit for data changed outside the API.
    'ttl' => [
        'product_list' => (int) env('CACHE_TTL_PRODUCT_LIST', 60),
        'product' => (int) env('CACHE_TTL_PRODUCT', 300),
        'categories' => (int) env('CACHE_TTL_CATEGORIES', 600),
        'sales_report' => (int) env('CACHE_TTL_SALES_REPORT', 60),
    ],

    // Read-your-writes (App\Support\ReadYourWrites): after a write, reads of the same data
    // go to the PRIMARY until the replica has applied that write (checked exactly with the
    // write's GTID). This is the maximum time the check is kept (replica stopped / far behind).
    'read_your_writes_seconds' => (int) env('READ_YOUR_WRITES_SECONDS', 60),

    // Rate limit of the API (requests per minute per user / IP).
    'api_rate_limit' => (int) env('API_RATE_LIMIT', 60),
];
