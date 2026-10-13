<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\FeatureGate;
use App\Services\StockLedger;
use App\Support\Bc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    use ResolvesBranch;

    public function __construct(
        private StockLedger $ledger,
        private DocumentNumbers $numbers,
        private AuditLogger $audit,
        private AccessManager $access,
        private FeatureGate $features,
    ) {}

    public function stock(Request $request): Response
    {
        $this->authorize('inventory.stock.view');
        $branchId = $request->string('branch_id')->toString() ?: $this->branchId();
        abort_unless(in_array($branchId, $this->access->visibleBranchIds($request->user(), 'inventory.stock.view'), true), 403);
        $search = $request->string('q')->toString();
        $source = $request->string('source')->toString();
        if (! in_array($source, ['produced', 'stocked'], true)) {
            $source = '';
        }
        $rows = DB::connection('tenant')->table('items')
            ->leftJoin('stock_balances', function ($join) use ($branchId) {
                $join->on('stock_balances.item_id', '=', 'items.id')->where('stock_balances.branch_id', $branchId)->whereNull('stock_balances.lot_id');
            })
            ->whereNull('items.deleted_at')
            ->when($search !== '', fn ($query) => $query->where('items.name', 'ilike', '%'.$search.'%'))
            ->when($source !== '', fn ($query) => $query->whereRaw($this->stockSourceSql().' = ?', [$source]))
            ->orderBy('items.name')
            ->select(['items.id', 'items.name', 'items.sku', 'items.type', 'items.reorder_level', 'stock_balances.qty', 'stock_balances.value', 'stock_balances.avg_cost'])
            ->selectRaw($this->stockSourceSql().' as source')
            ->get();

        return Inertia::render('Inventory/Stock', [
            'rows' => $rows,
            'branchId' => $branchId,
            'selectedItem' => $request->string('item_id')->toString(),
            'canAdjust' => $request->user()->can('inventory.adjust') && $branchId === $this->branchId(),
            'filters' => ['q' => $search, 'source' => $source],
        ]);
    }

    public function movements(Request $request): Response
    {
        $this->authorize('inventory.stock.view');
        $branchId = $request->string('branch_id')->toString() ?: $this->branchId();
        abort_unless(in_array($branchId, $this->access->visibleBranchIds($request->user(), 'inventory.stock.view'), true), 403);
        $itemId = $request->string('item_id')->toString();
        if ($itemId !== '' && ! Str::isUuid($itemId)) {
            $itemId = '';
        }
        $movements = StockMovement::query()
            ->leftJoin('items', 'items.id', '=', 'stock_movements.item_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.user_id')
            ->leftJoin('system_users', 'system_users.id', '=', 'stock_movements.system_user_id')
            ->when($itemId !== '', fn ($query) => $query->where('stock_movements.item_id', $itemId))
            ->where('stock_movements.branch_id', $branchId)
            ->select([
                'stock_movements.id',
                'stock_movements.item_id',
                'stock_movements.created_at',
                'stock_movements.type',
                'stock_movements.signed_qty',
                'stock_movements.unit_cost',
                'stock_movements.reason',
                'items.name as item_name',
                'items.sku as item_sku',
            ])
            ->selectRaw('coalesce(users.name, system_users.name) as changed_by')
            ->selectRaw('sum(stock_movements.signed_qty) over (partition by stock_movements.item_id, stock_movements.branch_id order by stock_movements.created_at asc, stock_movements.id asc) as qty_after')
            ->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id')
            ->paginate(40)
            ->withQueryString();
        $movements->getCollection()->transform(function (StockMovement $movement): StockMovement {
            $movement->setAttribute('qty_after', Bc::num($movement->getAttribute('qty_after')));

            return $movement;
        });

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku']),
            'itemId' => $itemId,
            'branchId' => $branchId,
        ]);
    }

    public function transfers(): Response
    {
        $this->authorize('inventory.transfer');
        abort_unless($this->features->active('branches.multi_branch'), 404);

        return Inertia::render('Inventory/Transfers', [
            'transfers' => Transfer::query()->latest()->paginate(20),
            'items' => Item::query()->where('track_stock', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'branches' => Branch::query()->get(),
        ]);
    }

    public function storeTransfer(Request $request): RedirectResponse
    {
        $this->authorize('inventory.transfer');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'from_branch_id' => ['required', 'uuid'],
            'to_branch_id' => ['required', 'uuid', 'different:from_branch_id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $transfer = Transfer::query()->create([
            'number' => $this->numbers->next($data['from_branch_id'], 'transfer'),
            'from_branch_id' => $data['from_branch_id'],
            'to_branch_id' => $data['to_branch_id'],
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        foreach ($data['lines'] as $line) {
            TransferLine::query()->create(['transfer_id' => $transfer->id, 'item_id' => $line['item_id'], 'qty' => $line['qty']]);
        }

        return back()->with('success', 'Transfer drafted.');
    }

    public function sendTransfer(Transfer $transfer): RedirectResponse
    {
        $this->authorize('inventory.transfer');
        abort_unless($transfer->status === 'draft', 422);
        DB::connection('tenant')->transaction(function () use ($transfer) {
            foreach (TransferLine::query()->where('transfer_id', $transfer->id)->get() as $line) {
                $this->ledger->post([
                    'item_id' => $line->item_id,
                    'branch_id' => $transfer->from_branch_id,
                    'type' => 'transfer_out',
                    'quantity' => $line->qty,
                    'signed_qty' => Bc::mul($line->qty, '-1'),
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id,
                    'user_id' => auth()->id(),
                    'reason' => 'Transfer '.$transfer->number,
                ]);
            }
            $transfer->update(['status' => 'in_transit']);
        });

        return back()->with('success', 'Transfer is in transit.');
    }

    public function receiveTransfer(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('inventory.receive');
        $data = $request->validate(['lines' => ['required', 'array']]);
        DB::connection('tenant')->transaction(function () use ($transfer, $data) {
            $discrepancy = false;
            foreach (TransferLine::query()->where('transfer_id', $transfer->id)->get() as $line) {
                $received = Bc::num($data['lines'][$line->id] ?? $line->qty);
                $line->update(['qty_received' => $received]);
                if (Bc::cmp($received, $line->qty) !== 0) {
                    $discrepancy = true;
                }
                if (Bc::cmp($received, '0') > 0) {
                    $cost = $this->ledger->averageCost($line->item_id, $transfer->from_branch_id);
                    $this->ledger->post([
                        'item_id' => $line->item_id,
                        'branch_id' => $transfer->to_branch_id,
                        'type' => 'transfer_in',
                        'quantity' => $received,
                        'signed_qty' => $received,
                        'unit_cost' => $cost,
                        'reference_type' => 'transfer',
                        'reference_id' => $transfer->id,
                        'user_id' => auth()->id(),
                    ]);
                }
            }
            $transfer->update(['status' => $discrepancy ? 'discrepancy' : 'received']);
        });

        return back()->with('success', 'Transfer received.');
    }

    public function counts(): Response
    {
        $this->authorize('inventory.stock.view');

        return Inertia::render('Inventory/Counts', [
            'counts' => StockCount::query()->where('branch_id', $this->branchId())->latest()->get(),
            'items' => Item::query()->where('track_stock', true)->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function storeCount(Request $request): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $data = $request->validate([
            'type' => ['required', 'in:full,cycle'],
            'blind' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.counted_qty' => ['nullable', 'numeric'],
        ]);
        $branchId = $this->branchId();
        $count = StockCount::query()->create([
            'number' => $this->numbers->next($branchId, 'count'),
            'branch_id' => $branchId,
            'type' => $data['type'],
            'blind' => $request->boolean('blind'),
            'status' => 'review',
            'counted_by' => $request->user()->id,
        ]);
        foreach ($data['lines'] as $line) {
            StockCountLine::query()->create([
                'stock_count_id' => $count->id,
                'item_id' => $line['item_id'],
                'expected_qty' => $this->ledger->onHand($line['item_id'], $branchId),
                'counted_qty' => $line['counted_qty'] ?? null,
            ]);
        }

        return back()->with('success', 'Count saved for review.');
    }

    public function postCount(StockCount $count): RedirectResponse
    {
        $this->authorize('inventory.counts.approve');
        DB::connection('tenant')->transaction(function () use ($count) {
            foreach (StockCountLine::query()->where('stock_count_id', $count->id)->get() as $line) {
                if ($line->counted_qty === null) {
                    continue;
                }
                $delta = Bc::sub($line->counted_qty, $line->expected_qty);
                if (Bc::cmp($delta, '0') === 0) {
                    continue;
                }
                $this->ledger->post([
                    'item_id' => $line->item_id,
                    'branch_id' => $count->branch_id,
                    'type' => 'adjustment',
                    'quantity' => Bc::mul($delta, Bc::cmp($delta, '0') < 0 ? '-1' : '1'),
                    'signed_qty' => $delta,
                    'unit_cost' => $this->ledger->averageCost($line->item_id, $count->branch_id),
                    'reference_type' => 'stock_count',
                    'reference_id' => $count->id,
                    'user_id' => auth()->id(),
                    'reason' => 'Count '.$count->number,
                    'allow_negative' => true,
                ]);
            }
            $count->update(['status' => 'posted', 'approved_by' => auth()->id()]);
        });
        $this->audit->tenant('inventory.count.posted', 'stock_count', $count->id);

        return back()->with('success', 'Count posted to the ledger.');
    }

    public function lots(): Response
    {
        $this->authorize('inventory.stock.view');
        abort_unless($this->features->active('inventory.lots_expiry'), 404);

        return Inertia::render('Inventory/Lots', [
            'lots' => StockLot::query()->where('branch_id', $this->branchId())->orderBy('expiry_date')->get(),
            'items' => Item::query()->get(['id', 'name'])->keyBy('id'),
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'request_id' => ['nullable', 'uuid'],
            'item_id' => ['required', 'uuid', 'exists:tenant.items,id'],
            'signed_qty' => ['required', 'numeric', 'decimal:0,4', 'not_in:0', 'between:-999999999,999999999'],
            'reason' => ['required', 'string', 'max:2000'],
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
        ]);
        DB::connection('tenant')->transaction(function () use ($request, $data): void {
            Branch::query()->whereKey($this->branchId())->lockForUpdate()->firstOrFail();
            $item = Item::query()->findOrFail($data['item_id']);
            if (! $item->track_stock || $item->type === 'service') {
                throw ValidationException::withMessages(['item_id' => 'Only stocked goods can be adjusted.']);
            }
            if (! empty($data['request_id'])) {
                $existing = StockMovement::query()->where('branch_id', $this->branchId())->where('reference_type', 'adjustment_request')->where('reference_id', $data['request_id'])->first();
                if ($existing) {
                    if ($existing->item_id !== $item->id || Bc::cmp($existing->signed_qty, $data['signed_qty']) !== 0 || $existing->reason !== $data['reason'] || (isset($data['unit_cost']) && Bc::cmp($existing->unit_cost, $data['unit_cost']) !== 0)) {
                        throw ValidationException::withMessages(['request_id' => 'This request already posted a different stock adjustment.']);
                    }

                    return;
                }
            }
            $value = abs((float) $data['signed_qty']) * (float) ($data['unit_cost'] ?? $this->ledger->averageCost($data['item_id'], $this->branchId()));
            $max = $this->access->constraintsFor($request->user(), 'inventory.adjust')['max_adjustment_value'] ?? null;
            if ($max !== null && $value > (float) $max) {
                throw ValidationException::withMessages(['signed_qty' => 'Adjustment exceeds your limit of '.$max.' TZS.']);
            }
            $qty = Bc::num($data['signed_qty']);
            $this->ledger->post([
                'item_id' => $data['item_id'],
                'branch_id' => $this->branchId(),
                'type' => Bc::cmp($qty, '0') < 0 ? 'wastage' : 'adjustment',
                'quantity' => Bc::mul($qty, Bc::cmp($qty, '0') < 0 ? '-1' : '1'),
                'signed_qty' => $qty,
                'unit_cost' => $data['unit_cost'] ?? null,
                'reason' => $data['reason'],
                'reference_type' => empty($data['request_id']) ? 'adjustment' : 'adjustment_request',
                'reference_id' => $data['request_id'] ?? $request->user()->id,
                'user_id' => $request->user()->id,
            ]);
            $this->audit->tenant('inventory.adjusted', 'item', $data['item_id'], null, $data);
        });

        return back()->with('success', 'Stock adjustment posted.');
    }

    private function stockSourceSql(): string
    {
        return "CASE WHEN items.type = 'finished_good' OR EXISTS (SELECT 1 FROM recipes WHERE recipes.item_id = items.id AND recipes.deleted_at IS NULL) THEN 'produced' WHEN items.type IN ('raw_material', 'trading_good') THEN 'stocked' ELSE items.type END";
    }
}
