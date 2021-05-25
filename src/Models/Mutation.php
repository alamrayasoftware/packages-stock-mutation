<?php
namespace ArsoftModules\StockMutation\Models;

use Illuminate\Database\Eloquent\Model;

class Mutation extends Model
{
    protected $table = 't_in_stock_mutation';
    protected $primaryKey = 'id';

    public function stock()
    {
        return $this->belongsTo(Stock::class,'stock_id','id');
    }
}