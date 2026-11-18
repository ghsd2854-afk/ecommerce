<?php

namespace App\Providers;

use App\Support\Performance;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide (shared) setup only. Module-specific setup lives in each
 * module's own provider (Modules/<Name>/Providers).
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Requirement 3: which optimizations are on (read once per request).
        $this->app->scoped(Performance::class);
    }

    public function boot(): void
    {
        $this->detectNPlusOne();

        RateLimiter::for('api', function (Request $request) {
            // 60 / minute (API_RATE_LIMIT). Only during a local k6 load test is it lifted
            // (php artisan perf:mode --load-test=on), otherwise k6 would measure 429 errors.
            return Limit::perMinute(Performance::apiRateLimit())->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Requirement 3: N+1 detector. When a relation is lazy loaded on a model that came from a
     * LIST of models (one query per model = N+1), Laravel calls this:
     *   - in the automated tests -> the test fails (every endpoint is checked by the test suite)
     *   - locally                -> a warning in storage/logs/laravel.log
     * Not active in production. Ignored in "perf:mode baseline", where N+1 is kept on purpose
     * to measure the difference.
     */
    private function detectNPlusOne(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation, LazyLoadingViolationException $e) {
            if (! Performance::on('eager_loading')) {
                return;
            }

            if ($this->app->runningUnitTests()) {
                throw $e;
            }

            Log::warning('N+1 query detected: '.$e->getMessage());
        });
    }
}
