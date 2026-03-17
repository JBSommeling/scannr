<?php

namespace Scannr;

use Scannr\Console\Commands\ScanSite;
use Scannr\Services\HttpChecker;
use Scannr\Services\LinkExtractor;
use Scannr\Services\LinkFlagService;
use Scannr\Services\RobotsService;
use Scannr\Services\ScannerService;
use Scannr\Services\ScanStatistics;
use Scannr\Services\SeverityEvaluator;
use Scannr\Services\SitemapService;
use Scannr\Services\UrlNormalizer;
use Illuminate\Support\ServiceProvider;

class ScannrServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scannr.php', 'scannr');

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
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanSite::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/scannr.php' => config_path('scannr.php'),
            ], 'scannr-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'scannr-migrations');
        }
    }
}
