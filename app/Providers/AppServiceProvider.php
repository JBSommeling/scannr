<?php

namespace App\Providers;

use App\Services\ScannerService;
use App\Services\SitemapService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('scanner', function ($app) {
            return new ScannerService();
        });

        $this->app->alias('scanner', ScannerService::class);

        $this->app->singleton('sitemap', function ($app) {
            return new SitemapService(null, $app->make('scanner'));
        });

        $this->app->alias('sitemap', SitemapService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
