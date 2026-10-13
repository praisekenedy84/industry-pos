<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierPrice;
use App\Models\SupplierReturn;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchasingController extends Controller
{
    use ResolvesBranch;

    public function __construct(private DocumentNumbers $numbers, private StockLedger $ledger, private AuditLogger $audit) {}

    public function suppliers(): Response
    {
        $this->authorize('purchasing.orders.view');

        return Inertia::render('Purchasing/Suppliers', [
            'suppliers' => Supplier::withTrashed()->orderBy('name')->get(),
            'canManage' => auth()->user()->can('purchasing.orders.create'),
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku']),
            'prices' => SupplierPrice::query()->get(),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.orders.create');
        $data = $request->validate([
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'payment_terms' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'item_id' => ['nullable', 'uuid', 'exists:tenant.items,id'],
            'price' => ['required_with:item_id', 'nullable', 'numeric', 'min:0'],
        ]);
        DB::connection('tenant')->transaction(function () use ($data): void {
            $supplier = Supplier::query()->create(collect($data)->only(['name', 'phone', 'email', 'payment_terms', 'notes'])->all());
            if (! empty($data['item_id'])) {
                SupplierPrice::query()->create(['supplier_id' => $supplier->id, 'item_id' => $data['item_id'], 'price' => $data['price']]);
            }
            $this->audit->tenant('supplier.created', 'supplier', $supplier->id);
        });

        return back()->with('success', 'Supplier saved.');
    }

    public function orders(): Response
    {
        $this->authorize('purchasing.orders.view');

        return Inertia::render('Purchasing/Orders', [
            'orders' => PurchaseOrder::query()->where('branch_id', $this->branchId())->latest()->paginate(20),
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function updateSupplier(Request $request, string $workspace, Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.orders.create');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'payment_terms' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000']]);
        DB::connection('tenant')->transaction(function () use ($supplier, $data): void {
            $before = $supplier->toArray();
            $supplier->update($data);
            $this->audit->tenant('supplier.updated', 'supplier', $supplier->id, $before, $supplier->fresh()->toArray());
        });

        return back()->with('success', 'Supplier updated.');
    }

    public function destroySupplier(string $workspace, Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.orders.create');
        DB::connection('tenant')->transaction(function () use ($supplier): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if (Bc::cmp($supplier->balance ?? 0, 0) !== 0 || PurchaseOrder::query()->where('supplier_id', $supplier->id)->whereNotIn('status', ['received', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['supplier' => 'Settle this supplier balance and finish open purchase orders before archiving.']);
            }
            $supplier->delete();
            $this->audit->tenant('supplier.archived', 'supplier', $supplier->id);
        });

        return back()->with('success', 'Supplier archived. Purchase history is retained.');
    }

    public function restoreSupplier(string $workspace, Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.orders.create');
        DB::connection('tenant')->transaction(function () use ($supplier): void {
            $supplier->restore();
            $this->audit->tenant('supplier.restored', 'supplier', $supplier->id);
        });

        return back()->with('success', 'Supplier restored.');
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.orders.create');
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric'],
        ]);
        $branchId = $this->branchId();
        $order = PurchaseOrder::query()->create([
            'number' => $this->numbers->next($branchId, 'purchase'),
            'supplier_id' => $data['supplier_id'],
            'branch_id' => $branchId,
            'status' => 'draft',
            'expected_at' => $data['expected_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        foreach ($data['lines'] as $line) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $order->id,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
            ]);
        }

        return redirect()->route('purchasing.orders.show', $order);
    }

    public function showOrder(PurchaseOrder $order): Response
    {
        $this->authorize('purchasing.orders.view');

        return Inertia::render('Purchasing/OrderShow', [
            'order' => $order,
            'lines' => PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get(),
            'supplier' => Supplier::query()->find($order->supplier_id),
            'items' => Item::query()->get(['id', 'name', 'sku'])->keyBy('id'),
            'receipts' => GoodsReceipt::query()->where('purchase_order_id', $order->id)->get(),
        ]);
    }

    public function approve(PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('purchasing.orders.approve');
        $order->update(['status' => 'approved', 'approved_by' => auth()->id()]);
        $this->audit->tenant('purchasing.approved', 'purchase_order', $order->id);

        return back()->with('success', 'Purchase order approved.');
    }

    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $this->authorize('purchasing.receipts.create');
        $this->assertBranchCanTransact($order->branch_id);
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
            'photo' => ['nullable', 'file', 'max:4096'],
        ]);
        DB::connection('tenant')->transaction(function () use ($order, $data, $request) {
            $path = $request->file('photo')?->store('tenants/'.app(TenancyManager::class)->id().'/receipts', 'public');
            $receipt = GoodsReceipt::query()->create([
                'number' => $this->numbers->next($order->branch_id, 'grn'),
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id,
                'notes' => $data['notes'] ?? null,
                'photo_path' => $path,
                'user_id' => $request->user()->id,
            ]);
            $open = false;
            foreach (PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get() as $line) {
                $qty = Bc::num($data['lines'][$line->id]['qty'] ?? 0);
                if (Bc::cmp($qty, '0') <= 0) {
                    if (Bc::cmp($line->qty_received, $line->qty) < 0) {
                        $open = true;
                    }

                    continue;
                }
                GoodsReceiptLine::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $line->id,
                    'item_id' => $line->item_id,
                    'qty' => $qty,
                    'unit_cost' => $data['lines'][$line->id]['unit_cost'] ?? $line->unit_cost,
                    'lot_code' => $data['lines'][$line->id]['lot_code'] ?? null,
                    'expiry_date' => $data['lines'][$line->id]['expiry_date'] ?? null,
                ]);
                $this->ledger->post([
                    'item_id' => $line->item_id,
                    'branch_id' => $order->branch_id,
                    'type' => 'purchase_receipt',
                    'quantity' => $qty,
                    'signed_qty' => $qty,
                    'unit_cost' => $data['lines'][$line->id]['unit_cost'] ?? $line->unit_cost,
                    'lot_code' => $data['lines'][$line->id]['lot_code'] ?? null,
                    'expiry_date' => $data['lines'][$line->id]['expiry_date'] ?? null,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'user_id' => $request->user()->id,
                    'reason' => 'PO '.$order->number,
                ]);
                $line->qty_received = Bc::add($line->qty_received, $qty);
                $line->save();
                if (Bc::cmp($line->qty_received, $line->qty) < 0) {
                    $open = true;
                }
            }
            $supplier = Supplier::query()->find($order->supplier_id);
            if ($supplier) {
                $added = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->get()->reduce(
                    fn ($carry, $row) => Bc::add($carry, Bc::mul($row->qty, $row->unit_cost)),
                    '0',
                );
                $supplier->balance = Bc::add($supplier->balance, $added);
                $supplier->save();
            }
            $order->update(['status' => $open ? 'partially_received' : 'received']);
        });

        return back()->with('success', 'Goods received and stock updated.');
    }

    public function supplierReturn(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.returns.create');
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);
        $branchId = $this->branchId();
        $this->assertBranchCanTransact($branchId);
        $return = SupplierReturn::query()->create([
            'number' => $this->numbers->next($branchId, 'return'),
            'supplier_id' => $data['supplier_id'],
            'branch_id' => $branchId,
            'item_id' => $data['item_id'],
            'qty' => $data['qty'],
            'unit_cost' => $data['unit_cost'],
            'notes' => $data['notes'] ?? null,
            'user_id' => $request->user()->id,
        ]);
        $this->ledger->post([
            'item_id' => $data['item_id'],
            'branch_id' => $branchId,
            'type' => 'adjustment',
            'quantity' => $data['qty'],
            'signed_qty' => Bc::mul($data['qty'], '-1'),
            'unit_cost' => $data['unit_cost'],
            'reference_type' => 'supplier_return',
            'reference_id' => $return->id,
            'user_id' => $request->user()->id,
            'reason' => $data['notes'] ?? 'Supplier return',
        ]);
        $supplier = Supplier::query()->find($data['supplier_id']);
        if ($supplier) {
            $supplier->balance = Bc::sub($supplier->balance, Bc::mul($data['qty'], $data['unit_cost']));
            $supplier->save();
        }

        return back()->with('success', 'Supplier return posted.');
    }
}
