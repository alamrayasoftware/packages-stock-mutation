<?php

namespace ArsoftModules\StockMutation;

use ArsoftModules\StockMutation\StockMutation;
use Illuminate\Support\ServiceProvider;

class StockMutationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations')
        ], 'migrations');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('stockmutation', function() {
            return new StockMutation();
        });      
    }
}