<?php

namespace App\Providers;

use App\Auth\IdentityAuthenticator;
use App\Auth\SessionVault;
use App\Models\User;
use App\Services\AccessManager;
use App\Support\IndustryVocabulary;
use App\Support\PermissionCatalog;
use App\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenancyManager::class);
        $this->app->singleton(IndustryVocabulary::class);
        $this->app->singleton(IdentityAuthenticator::class);
        $this->app->singleton(SessionVault::class);
    }

    public function boot(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Gate::define($permission['key'], function (User $user, mixed $record = null) use ($permission) {
                return app(AccessManager::class)->allows($user, $permission['key'], $record);
            });
        }
    }
}
