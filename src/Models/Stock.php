<?php
namespace ArsoftModules\StockMutation\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $table = 't_in_stock';
    protected $primaryKey = 'id';
}