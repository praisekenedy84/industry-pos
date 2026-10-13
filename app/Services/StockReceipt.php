<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Support\Bc;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StockReceipt
{
    public function __construct(private StockLedger $ledger, private DocumentNumbers $numbers, private AuditLogger $audit) {}

    /** @param array{request_id: string, qty: mixed, unit_cost: mixed, supplier_id?: ?string, reference?: ?string, notes?: ?string} $data */
    public function receive(Item $item, string $branchId, array $data, mixed $maxValue = null): GoodsReceipt
    {
        $data = Validator::make($data, [
            'request_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'supplier_id' => ['nullable', 'uuid', 'exists:tenant.suppliers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($item, $branchId, $data, $maxValue): GoodsReceipt {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);
            if ($branch->status !== 'active') {
                throw ValidationException::withMessages(['stock' => 'This branch cannot transact.']);
            }
            $item = Item::query()->findOrFail($item->id);
            if (! in_array($item->type, ['trading_good', 'raw_material'], true) || ! $item->track_stock || ! $item->base_unit_id) {
                throw ValidationException::withMessages(['stock' => 'Add stock is for traded goods and ingredients with a stock unit. Complete a batch for produced goods.']);
            }
            $hash = hash('sha256', json_encode([$item->id, Bc::num($data['qty']), Bc::num($data['unit_cost']), $data['supplier_id'] ?? null, $data['reference'] ?? null, $data['notes'] ?? null], JSON_THROW_ON_ERROR));
            $existing = GoodsReceipt::query()->where('branch_id', $branchId)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw ValidationException::withMessages(['request_id' => 'This request already recorded a different receipt. Reload before adding another delivery.']);
                }

                return $existing;
            }
            $value = Bc::mul($data['qty'], $data['unit_cost']);
            if (Bc::cmp($value, '99999999999999') > 0 || ($maxValue !== null && Bc::cmp($value, $maxValue) > 0)) {
                throw ValidationException::withMessages(['qty' => 'The receipt value exceeds the allowed limit.']);
            }
            $receipt = GoodsReceipt::query()->create([
                'number' => $this->numbers->next($branchId, 'grn'), 'branch_id' => $branchId,
                'request_id' => $data['request_id'], 'request_hash' => $hash,
                'supplier_id' => $data['supplier_id'] ?? null, 'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'user_id' => Auth::id(),
            ]);
            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id, 'item_id' => $item->id,
                'qty' => $data['qty'], 'unit_cost' => $data['unit_cost'], 'lot_code' => $receipt->number,
            ]);
            $this->ledger->post([
                'item_id' => $item->id, 'branch_id' => $branchId, 'type' => 'purchase_receipt',
                'quantity' => $data['qty'], 'signed_qty' => $data['qty'], 'unit_cost' => $data['unit_cost'],
                'lot_code' => $receipt->number, 'reference_type' => 'goods_receipt', 'reference_id' => $receipt->id,
                'user_id' => Auth::id(), 'reason' => 'Stock receipt '.$receipt->number,
            ]);
            $this->audit->tenant('inventory.received', 'goods_receipt', $receipt->id, null, [...$data, 'item_id' => $item->id, 'branch_id' => $branchId]);

            return $receipt;
        });
    }
}
