<?php

namespace ArsoftModules\StockMutation\Facades;

use Illuminate\Support\Facades\Facade;

class StockMutation extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'stockmutation';
    }
}