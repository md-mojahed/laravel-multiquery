<?php

namespace Mojahed;

use Illuminate\Support\ServiceProvider;
use Mojahed\Binary\BinaryExecutor;
use Mojahed\Macros\BuilderMacro;
use Mojahed\Macros\CollectionMacro;

class MultiQueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/multiquery.php',
            'multiquery'
        );

        $this->app->singleton(MultiQueryManager::class, function ($app) {
            return new MultiQueryManager(
                executor: new BinaryExecutor(),
                config:   $app['config']['multiquery'],
            );
        });
    }

    public function boot(): void
    {
        // publish config
        $this->publishes([
            __DIR__ . '/../config/multiquery.php' => config_path('multiquery.php'),
        ], 'multiquery-config');

        // register mq() on all Builder instances
        BuilderMacro::register();

        // register fromMq() on all Collections
        CollectionMacro::register();
    }
}
