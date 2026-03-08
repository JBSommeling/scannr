<?php

namespace App\Providers;

use App\Services\HttpChecker;
use App\Services\LinkExtractor;
use App\Services\LinkFlagService;
use App\Services\RobotsService;
use App\Services\ScannerService;
use App\Services\ScanStatistics;
use App\Services\SeverityEvaluator;
use App\Services\SitemapService;
use App\Services\UrlNormalizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('url-normalizer', function ($app) {
            return new UrlNormalizer;
        });

        $this->app->alias('url-normalizer', UrlNormalizer::class);

        $this->app->singleton('severity-evaluator', function ($app) {
            return new SeverityEvaluator;
        });

        $this->app->alias('severity-evaluator', SeverityEvaluator::class);

        $this->app->singleton('link-flag-service', function ($app) {
            return new LinkFlagService(
                $app->make('url-normalizer'),
                $app->make('severity-evaluator'),
            );
        });

        $this->app->alias('link-flag-service', LinkFlagService::class);

        $this->app->singleton('http-checker', function ($app) {
            return new HttpChecker(
                $app->make('url-normalizer'),
                $app->make('link-flag-service'),
            );
        });

        $this->app->alias('http-checker', HttpChecker::class);

        $this->app->singleton('link-extractor', function ($app) {
            return new LinkExtractor(
                $app->make('url-normalizer'),
                $app->make('http-checker'),
                $app->make('link-flag-service'),
            );
        });

        $this->app->alias('link-extractor', LinkExtractor::class);

        $this->app->singleton('scan-stats', function ($app) {
            return new ScanStatistics;
        });

        $this->app->alias('scan-stats', ScanStatistics::class);

        $this->app->singleton('scanner', function ($app) {
            return new ScannerService(
                $app->make('http-checker'),
                $app->make('link-extractor'),
                $app->make('url-normalizer'),
                $app->make('scan-stats'),
                $app->make('link-flag-service'),
            );
        });

        $this->app->alias('scanner', ScannerService::class);

        $this->app->singleton('sitemap', function ($app) {
            return new SitemapService(null, $app->make('url-normalizer'));
        });

        $this->app->alias('sitemap', SitemapService::class);

        $this->app->singleton('robots', function ($app) {
            return new RobotsService;
        });

        $this->app->alias('robots', RobotsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
