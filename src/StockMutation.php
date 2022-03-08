<?php

namespace ArsoftModules\StockMutation;

use ArsoftModules\StockMutation\Models\StockMutation as Mutation;
use ArsoftModules\StockMutation\Models\Stock;
use ArsoftModules\StockMutation\Models\StockPeriod;
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
     * @param int $itemId item id
     * @param int $position item position / warehouse id
     * @param float $qty quantity, exp: 12000.57
     * @param string $date transaction date, format: Y-m-d
     * @param float $hpp cost of goods solds, exp: 12000.57
     * @param string $reference nota / reference number / transaction number
     * @param int $companyId company id
     * @param string $expiredDate expired date, format: Y-m-d
     * @param string $note description
     */
    public function mutationIn(
        int $itemId,
        int $position,
        float $qty,
        string $date,
        float $hpp = 0,
        string $reference = null,
        int $companyId = null,
        string $expiredDate = null,
        string $note = null
    ) {
        DB::BeginTransaction();
        try {

            $date = Carbon::parse($date);

            $stock = Stock::where('company_id', $companyId)
                ->where('position_id', $position)
                ->where('item_id', $itemId);

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
                $stock->position_id = $position;
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

            $syncStockPeriod = $this->syncStockPeriod($stock->id, $date);
            if ($syncStockPeriod['status'] != 'success') {
                throw new Exception($syncStockPeriod['message'], 400);
            }
            $tempData = new stdClass();
            $tempData->model = $mutation;
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
     * @param int $itemId item id
     * @param int $position item position  / warehouse id
     * @param float $qty quantity, exp: 12000.57
     * @param string $date transaction date, format: Y-m-d || Carbon::class
     * @param int $companyId company id
     * @param string $reference nota / reference number / transaction number
     * @param string $note description
     * @param bool|null $allowMinus allow stock qty to a minus value
     * @param string $costType cost type : FIFO / LIFO
     */
    public function mutationOut(
        int $itemId,
        int $position,
        float $qty,
        string $date,
        int $companyId = null,
        string $reference = null,
        string $note = null,
        $allowMinus = false,
        $costType = 'FIFO'
    ) {
        DB::beginTransaction();
        try {
            $date = Carbon::parse($date);

            $stocks = Stock::where('company_id', $companyId)
                ->where('position_id', $position)
                ->where('item_id', $itemId);

            if (!$allowMinus) {
                $stocks = $stocks->where('qty', '>', 0)->get();
                if (count($stocks) <= 0) {
                    throw new Exception("Stock not found !", 404);
                }
            } else {
                $stocks = $stocks->get();
                if (count($stocks) <= 0) {
                    // create new stock
                    $stock = new Stock();
                    $stock->company_id = $companyId;
                    $stock->item_id = $itemId;
                    $stock->position_id = $position;
                    $stock->qty = 0;
                    $stock->save();
                    // get list stock
                    $stocks = Stock::where('company_id', $companyId)
                        ->where('position_id', $position)
                        ->where('item_id', $itemId)
                        ->get();
                }
            }

            $currentStock = $this->getCurrentStock($itemId, $companyId, $position, null, null);

            // validate allow-minus qty
            $requestedQty = $qty;
            if ($currentStock < $requestedQty && !$allowMinus) {
                throw new Exception("Insufficient stock !", 400);
            }

            $listStockId = $stocks->pluck('id')->all();
            $mutations = Mutation::whereIn('stock_id', $listStockId);
            // validate allow-minus qty
            if (!$allowMinus) {
                $mutations = $mutations->whereRaw('(qty - used) > 0');
            }
            $mutations = $mutations->where('type', 'in')
                ->select(DB::raw('sum(qty - used) as remaining_qty'), 'id', 'qty', 'used', 'stock_id')
                ->with('stock')
                ->groupBy('id', 'qty', 'used', 'stock_id');

            // condition cost type, FIFO/LIFO
            if ($costType == 'FIFO') {
                $mutations = $mutations->oldest();
            } else {
                $mutations = $mutations->latest();
            }

            $mutations = $mutations->get();

            $cogmValue = 0;
            $listModelMutation = [];
            foreach ($mutations as $mutation) {
                $usedQty = 0;
                if ($requestedQty < $mutation->remaining_qty || $allowMinus) {
                    $usedQty = $requestedQty;
                } else {
                    $usedQty = $mutation->remaining_qty;
                }

                $updateMutation = Mutation::where('id', $mutation->id)->first();
                $updateMutation->used += $usedQty;
                $updateMutation->update();

                $cogmValue += ( $updateMutation->hpp * $usedQty );

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
                $newMutation->hpp = $updateMutation->hpp;
                $newMutation->type = 'out';
                $newMutation->note = $note;
                $newMutation->save();
                array_push($listModelMutation,$newMutation);
                $requestedQty -= $usedQty;

                if ($requestedQty <= 0) {
                    break;
                }
            }
            foreach ($listStockId as $key => $stockId) {
                $syncStockPeriod = $this->syncStockPeriod($stockId, $date);
                if ($syncStockPeriod['status'] != 'success') {
                    throw new Exception($syncStockPeriod['message'], 400);
                }
            }
            $tempData = new stdClass();
            $tempData->cogm = $cogmValue;
            $tempData->model = $listModelMutation;
            
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
     * @param string $reference nota / reference number / transaction number
     */
    public function rollBack(String $reference)
    {
        DB::beginTransaction();
        try {
            $mutation = Mutation::where('trx_reference', $reference)->get();
            if (count($mutation) < 1) {
                // nota not found
                DB::commit();
                return $this;
            }

            foreach ($mutation as $value) {
                if ($value->type == 'out') {
                    // update mutation where value of trx_reference
                    $trxReference = Mutation::where('id', $value->mutation_reference_id)->first();
                    if (!$trxReference) {
                        throw new Exception("Mutation reference not found !", 404);
                    }
                    $trxReference->used = ($trxReference->used - $value->qty);
                    $trxReference->update();

                    // update curent stock
                    $stock = Stock::where('id', $value->stock_id)->first();
                    $stock->qty = ($stock->qty + $value->qty);
                    $stock->update();
                } else if ($value->type == 'in') {
                    // check reference mutation
                    $referenceMutation = Mutation::where('mutation_reference_id', $value->id)->first();
                    if ($referenceMutation) {
                        throw new Exception("Mutation is already used by ( " . $referenceMutation->trx_reference . " )", 403);
                    }
                    // update curent stock
                    $stock = Stock::where('id', $value->stock_id)->first();
                    $stock->qty = ($stock->qty - $value->qty);
                    $stock->update();
                }

                // delete mutation 
                $mutationReference = Mutation::where('id', $value->id)->delete();
                if (!$mutationReference) {
                    throw new Exception("Delete mutation failed !", 500);
                }
            }
            $syncStockPeriod = $this->syncStockPeriod($mutation[0]->stock_id, $mutation[0]->date);
            if ($syncStockPeriod['status'] != 'success') {
                throw new Exception($syncStockPeriod['message'], 400);
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

    /**
     * @param string $type mutation type, option 'in', 'out'
     * @param int $itemId item id
     * @param int $position item position / warehouse id
     * @param int $companyId company id
     * @param string $reference nota / reference number / transaction number
     * @param string $dateStart start date, format: dd/mm/yyyy
     * @param string $dateEnd end date, format: dd/mm/yyyy
     */

    public function history(
        string $type = 'in',
        int $itemId = null,
        int $position = null,
        int $companyId = null,
        string $reference = null,
        string $dateStart = null,
        string $dateEnd = null
    ) {
        try {
            if ($dateStart) {
                $dateStart = $this->formatDate($dateStart);
                if (!$dateStart) {
                    throw new Exception('Format date invalid, please use dd/mm/yyyy');
                }
            }
            if ($dateEnd) {
                $dateEnd = $this->formatDate($dateEnd);
                if (!$dateEnd) {
                    throw new Exception('Format date invalid, please use dd/mm/yyyy');
                }
            }

            $mutation = Mutation::where('type', $type)
                ->whereHas('stock', function ($q) use ($itemId, $position, $companyId, $dateStart, $dateEnd) {
                    if ($itemId != null) {
                        $q->where('item_id', $itemId);
                    }
                    if ($position != null) {
                        $q->where('position_id', $position);
                    }
                    if ($companyId != null) {
                        $q->where('company_id', $companyId);
                    }
                    if ($dateStart != null && $dateEnd != null) {
                        $q->whereBetween('stock_mutations.created_at', [$dateStart, $dateEnd]);
                    }
                });

            if ($reference != null) {
                $mutation = $mutation->where('trx_reference', $reference);
            }

            $mutation = $mutation->with('stock')
                ->get();

            $this->data = $mutation;

            return $this;
        } catch (\Throwable $th) {
            $this->status = 'error';
            $this->errorMessage = $th->getMessage();
            return $this;
        }
    }

    /**
     * @param int $stockId stock id
     * @param string $period date period, date_format: Y-m-d
     */
    public function syncStockPeriod($stockId, $period)
    {
        try {
            $period = Carbon::parse($period);

            $totalMutationIn = Mutation::where('stock_id', $stockId)
                ->whereDate('date', $period)
                ->where('type', 'in')
                ->sum('qty');

            $totalMutationOut = Mutation::where('stock_id', $stockId)
                ->whereDate('date', $period)
                ->where('type', 'out')
                ->sum('qty');

            $openingStock = 0;
            $stockPeriodPrev = StockPeriod::where('stock_id', $stockId)
                ->whereDate('period', '<', $period)
                ->latest('period')
                ->first();

            if ($stockPeriodPrev) {
                $openingStock = $stockPeriodPrev->closing_stock;
            }

            $stockPeriod = StockPeriod::where('stock_id', $stockId)
                ->whereDate('period', $period)
                ->first();

            if (!$stockPeriod) {
                $stockPeriod = new StockPeriod();
                $stockPeriod->stock_id = $stockId;
                $stockPeriod->period = $period;
            }

            $stockPeriod->opening_stock = $openingStock;
            $stockPeriod->total_stock_in = $totalMutationIn;
            $stockPeriod->total_stock_out = $totalMutationOut;
            $stockPeriod->closing_stock = $openingStock + ($totalMutationIn - $totalMutationOut);
            $stockPeriod->save();

            // new: next period
            $nextPeriod = Mutation::where('stock_id', $stockId)
                ->whereDate('date', '>', $period)
                ->orderBy('date', 'ASC')
                ->first();
            if ($nextPeriod) {
                $syncStockPeriod = $this->syncStockPeriod($stockId, $nextPeriod->date);
                if ($syncStockPeriod['status'] != 'success') {
                    throw new Exception($syncStockPeriod['message'], 400);
                }
            }

            return [
                'status' => 'success'
            ];
        } catch (\Throwable $th) {
            return [
                'status' => 'error',
                'message' => $th->getMessage()
            ];
        }
    }

    /**
     * @param int $itemId item id
     * @param int $position item position / warehouse id
     * @param int $companyId company id
     * @param sring $expiredDate selected expired date, format: Y-m-d
     * @param sring $date selected date, format: Y-m-d || Carbon::class
     */
    public function currentStock(
        $itemId = null,
        $companyId = null,
        $positionId = null,
        $expiredDate = null,
        $date = null
    ) {
        try {
            $totalStock = $this->getCurrentStock($itemId, $companyId, $positionId, $expiredDate, $date);

            $tempData = new stdClass();
            $tempData->curent_stock = $totalStock;
            $tempData->list_stock = [];

            $this->data = $tempData;
            return $this;
        } catch (\Throwable $th) {
            $this->status = 'error';
            $this->errorMessage = $th->getMessage();
            return $this;
        }
    }

    /**
     * get current stock qty
     * 
     * @param int $itemId item id
     * @param int $companyId company id
     * @param int $positionId item position / warehouse id
     * @param sring $expiredDate selected expired date, format: Y-m-d || Carbon::class
     * @param sring $date selected date, format: Y-m-d || Carbon::class, default: now()
     * 
     * @return float $qty
     */
    private function getCurrentStock(
        $itemId = null,
        $companyId = null,
        $positionId = null,
        $expiredDate = null,
        $date = null
    )
    {
        $totalStock = 0;

        $stock = Stock::orderBy('expired_date');
        // filter item-id
        if ($itemId) {
            $stock = $stock->where('item_id', $itemId);
        } else {
            $stock = $stock->whereNull('item_id');
        }
        // filter company
        if ($companyId) {
            $stock = $stock->where('company_id', $companyId);
        } else {
            $stock = $stock->whereNull('company_id');
        }
        // filter position
        if ($positionId) {
            $stock = $stock->where('position_id', $positionId);
        } else {
            $stock = $stock->whereNull('position_id');
        }
        // filter expired date
        if ($expiredDate) {
            $expiredDate = now()->parse($expiredDate);
            $stock = $stock->whereDate('expired_date', $expiredDate);
        } else {
            $stock = $stock->whereNull('expired_date');
        }
        // retrive data
        $stock = $stock->first();

        if ($stock) {
            // old
            $totalStock = $stock->qty;

            // // new ( unused for now )
            // $date = now()->parse($date ?? now());
            // $currentStockPeriod = StockPeriod::where('stock_id', $stock->id)
            //     ->whereDate('period', $date)
            //     ->first();
            // if ($currentStockPeriod) {
            //     $totalStock = $currentStockPeriod->closing_stock;
            // }
        }

        return $totalStock;        
    }
    
     /**
     * @param int $itemId item id
     * @param string $reference refrence
     */
    public function usedStock(
        $itemId = null,
        $reference = null,
    ) {
        try {
            $totalUsed = Mutation::where('trx_reference', $reference)
            ->whereHas('stock',function($q) use($itemId){
                $q->where('item_id',$itemId);
            })
            ->value('used');

            $tempData = new stdClass();
            $tempData->used_stock = $totalUsed;

            $this->data = $tempData;
            return $this;
        } catch (\Throwable $th) {
            $this->status = 'error';
            $this->errorMessage = $th->getMessage();
            return $this;
        }
    }

    public function formatDate($date)
    {
        try {
            if ($date) {
                $date = explode('/', $date);

                if (strlen($date[2]) < 4) {
                    throw new Exception();
                }
                if (strlen($date[1]) < 2 || $date[1] > 12) {
                    throw new Exception();
                }
                if (strlen($date[0]) < 2) {
                    throw new Exception();
                }
                $date = $date[2] . '-' . $date[1] . '-' . $date[0];
                $new_date = date('Y-m-d', strtotime($date));
                return $new_date;
            }
        } catch (\Throwable $th) {
            return false;
        }
    }
}
