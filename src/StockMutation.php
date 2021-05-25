<?php

namespace ArsoftModules\StockMutation;

use ArsoftModules\StockMutation\Models\Mutation;
use ArsoftModules\StockMutation\Models\Stock;
use Exception;
use Illuminate\Support\Facades\DB;
use stdClass;

class StockMutation
{
    private $status = 'success', $data, $errorMessage;
    public function getStatus()
    {
        return $this->status;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * @param int $item id item-data
     * @param string $position position item / warehouse
     * @param string $qty quantity
     * @param string $company id company
     * @param string $refrence refrence of mutation in
     * @param string $hpp value of cost of goods sold
     * @param string $note description
     */
    public function mutationIn(
        int $item,
        int $position,
        int $qty,
        String $company = null,
        String $refrence = null,
        String $hpp = null,
        String $note = ''
    ) {
        DB::BeginTransaction();
        try {

            $stock = Stock::where('company_id', $company)->where('item_id', $item)->where('position', $position)->first();
            if (!$stock) {
                $stock = new Stock();
                $stock->qty = $qty;
            } else {
                $stock->qty = (int) $stock->qty + $qty;
            }
            $stock->company_id = $company;
            $stock->item_id = $item;
            $stock->position = $position;
            $stock->save();



            $mutation = new Mutation();
            $mutation->stock_id = $stock->id;
            $mutation->date = date("Y-m-d");
            $mutation->qty = $qty;
            $mutation->used = 0;
            $mutation->reference = $refrence;
            $mutation->hpp = $hpp;
            $mutation->type = 'in';
            $mutation->note = $note;
            $mutation->save();

            $tempData = new stdClass();
            $tempData->curent_stock = $stock->qty;


            $this->data = $tempData;
            Db::commit();
            return $this;
        } catch (\Throwable $th) {
            DB::rollBack();
            $this->status = 'error';
            $this->errorMessage = $th->getMessage();
            return $this;
        }
    }

    /**
     * @param int $item id item-data
     * @param string $position position item / warehouse
     * @param string $qty quantity
     * @param string $company id company
     * @param string $refrence refrence of mutation in
     * @param string $hpp value of cost of goods sold
     * @param string $note description
     */
    public function mutationOut(
        int $item,
        int $position,
        int $qty,
        String $company = null,
        String $refrence = null,
        String $hpp = null,
        String $note = null
    ) {
        DB::beginTransaction();
        try {
            $stock = Stock::where('company_id', $company)->where('item_id', $item)->where('position', $position)->first();

            $mutation  = Mutation::where('stock_id', $stock->id)
                ->whereRaw('(qty - used) > 0')
                ->where('type', 'in')
                ->select(DB::raw('sum(qty - used) as total_qty'), 'id', 'qty', 'used', 'stock_id')
                ->groupBy('id', 'qty', 'used', 'stock_id')
                ->oldest()
                ->with('stock')
                ->get();
            $updateQty = $qty;
            $currentStock = $stock->qty;
            if ($stock->qty < $qty) {
                return throw new Exception("Quantity not available", 400);
            }
            foreach ($mutation as $value) {


                $valQty = $value->total_qty;
                if ($updateQty > 0) {
                    $usedQty = 0;
                    if ($valQty > $updateQty) {
                        $usedQty = $updateQty;
                    } else {
                        $usedQty = $value->qty;
                    }

                    $updateMutation =  Mutation::where('id', $value->id)->first();
                    $updateMutation->used += $usedQty;
                    $updateMutation->update();

                    $mutation = new Mutation();
                    $mutation->stock_id = $stock->id;
                    $mutation->date = date("Y-m-d");
                    $mutation->qty = $usedQty;
                    $mutation->used = 0;
                    $mutation->mutation_reference = $updateMutation->trx_reference;
                    $mutation->trx_reference = $refrence;
                    $mutation->hpp = $hpp;
                    $mutation->type = 'out';
                    $mutation->note = $note;
                    $mutation->save();
                    $currentStock = $currentStock - $usedQty;
                    $updateQty = $updateQty - $valQty;
                }
            }
            $stock->qty = $currentStock;
            $stock->update();

            $tempData = new stdClass();
            $tempData->curent_stock = $stock->qty;
            $this->data = $tempData;
            DB::commit();
            return $this;
        } catch (\Throwable $th) {
            DB::rollBack();
            $this->status = 'error';
            $this->errorMessage = $th->getMessage();
            return $this;
        }
    }
}
