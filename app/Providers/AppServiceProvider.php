<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CLAUDE.md §12/§17: HTTPS in all non-local environments. Forces
        // every generated URL (route(), asset(), the manifest link tag,
        // etc.) to https:// even if a reverse proxy in front of the app
        // terminates SSL and forwards plain HTTP internally.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
