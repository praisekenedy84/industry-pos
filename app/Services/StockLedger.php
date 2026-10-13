<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Support\Bc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockLedger
{
    public function __construct(private FeatureGate $features) {}

    public function onHand(string $itemId, string $branchId): string
    {
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereNull('lot_id')
            ->first();

        return Bc::num($balance->qty ?? 0);
    }

    public function averageCost(string $itemId, string $branchId): string
    {
        $balance = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereNull('lot_id')
            ->first();

        return Bc::num($balance->avg_cost ?? 0);
    }

    public function post(array $data): StockMovement
    {
        return DB::connection('tenant')->transaction(function () use ($data) {
            $signed = Bc::num($data['signed_qty']);
            $quantity = Bc::num($data['quantity']);
            $itemId = $data['item_id'];
            $branchId = $data['branch_id'];

            $balance = $this->lockAggregate($itemId, $branchId);
            $unitCost = $data['unit_cost'] ?? null;
            $unitCost = $unitCost === null ? null : Bc::num($unitCost);

            if (Bc::cmp($signed, '0') < 0) {
                $this->guardNegative($itemId, $branchId, $balance, $signed, (bool) ($data['allow_negative'] ?? false));
                $issued = Bc::mul($signed, '-1');
                if ($unitCost === null) {
                    $data['total_cost'] = $this->valueForIssue($itemId, $branchId, $issued, $data['lot_id'] ?? null);
                    $unitCost = Bc::div($data['total_cost'], $issued);
                }
            }

            if (Bc::cmp($signed, '0') > 0) {
                $unitCost = $unitCost ?? '0.0000';
                $newQty = Bc::add($balance->qty, $signed);
                $receivedValue = isset($data['total_cost']) ? Bc::num($data['total_cost']) : Bc::mul($signed, $unitCost);
                $newValue = Bc::add($balance->value, $receivedValue);
                $balance->qty = $newQty;
                $balance->value = $newValue;
                $balance->avg_cost = Bc::cmp($newQty, '0') > 0 ? Bc::div($newValue, $newQty) : $unitCost;
                $balance->save();
                $lotQty = Bc::cmp($newQty, '0') > 0 && Bc::cmp($signed, $newQty) > 0 ? $newQty : (Bc::cmp($newQty, '0') <= 0 ? '0.0000' : $signed);
                $data['lot_id'] = $this->touchLot($data, $lotQty, $unitCost);
            } elseif (Bc::cmp($signed, '0') < 0) {
                $issued = Bc::mul($signed, '-1');
                $newQty = Bc::add($balance->qty, $signed);
                $issuedValue = isset($data['total_cost']) ? Bc::num($data['total_cost']) : Bc::mul($issued, $unitCost);
                $newValue = Bc::sub($balance->value, $issuedValue);
                if (Bc::cmp($newQty, '0') === 0) {
                    $newValue = '0.0000';
                }
                $balance->qty = $newQty;
                $balance->value = $newValue;
                if (Bc::cmp($newQty, '0') > 0) {
                    $balance->avg_cost = Bc::div($balance->value, $newQty);
                }
                $balance->save();
                $this->consumeLots($itemId, $branchId, $issued, $data['lot_id'] ?? null);
            }

            $cost = $unitCost ?? '0.0000';

            return StockMovement::query()->create([
                'item_id' => $itemId,
                'branch_id' => $branchId,
                'lot_id' => $data['lot_id'] ?? null,
                'type' => $data['type'],
                'quantity' => $quantity,
                'signed_qty' => $signed,
                'unit_cost' => $cost,
                'total_cost' => isset($data['total_cost']) ? Bc::num($data['total_cost']) : Bc::mul($quantity, $cost),
                'user_id' => $data['user_id'] ?? null,
                'system_user_id' => $data['system_user_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
            ]);
        });
    }

    private function lockAggregate(string $itemId, string $branchId): StockBalance
    {
        Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
        StockBalance::query()->firstOrCreate(
            ['item_id' => $itemId, 'branch_id' => $branchId, 'lot_id' => null],
            ['qty' => 0, 'value' => 0, 'avg_cost' => 0],
        );

        return StockBalance::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->whereNull('lot_id')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function guardNegative(string $itemId, string $branchId, StockBalance $balance, string $signed, bool $allow): void
    {
        $next = Bc::add($balance->qty, $signed);
        if (Bc::cmp($next, '0') >= 0 || $allow) {
            return;
        }

        $item = Item::query()->find($itemId);
        $policy = $item?->negative_stock_policy ?: 'inherit';
        if ($policy === 'inherit') {
            $policy = Setting::getValue('negative_stock', 'block');
        }
        if ($policy === 'block') {
            throw ValidationException::withMessages([
                'stock' => ($item->name ?? 'Item').' would go negative at this branch. The negative-stock policy is block.',
            ]);
        }
    }

    public function assertKnownCost(string $itemId, string $branchId): void
    {
        $balance = StockBalance::query()->where('item_id', $itemId)->where('branch_id', $branchId)->whereNull('lot_id')->first();
        if (! $balance || (Bc::cmp($balance->avg_cost, 0) === 0 && (Bc::cmp($balance->value, 0) !== 0 || ! StockMovement::query()->where('item_id', $itemId)->where('branch_id', $branchId)->where('signed_qty', '>', 0)->where('unit_cost', 0)->exists()))) {
            throw ValidationException::withMessages(['cost' => 'Receive ingredient stock with its purchase cost before completing. Explicit zero-cost receipts are allowed.']);
        }
    }

    public function valueForIssue(string $itemId, string $branchId, string $qty, ?string $lotId = null): string
    {
        $balance = StockBalance::query()->where('item_id', $itemId)->where('branch_id', $branchId)->whereNull('lot_id')->first();
        if ($balance && Bc::cmp($balance->qty, $qty) === 0) {
            return Bc::num($balance->value);
        }
        if (Setting::getValue('valuation', 'weighted_average') === 'fifo') {
            $remaining = $qty;
            $value = '0.0000';
            $lots = StockLot::query()->where('item_id', $itemId)->where('branch_id', $branchId)->where('qty', '>', 0)
                ->when($lotId, fn ($query) => $query->whereKey($lotId))->orderBy('created_at')->orderBy('receipt_sequence')->get();
            foreach ($lots as $lot) {
                if (Bc::cmp($remaining, 0) <= 0) {
                    break;
                }
                $take = Bc::cmp($lot->qty, $remaining) <= 0 ? Bc::num($lot->qty) : $remaining;
                $value = Bc::add($value, Bc::mul($take, $lot->unit_cost));
                $remaining = Bc::sub($remaining, $take);
            }

            return Bc::add($value, Bc::mul($remaining, $balance->avg_cost ?? 0));
        }

        return Bc::mul($qty, $balance->avg_cost ?? 0);
    }

    private function touchLot(array $data, string $signed, string $unitCost): ?string
    {
        $code = $data['lot_code'] ?? null;
        if (! $code && empty($data['lot_id'])) {
            if (Setting::getValue('valuation', 'weighted_average') !== 'fifo') {
                return null;
            }
            $code = 'RCV-'.Str::uuid();
        }
        if (! $this->features->active('inventory.lots_expiry') && empty($data['lot_id']) && ! $code) {
            return null;
        }
        $lot = null;
        if (! empty($data['lot_id'])) {
            $lot = StockLot::query()->find($data['lot_id']);
        }
        if (! $lot && $code) {
            $lot = StockLot::query()->firstOrCreate(
                [
                    'item_id' => $data['item_id'],
                    'branch_id' => $data['branch_id'],
                    'lot_code' => $code,
                ],
                [
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'attributes' => $data['lot_attributes'] ?? null,
                    'qty' => 0,
                    'unit_cost' => $unitCost,
                ],
            );
        }
        if ($lot) {
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            $lot->qty = Bc::add($lot->qty, $signed);
            $lot->unit_cost = $unitCost;
            $lot->save();
        }

        return $lot?->id;
    }

    private function consumeLots(string $itemId, string $branchId, string $qty, ?string $lotId): void
    {
        $remaining = $qty;
        $query = StockLot::query()->where('item_id', $itemId)->where('branch_id', $branchId)->where('qty', '>', 0);
        if ($lotId) {
            $query->where('id', $lotId);
        } else {
            if (Setting::getValue('valuation', 'weighted_average') !== 'fifo') {
                $query->orderByRaw('expiry_date nulls last');
            }
            $query->orderBy('created_at')->orderBy('receipt_sequence');
        }
        foreach ($query->lockForUpdate()->get() as $lot) {
            if (Bc::cmp($remaining, '0') <= 0) {
                break;
            }
            $take = Bc::cmp($lot->qty, $remaining) <= 0 ? Bc::num($lot->qty) : $remaining;
            $lot->qty = Bc::sub($lot->qty, $take);
            $lot->save();
            $remaining = Bc::sub($remaining, $take);
        }
    }
}
