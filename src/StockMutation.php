<?php

namespace ArsoftModules\StockMutation;

use ArsoftModules\StockMutation\Models\Mutation;
use ArsoftModules\StockMutation\Models\Stock;
use Exception;
use Illuminate\Support\Carbon;
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
     * @param string $itemId item id
     * @param string $position item position  / warehouse id
     * @param int $qty quantity
     * @param string $date transaction date, format: Y-m-d
     * @param int $hpp cost of goods solds
     * @param string $reference nota / reference number / transaction number
     * @param string $companyId company id
     * @param string $expiredDate expired date
     * @param string $note description
     */
    public function mutationIn(
        string $itemId,
        string $position,
        int $qty,
        string $date,
        int $hpp = 0,
        string $reference,
        string $companyId = null,
        string $expiredDate = null,
        string $note = null
    ) {
        DB::BeginTransaction();
        try {

            $date = Carbon::parse($date);

            $stock = Stock::where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->where('position', $position);

            if ($expiredDate) {
                $expiredDate = Carbon::parse($expiredDate);
                $stock = $stock->whereDate('expired_date', $expiredDate);
            } else {
                $stock = $stock->whereNull('expired_date');
            }

            $stock = $stock->first();

            if (!$stock) {
                $stock = new Stock();
                $stock->company_id = $companyId;
                $stock->item_id = $itemId;
                $stock->position = $position;
                $stock->qty = $qty;
                $stock->expired_date = $expiredDate;
            } else {
                $stock->qty += $qty;
            }
            $stock->save();

            $mutation = new Mutation();
            $mutation->stock_id = $stock->id;
            $mutation->date = $date;
            $mutation->qty = $qty;
            $mutation->used = 0;
            $mutation->trx_reference = $reference;
            $mutation->hpp = $hpp;
            $mutation->type = 'in';
            $mutation->note = $note;
            $mutation->save();

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

    /**
     * @param string $itemId item id
     * @param string $position item position  / warehouse id
     * @param int $qty quantity
     * @param string $date transaction date, format: Y-m-d
     * @param string $companyId company id
     * @param string $reference nota / reference number / transaction number
     * @param string $note description
     */
    public function mutationOut(
        string $itemId,
        string $position,
        int $qty,
        string $date,
        String $companyId = null,
        String $reference = null,
        String $note = null
    ) {
        DB::beginTransaction();
        try {
            $date = Carbon::parse($date);

            $stock = Stock::where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->where('position', $position)
                ->where('qty', '>', 0)
                ->get();

            if (count($stock) < 0) {
                throw new Exception("Stock not found !", 404);
            }

            $listStockId = $stock->pluck('id')->all();
            $mutations = Mutation::whereIn('stock_id', $listStockId)
                ->whereRaw('(qty - used) > 0')
                ->where('type', 'in')
                ->select(DB::raw('sum(qty - used) as remaining_qty'), 'id', 'qty', 'used', 'stock_id')
                ->with('stock')
                ->groupBy('id', 'qty', 'used', 'stock_id')
                ->oldest()
                ->get();
                
            $requestedQty = $qty;
            $currentStock = $stock->sum('qty');
            if ($currentStock < $requestedQty) {
                throw new Exception("Insufficient stock !", 400);
            }
            foreach ($mutations as $mutation) {
                $usedQty = 0;
                if ($requestedQty < $mutation->remaining_qty) {
                    $usedQty = $requestedQty;
                } else {
                    $usedQty = $mutation->remaining_qty;
                }
                
                $updateMutation = Mutation::where('id', $mutation->id)->first();
                $updateMutation->used += $usedQty;
                $updateMutation->update();

                $updateStock = Stock::where('id', $updateMutation->stock_id)->first();
                $updateStock->qty -= $usedQty;
                $updateStock->update();

                $newMutation = new Mutation();
                $newMutation->stock_id = $updateMutation->stock_id;
                $newMutation->date = $date;
                $newMutation->qty = $usedQty;
                $newMutation->used = 0;
                $newMutation->mutation_reference_id = $updateMutation->id;
                $newMutation->trx_reference = $reference;
                $newMutation->type = 'out';
                $newMutation->note = $note;
                $newMutation->save();

                $requestedQty -= $usedQty;
                
                if ($requestedQty <= 0) {
                    break;
                }
            }

            $tempData = new stdClass();
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

    public function rollBack(String $reference)
    {
        DB::beginTransaction();
        try {
            $mutation = Mutation::where('trx_reference', $reference)->get();
            if (count($mutation) < 1) {
                throw new Exception("Data not found", 400);
            }

            foreach ($mutation as  $value) {
                if ($value->type == 'out') {
                    // update mutation where value of trx_reference
                    $trxReference = Mutation::where('id', $value->mutation_reference_id)->first();
                    if (!$trxReference) {
                        throw new Exception("Error data mutation reference not found", 400);
                    }
                    $trxReference->used = ($trxReference->used - $value->qty);
                    $trxReference->update();

                    // update curent stock
                    $stock = Stock::where('id', $value->stock_id)->first();
                    $stock->qty = ($stock->qty + $value->qty);
                    $stock->update();
                } else if ($value->type == 'in') {
                    $checkMutation = Mutation::where('mutation_reference_id', $value->id)->exists();
                    if ($checkMutation) {
                        throw new Exception("Error data mutation reference cannot rollback", 400);
                    }
                     // update curent stock
                    $stock = Stock::where('id', $value->stock_id)->first();
                    $stock->qty = ($stock->qty - $value->qty);
                    $stock->update();
                }

                // delete mutation 
                $mutationReference = Mutation::where('id', $value->id)->delete();
                if (!$mutationReference) {
                    throw new Exception("Error data mutation rollback not available", 400);
                }
            }
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
