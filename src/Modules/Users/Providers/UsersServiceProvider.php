<?php

namespace Modules\Users\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Users\Contracts\UserDirectory;
use Modules\Users\Services\EloquentUserDirectory;

class UsersServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();

        // Public permission other modules use instead of reading the user's role:
        //   Gate::allows('admin')  /  $request->user()->can('admin')
        Gate::define('admin', fn ($user) => $user->isAdmin());

        // Login rate limiting, per ACCOUNT:
        //  - 5 attempts per minute for the same email from the same address. Only the
        //    account being attacked is blocked; other users keep logging in normally,
        //    even from the same network.
        //  - 30 attempts per minute per address, so one client cannot try many
        //    different accounts one after another.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login-account:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-address:'.$request->ip()),
            ];
        });
    }
}
