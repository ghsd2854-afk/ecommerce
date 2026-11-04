<?php

namespace Modules\Users\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;
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

       Gate::define('admin', fn ($user) => $user->isAdmin());
    }
}
