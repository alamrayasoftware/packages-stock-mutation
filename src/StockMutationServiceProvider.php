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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('stockmutation', function() {
            return new StockMutation();
        });
      
    }
}