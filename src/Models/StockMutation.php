<?php
namespace ArsoftModules\StockMutation\Models;

use Illuminate\Database\Eloquent\Model;

class StockMutation extends Model
{
    public function stock()
    {
        return $this->belongsTo(Stock::class,'stock_id','id');
    }
}