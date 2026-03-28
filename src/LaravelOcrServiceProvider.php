<?php

namespace Mayaram\LaravelOcr;

use Illuminate\Support\ServiceProvider;
use Mayaram\LaravelOcr\Services\OCRManager;
use Mayaram\LaravelOcr\Services\TemplateManager;
use Mayaram\LaravelOcr\Services\AICleanupService;
use Mayaram\LaravelOcr\Services\DocumentParser;
use Mayaram\LaravelOcr\Console\Commands\DoctorCommand;
use Mayaram\LaravelOcr\Console\Commands\CreateTemplateCommand;
use Mayaram\LaravelOcr\Console\Commands\ProcessDocumentCommand;

class LaravelOcrServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-ocr.php', 'laravel-ocr');

        $this->app->singleton('laravel-ocr', function ($app) {
            return new OCRManager($app);
        });
        $this->app->alias('laravel-ocr', OCRManager::class);

        $this->app->singleton('laravel-ocr.templates', function ($app) {
            return new TemplateManager();
        });

        $this->app->singleton('laravel-ocr.ai-cleanup', function ($app) {
            return new AICleanupService($app['config']);
        });

        $this->app->singleton('laravel-ocr.parser', function ($app) {
            return new DocumentParser(
                $app['laravel-ocr'],
                $app['laravel-ocr.templates'],
                $app['laravel-ocr.ai-cleanup']
            );
        });
        $this->app->alias('laravel-ocr.parser', DocumentParser::class);
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-ocr.php' => config_path('laravel-ocr.php'),
            ], 'laravel-ocr-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'laravel-ocr-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-ocr'),
            ], 'laravel-ocr-views');

            $this->commands([
                CreateTemplateCommand::class,
                ProcessDocumentCommand::class,
                DoctorCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-ocr');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
