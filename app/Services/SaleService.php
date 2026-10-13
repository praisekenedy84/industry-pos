<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionOrder;
use App\Models\Receipt;
use App\Models\Recipe;
use App\Models\ReturnLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private StockLedger $ledger,
        private DocumentNumbers $numbers,
        private ProductionService $production,
        private AuditLogger $audit,
        private FeatureGate $features,
        private AccessManager $access,
    ) {}

    public function priceFor(Item $item, ?string $priceListId, ?string $variantId = null, ?string $branchId = null): string
    {
        $query = PriceListItem::query()->where('item_id', $item->id)->orderByRaw('branch_id IS NULL');
        if ($priceListId) {
            $query->where('price_list_id', $priceListId);
        } else {
            $default = PriceList::query()->where('is_default', true)->first();
            if ($default) {
                $query->where('price_list_id', $default->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        $now = now();
        $row = $query->get()->first(function ($row) use ($branchId, $variantId) {
            if ($row->branch_id && $row->branch_id !== $branchId) {
                return false;
            }
            if ($row->variant_id && $row->variant_id !== $variantId) {
                return false;
            }
            if ($row->starts_at && $row->starts_at->isFuture()) {
                return false;
            }
            if ($row->ends_at && $row->ends_at->isPast()) {
                return false;
            }

            return true;
        });
        $price = $row ? Bc::num($row->price) : Bc::num($item->default_price);
        if ($variantId) {
            $variant = $item->relationLoaded('variants')
                ? $item->variants->firstWhere('id', $variantId)
                : ItemVariant::query()->find($variantId);
            if ($variant) {
                $price = Bc::add($price, $variant->price_delta);
            }
        }

        return $price;
    }

    public function complete(array $data): Sale
    {
        $key = $data['idempotency_key'] ?? null;

        return DB::connection('tenant')->transaction(function () use ($data, $key) {
            $branchId = $data['branch_id'];
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            if ($key) {
                $existing = Sale::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ($existing->branch_id !== $branchId) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This sale request belongs to another branch.']);
                    }

                    return $existing->load(['lines', 'payments']);
                }
            }
            $user = Auth::user();
            $includeTax = (bool) SettingPrices::includeTax();
            $customer = ! empty($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : null;
            $priceListId = $data['price_list_id'] ?? $customer?->price_list_id;

            $subtotal = '0.0000';
            $discountTotal = '0.0000';
            $taxTotal = '0.0000';
            $grossTotal = '0.0000';
            $prepared = [];

            foreach ($data['lines'] as $line) {
                $item = Item::query()->findOrFail($line['item_id']);
                if (! in_array($item->type, ['finished_good', 'trading_good', 'service'], true) && ! ($item->type === 'raw_material' && $item->billsOfMaterials()->exists())) {
                    throw ValidationException::withMessages(['lines' => $item->name.' cannot be sold.']);
                }
                $qty = Bc::num($line['qty']);
                $unitPrice = Bc::num($line['unit_price'] ?? $this->priceFor($item, $priceListId, $line['variant_id'] ?? null, $branchId));
                $discount = Bc::num($line['discount'] ?? 0);
                $this->guardDiscount($user, $unitPrice, $qty, $discount, $data['approval_pin'] ?? null);
                $lineNetBeforeTax = Bc::sub(Bc::mul($unitPrice, $qty), $discount);
                if (Bc::cmp($lineNetBeforeTax, '0') < 0) {
                    throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the line total.']);
                }
                $ratePercent = $this->taxPercent($item);
                $rate = Bc::div($ratePercent, '100');
                if ($includeTax) {
                    $gross = $lineNetBeforeTax;
                    $net = Bc::cmp($rate, '0') > 0 ? Bc::div($gross, Bc::add('1', $rate)) : $gross;
                    $tax = Bc::sub($gross, $net);
                } else {
                    $net = $lineNetBeforeTax;
                    $tax = Bc::mul($net, $rate);
                    $gross = Bc::add($net, $tax);
                }
                $prepared[] = compact('item', 'qty', 'unitPrice', 'discount', 'ratePercent', 'net', 'tax', 'gross') + [
                    'variant_id' => $line['variant_id'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ];
                $subtotal = Bc::add($subtotal, $net);
                $discountTotal = Bc::add($discountTotal, $discount);
                $taxTotal = Bc::add($taxTotal, $tax);
                $grossTotal = Bc::add($grossTotal, $gross);
            }

            $payments = $data['payments'] ?? [];
            $paid = '0.0000';
            foreach ($payments as $payment) {
                $paid = Bc::add($paid, $payment['amount']);
            }
            if (Bc::cmp($paid, $grossTotal) < 0) {
                $hasAccount = collect($payments)->contains(fn ($p) => $p['method'] === 'account');
                if (! $hasAccount) {
                    throw ValidationException::withMessages(['payments' => 'Payments do not cover the sale total.']);
                }
            }

            $sale = Sale::query()->create([
                'number' => $this->numbers->next($branchId, 'sale'),
                'branch_id' => $branchId,
                'terminal_id' => $data['terminal_id'] ?? null,
                'user_id' => $user?->id,
                'customer_id' => $customer?->id,
                'price_list_id' => $priceListId,
                'status' => 'completed',
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total' => $grossTotal,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $key,
                'offline_ref' => $data['offline_ref'] ?? null,
                'prices_include_tax' => $includeTax,
            ]);

            foreach ($prepared as $line) {
                $fulfillment = $line['item']->type === 'finished_good' && $line['item']->make_mode === 'make_to_order' ? 'production' : 'stock';
                $unitCost = '0.0000';
                $totalCost = '0.0000';
                $productionOrderId = null;
                if ($line['item']->track_stock && $line['item']->type !== 'service' && $fulfillment === 'stock') {
                    if (Bc::cmp($this->ledger->onHand($line['item']->id, $branchId), $line['qty']) < 0) {
                        throw ValidationException::withMessages(['stock' => $line['item']->name.' has insufficient available stock. Complete a batch or add stock before selling.']);
                    }
                    $movement = $this->ledger->post([
                        'item_id' => $line['item']->id,
                        'branch_id' => $branchId,
                        'type' => 'sale',
                        'quantity' => $line['qty'],
                        'signed_qty' => Bc::mul($line['qty'], '-1'),
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'user_id' => $user?->id,
                        'reason' => 'Sale '.$sale->number,
                    ]);
                    $unitCost = $movement->unit_cost;
                    $totalCost = $movement->total_cost;
                }
                if ($fulfillment === 'production') {
                    $recipe = Recipe::query()->where('item_id', $line['item']->id)->first();
                    if ($recipe) {
                        $order = $this->production->plan([
                            'recipe_id' => $recipe->id,
                            'branch_id' => $branchId,
                            'planned_qty' => $line['qty'],
                            'sale_id' => $sale->id,
                            'notes' => 'Make to order from '.$sale->number,
                        ]);
                        $productionOrderId = $order->id;
                    } else {
                        throw ValidationException::withMessages(['lines' => $line['item']->name.' needs a recipe and production line before it can be made to order.']);
                    }
                }
                SaleLine::query()->create([
                    'sale_id' => $sale->id,
                    'item_id' => $line['item']->id,
                    'variant_id' => $line['variant_id'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unitPrice'],
                    'discount' => $line['discount'],
                    'tax_rate' => $line['ratePercent'],
                    'net' => $line['net'],
                    'tax_amount' => $line['tax'],
                    'gross' => $line['gross'],
                    'unit_cost' => $unitCost,
                    'total_cost' => $fulfillment === 'production' ? null : $totalCost,
                    'costing_status' => $fulfillment === 'production' ? 'pending' : 'costed',
                    'notes' => $line['notes'],
                    'fulfillment' => $fulfillment,
                    'production_order_id' => $productionOrderId,
                ]);
            }

            foreach ($payments as $payment) {
                SalePayment::query()->create([
                    'sale_id' => $sale->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                ]);
                if ($payment['method'] === 'account' && $customer) {
                    if (! $this->features->active('customers.credit')) {
                        throw ValidationException::withMessages(['payments' => 'Customer credit is not enabled.']);
                    }
                    $next = Bc::add($customer->balance, $payment['amount']);
                    if (Bc::cmp($customer->credit_limit, '0') > 0 && Bc::cmp($next, $customer->credit_limit) > 0) {
                        throw ValidationException::withMessages(['payments' => 'This sale would exceed the customer credit limit.']);
                    }
                    $customer->balance = $next;
                    $customer->save();
                }

            }

            Receipt::query()->create([
                'sale_id' => $sale->id,
                'number' => $this->numbers->next($branchId, 'receipt'),
                'branch_id' => $branchId,
                'language' => app(TenancyManager::class)->tenant()?->receipt_language ?? 'bilingual',
            ]);

            $this->audit->tenant('pos.sale.completed', 'sale', $sale->id, null, ['total' => $grossTotal, 'number' => $sale->number]);

            return $sale->load(['lines', 'payments']);
        });
    }

    public function updateNotes(Sale $sale, ?string $notes): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($sale, $notes) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['notes' => 'Only a completed sale can be edited.']);
            }
            $before = $sale->notes;
            $sale->update(['notes' => $notes]);
            $this->audit->tenant('pos.sale.updated', 'sale', $sale->id, ['notes' => $before], ['notes' => $sale->notes]);

            return $sale;
        });
    }

    public function void(Sale $sale, array $data): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($sale, $data) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'void') {
                return $sale;
            }
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Only a completed sale can be voided.']);
            }
            $user = Auth::user();
            $constraints = $user ? $this->access->constraintsFor($user, 'pos.sale.void') : [];
            if (! empty($constraints['requires_approval']) && ! $this->managerCanVoid($data['approved_by'] ?? null)) {
                throw ValidationException::withMessages(['approval' => 'A manager must approve this void.']);
            }
            if (SaleReturn::query()->where('sale_id', $sale->id)->exists()) {
                throw ValidationException::withMessages(['sale' => 'This sale has a return and cannot be voided.']);
            }

            $lines = SaleLine::query()->where('sale_id', $sale->id)->get();
            $orders = ProductionOrder::query()->whereIn('id', $lines->pluck('production_order_id')->filter())->lockForUpdate()->get();
            foreach ($orders as $order) {
                if (! in_array($order->status, ['draft', 'planned', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['sale' => 'Cancel the production batch before voiding this sale.']);
                }
            }

            foreach ($lines as $line) {
                $item = Item::withTrashed()->find($line->item_id);
                if ($line->fulfillment === 'stock' && $item && $item->type !== 'service' && $item->track_stock) {
                    $this->ledger->post([
                        'item_id' => $line->item_id,
                        'branch_id' => $sale->branch_id,
                        'type' => 'sale_void',
                        'quantity' => $line->qty,
                        'signed_qty' => $line->qty,
                        'unit_cost' => $line->unit_cost,
                        'total_cost' => $line->total_cost ?? Bc::mul($line->unit_cost, $line->qty),
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'user_id' => $user?->id,
                        'reason' => 'Void '.$sale->number,
                    ]);
                }
            }
            foreach ($orders as $order) {
                if (in_array($order->status, ['draft', 'planned'], true)) {
                    $this->production->cancel($order->fresh(), 'Sale '.$sale->number.' voided.');
                }
            }

            $accountPaid = '0.0000';
            foreach (SalePayment::query()->where('sale_id', $sale->id)->where('method', 'account')->get() as $payment) {
                $accountPaid = Bc::add($accountPaid, $payment->amount);
            }
            if (Bc::cmp($accountPaid, '0') > 0) {
                $customer = $sale->customer_id ? Customer::withTrashed()->whereKey($sale->customer_id)->lockForUpdate()->first() : null;
                if (! $customer) {
                    throw ValidationException::withMessages(['sale' => 'The customer for this credit sale could not be found.']);
                }
                $customer->balance = Bc::sub($customer->balance, $accountPaid);
                $customer->save();
            }

            $before = $sale->toArray();
            $sale->update([
                'status' => 'void',
                'void_reason' => $data['reason'],
                'voided_by' => $user?->id,
                'voided_at' => now(),
            ]);
            $this->audit->tenant('pos.sale.voided', 'sale', $sale->id, $before, $sale->fresh()->toArray());

            return $sale;
        });
    }

    public function returnSale(Sale $sale, array $data): SaleReturn
    {
        return DB::connection('tenant')->transaction(function () use ($sale, $data) {
            $user = Auth::user();
            $constraints = $user ? $this->access->constraintsFor($user, 'pos.sale.refund') : [];
            if (! empty($constraints['requires_approval']) && empty($data['approved_by'])) {
                throw ValidationException::withMessages(['approval' => 'A manager must approve this return.']);
            }
            $return = SaleReturn::query()->create([
                'number' => $this->numbers->next($sale->branch_id, 'return'),
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'reason' => $data['reason'],
                'restock' => (bool) ($data['restock'] ?? true),
                'user_id' => $user?->id,
                'approved_by' => $data['approved_by'] ?? null,
            ]);
            foreach ($data['lines'] as $line) {
                $saleLine = SaleLine::query()->where('sale_id', $sale->id)->findOrFail($line['sale_line_id']);
                $qty = Bc::num($line['qty']);
                if (Bc::cmp($qty, $saleLine->qty) > 0) {
                    throw ValidationException::withMessages(['lines' => 'Return quantity exceeds the quantity sold.']);
                }
                $restock = (bool) ($line['restock'] ?? $data['restock'] ?? true);
                ReturnLine::query()->create([
                    'return_id' => $return->id,
                    'sale_line_id' => $saleLine->id,
                    'qty' => $qty,
                    'restock' => $restock,
                ]);
                $item = Item::withTrashed()->findOrFail($saleLine->item_id);
                if ($restock && $saleLine->fulfillment === 'stock' && $item->type !== 'service' && $item->track_stock) {
                    $this->ledger->post([
                        'item_id' => $saleLine->item_id,
                        'branch_id' => $sale->branch_id,
                        'type' => 'sale_return',
                        'quantity' => $qty,
                        'signed_qty' => $qty,
                        'unit_cost' => $saleLine->unit_cost,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'user_id' => $user?->id,
                        'reason' => $data['reason'],
                    ]);
                }
            }
            $this->audit->tenant('pos.return.created', 'return', $return->id, null, ['sale' => $sale->number]);

            return $return;
        });
    }

    private function taxPercent(Item $item): string
    {
        $rate = $item->tax_rate_id ? TaxRate::query()->find($item->tax_rate_id) : TaxRate::query()->where('is_default', true)->first();

        return Bc::num($rate->rate ?? 0);
    }

    private function guardDiscount(?User $user, string $unitPrice, string $qty, string $discount, ?string $pin): void
    {
        if (! $user || Bc::cmp($discount, '0') <= 0) {
            return;
        }
        $base = Bc::mul($unitPrice, $qty);
        if (Bc::cmp($base, '0') <= 0) {
            return;
        }
        $percent = Bc::mul(Bc::div($discount, $base), '100');
        $max = $this->access->constraintsFor($user, 'pos.sale.discount')['max_discount_percent'] ?? null;
        if ($max === null || Bc::cmp($percent, $max) <= 0) {
            return;
        }
        if ($pin && $this->managerApproves($pin, 'pos.sale.discount', $percent)) {
            return;
        }
        throw ValidationException::withMessages([
            'discount' => 'Discount exceeds your limit of '.$max.'%. A manager PIN is required.',
        ]);
    }

    private function managerCanVoid(?string $approverId): bool
    {
        $approver = $approverId ? User::query()->where('status', 'active')->find($approverId) : null;
        if (! $approver || ! $this->access->allows($approver, 'pos.sale.void')) {
            return false;
        }

        return empty($this->access->constraintsFor($approver, 'pos.sale.void')['requires_approval']);
    }

    private function managerApproves(string $pin, string $permission, string $percent): bool
    {
        $candidates = User::query()->where('status', 'active')->whereNotNull('pin_hash')->get();
        foreach ($candidates as $candidate) {
            if (! Hash::check($pin, $candidate->pin_hash)) {
                continue;
            }
            $max = $this->access->constraintsFor($candidate, $permission)['max_discount_percent'] ?? 100;
            if ($this->access->allows($candidate, $permission) && Bc::cmp($percent, $max) <= 0) {
                return true;
            }
        }

        return false;
    }
}

class SettingPrices
{
    public static function includeTax(): bool
    {
        return (bool) Setting::getValue('prices_include_tax', true);
    }
}
