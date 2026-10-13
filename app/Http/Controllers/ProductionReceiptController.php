<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\Item;
use App\Models\ProductionReceipt;
use App\Models\Recipe;
use App\Models\Unit;
use App\Services\AccessManager;
use App\Services\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductionReceiptController extends Controller
{
    use ResolvesBranch;

    public function index(Request $request): Response
    {
        $this->authorize('inventory.stock.view');

        return Inertia::render('Inventory/DirectReceipts', [
            'receipts' => ProductionReceipt::query()->where('branch_id', $this->branchId())->with('item:id,name,sku')->latest()->paginate(20),
            'items' => Item::query()->where('track_stock', true)->where('type', '!=', 'service')->whereNotNull('base_unit_id')->orderBy('name')->get(['id', 'name', 'sku', 'base_unit_id']),
            'bills' => Recipe::query()->pluck('name', 'item_id'),
            'units' => Unit::query()->get(['id', 'name', 'symbol'])->keyBy('id'),
            'canReceive' => $request->user()->can('inventory.adjust'),
        ]);
    }

    public function store(Request $request, ProductionService $production, AccessManager $access): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid', 'exists:tenant.items,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lot_code' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'lot_attributes' => ['nullable', 'array'],
            'lot_attributes.*' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $production->receive([
            ...$data, 'branch_id' => $this->branchId(),
            'max_value' => $access->constraintsFor($request->user(), 'inventory.adjust')['max_adjustment_value'] ?? null,
        ]);

        return back()->with('success', 'Direct receipt posted. Stock is available at this location.');
    }
}
