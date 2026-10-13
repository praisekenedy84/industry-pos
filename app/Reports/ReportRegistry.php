<?php

namespace App\Reports;

use App\Models\Expense;
use App\Services\FeatureGate;
use App\Support\Bc;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportRegistry
{
    public function __construct(private FeatureGate $features) {}

    public function catalog(): array
    {
        $rows = [
            ['expenses.register', 'Expenses', 'Expense register', 'reports.expenses.view', null],
            ['expenses.categories', 'Expenses', 'Expenses by category', 'reports.expenses.view', null],
            ['profit.operating', 'Profit', 'Operating result after expenses', 'reports.operating.view', null],
            ['sales.summary', 'Sales', 'Daily sales summary', 'reports.sales.view', null],
            ['sales.items', 'Sales', 'Sales by item', 'reports.sales.view', null],
            ['sales.categories', 'Sales', 'Sales by category', 'reports.sales.view', null],
            ['sales.channels', 'Sales', 'Sales by channel', 'reports.sales.view', 'pricing.multi_channel'],
            ['sales.cashiers', 'Sales', 'Sales by seller', 'reports.sales.view', 'module.pos'],
            ['sales.hours', 'Sales', 'Sales by hour', 'reports.sales.view', 'module.pos'],
            ['sales.customers', 'Sales', 'Sales by customer', 'reports.sales.view', 'module.customers'],
            ['sales.origin', 'Sales', 'Produced vs traded', 'reports.sales.view', null],
            ['sales.discounts', 'Sales', 'Discounts and overrides', 'reports.sales.view', 'module.pos'],
            ['sales.returns', 'Sales', 'Voids, returns and refunds', 'reports.sales.view', 'module.pos'],
            ['sales.receipts', 'Sales', 'Receipt register', 'reports.sales.view', 'module.pos'],
            ['profit.margin', 'Profit', 'Gross margin', 'reports.margin.view', 'reports.advanced'],
            ['profit.recipes', 'Profit', 'Profitability by recipe', 'reports.margin.view', 'module.production'],
            ['profit.price_cost', 'Profit', 'Price vs cost', 'reports.margin.view', 'reports.advanced'],
            ['production.summary', 'Production', 'Production summary', 'reports.production.view', 'module.production'],
            ['production.yield', 'Production', 'Yield and variance', 'reports.production.view', 'module.production'],
            ['production.waste', 'Production', 'Waste and rejects', 'reports.production.view', 'module.production'],
            ['production.journal', 'Production', 'Production accounting journal', 'reports.production.view', 'module.production'],
            ['production.batch_cost', 'Production', 'Batch cost breakdown', 'reports.production.view', 'module.production'],
            ['production.cost_trend', 'Production', 'Recipe cost trend', 'reports.production.view', 'module.production'],
            ['production.people', 'Production', 'Output by person', 'reports.production.view', 'module.production'],
            ['production.stages', 'Production', 'Stage duration', 'reports.production.view', 'production.multi_stage'],
            ['production.materials', 'Production', 'Material requirements', 'reports.production.view', 'module.production'],
            ['production.mto', 'Production', 'Make-to-order backlog', 'reports.production.view', 'module.production'],
            ['inventory.on_hand', 'Inventory', 'Stock on hand', 'reports.inventory.view', null],
            ['inventory.valuation', 'Inventory', 'Stock valuation', 'reports.inventory.view', null],
            ['inventory.movements', 'Inventory', 'Stock movement ledger', 'reports.inventory.view', null],
            ['inventory.card', 'Inventory', 'Stock card', 'reports.inventory.view', null],
            ['inventory.low', 'Inventory', 'Low stock', 'reports.inventory.view', null],
            ['inventory.expiry', 'Inventory', 'Expiry', 'reports.inventory.view', 'inventory.lots_expiry'],
            ['inventory.dead', 'Inventory', 'Dead and slow stock', 'reports.inventory.view', 'reports.advanced'],
            ['inventory.ageing', 'Inventory', 'Stock ageing', 'reports.inventory.view', 'reports.advanced'],
            ['inventory.turnover', 'Inventory', 'Stock turnover', 'reports.inventory.view', 'reports.advanced'],
            ['inventory.adjustments', 'Inventory', 'Adjustments and wastage', 'reports.inventory.view', null],
            ['inventory.counts', 'Inventory', 'Count variance', 'reports.inventory.view', null],
            ['inventory.transfers', 'Inventory', 'Transfers', 'reports.inventory.view', 'branches.multi_branch'],
            ['purchasing.spend', 'Purchasing', 'Purchases by supplier', 'reports.purchasing.view', 'module.purchasing'],
            ['purchasing.status', 'Purchasing', 'Purchase order status', 'reports.purchasing.view', 'module.purchasing'],
            ['purchasing.prices', 'Purchasing', 'Supplier price history', 'reports.purchasing.view', 'module.purchasing'],
            ['purchasing.payables', 'Purchasing', 'Supplier payables', 'reports.purchasing.view', 'module.purchasing'],
            ['purchasing.receipts', 'Purchasing', 'Goods receipt vs PO', 'reports.purchasing.view', 'module.purchasing'],
            ['customers.ageing', 'Customers', 'Receivables ageing', 'reports.customers.view', 'customers.credit'],
            ['customers.statement', 'Customers', 'Customer statement', 'reports.customers.view', 'module.customers'],
            ['customers.exceptions', 'Customers', 'Credit limit exceptions', 'reports.customers.view', 'customers.credit'],
            ['customers.history', 'Customers', 'Purchase history', 'reports.customers.view', 'module.customers'],
            ['cash.methods', 'Cash', 'Payments by method', 'reports.cash.view', 'module.pos'],
            ['cash.reconciliation', 'Cash', 'Payment reconciliation', 'reports.cash.view', 'module.pos'],
            ['tax.summary', 'Tax', 'VAT summary', 'reports.tax.view', null],
            ['tax.detail', 'Tax', 'Tax detail by document', 'reports.tax.view', null],
            ['tax.exempt', 'Tax', 'Exempt and zero-rated', 'reports.tax.view', null],
            ['staff.performance', 'Staff', 'Seller performance', 'reports.staff.view', 'module.pos'],
            ['staff.activity', 'Staff', 'Attendance and shifts', 'reports.staff.view', null],
            ['staff.access', 'Staff', 'User access review', 'reports.staff.view', null],
            ['staff.security', 'Staff', 'Session and security events', 'reports.staff.view', null],
            ['audit.trail', 'Audit', 'Audit trail', 'reports.audit.view', null],
            ['audit.prices', 'Audit', 'Price and cost changes', 'audit.view', null],
            ['audit.api', 'Audit', 'API activity', 'reports.audit.view', 'api.system_users'],
            ['management.branches', 'Management', 'Branch comparison', 'reports.sales.view', 'branches.multi_branch'],
            ['management.snapshot', 'Management', 'Business snapshot', 'reports.margin.view', 'reports.advanced'],
            ['management.eod', 'Management', 'End of day', 'reports.sales.view', null],
            ['management.targets', 'Management', 'Target vs actual', 'reports.sales.view', null],
        ];

        return array_values(array_filter(array_map(function ($row) {
            [$key, $group, $name, $permission, $feature] = $row;
            if ($feature && ! $this->features->active($feature)) {
                return null;
            }

            return compact('key', 'group', 'name', 'permission');
        }, $rows)));
    }

    public function find(string $key): ?array
    {
        return collect($this->catalog())->firstWhere('key', $key);
    }

    public function run(string $key, array $filters): array
    {
        [$from, $to] = $this->range($filters);
        $branch = $filters['branch_id'] ?? null;
        $result = match ($key) {
            'expenses.register', 'expenses.categories' => $this->expenses($key, $from, $to, $branch, $filters['created_by'] ?? null),
            'profit.operating' => $this->operatingResult($from, $to, $branch, $filters['created_by'] ?? null),
            'sales.summary', 'management.eod' => $this->salesSummary($from, $to, $branch),
            'sales.items', 'sales.categories', 'sales.origin' => $this->salesBy($key, $from, $to, $branch),
            'sales.cashiers', 'staff.performance' => $this->byCashier($from, $to, $branch),
            'sales.hours' => $this->byHour($from, $to, $branch),
            'sales.customers', 'customers.history' => $this->byCustomer($from, $to, $branch),
            'sales.discounts' => $this->simple('sale_lines', ['item_id', 'discount', 'gross'], $from, $to, $branch, 'sales'),
            'sales.returns' => $this->plain('returns', ['number', 'reason', 'created_at'], $from, $to, $branch),
            'sales.receipts' => $this->plain('receipts', ['number', 'reprint_count', 'created_at'], $from, $to, $branch),
            'profit.margin', 'profit.price_cost' => $this->margin($from, $to, $branch),
            'profit.recipes', 'production.batch_cost', 'production.yield', 'production.cost_trend' => $this->runs($from, $to, $branch),
            'production.summary', 'production.mto', 'production.people' => $this->orders($from, $to, $branch),
            'production.waste' => $this->productionWaste($from, $to, $branch),
            'production.journal' => $this->productionJournal($from, $to, $branch),
            'production.stages' => $this->plain('run_stage_logs', ['name', 'status', 'started_at', 'ended_at'], $from, $to, null),
            'production.materials' => $this->plain('run_inputs', ['item_id', 'planned_qty', 'actual_qty', 'unit_cost'], $from, $to, null),
            'inventory.on_hand', 'inventory.valuation', 'inventory.low', 'inventory.ageing', 'inventory.turnover', 'inventory.dead' => $this->stock($key, $branch),
            'inventory.movements', 'inventory.card', 'inventory.adjustments' => $this->movements($key, $from, $to, $branch, $filters['item_id'] ?? null),
            'inventory.expiry' => $this->plain('stock_lots', ['item_id', 'lot_code', 'expiry_date', 'qty'], $from, $to, $branch),
            'inventory.counts' => $this->plain('stock_counts', ['number', 'status', 'type', 'created_at'], $from, $to, $branch),
            'inventory.transfers' => $this->plain('transfers', ['number', 'status', 'from_branch_id', 'to_branch_id'], $from, $to, null),
            'purchasing.spend', 'purchasing.status', 'purchasing.receipts' => $this->plain('purchase_orders', ['number', 'status', 'supplier_id', 'created_at'], $from, $to, $branch),
            'purchasing.prices' => $this->plain('supplier_prices', ['supplier_id', 'item_id', 'price'], $from, $to, null),
            'purchasing.payables' => $this->plain('suppliers', ['name', 'balance', 'payment_terms'], $from, $to, null),
            'customers.ageing', 'customers.exceptions', 'customers.statement' => $this->plain('customers', ['name', 'balance', 'credit_limit', 'phone'], $from, $to, null),
            'cash.methods' => $this->payments($from, $to, $branch),
            'cash.reconciliation' => $this->payments($from, $to, $branch),
            'tax.summary', 'tax.detail', 'tax.exempt' => $this->tax($key, $from, $to, $branch),
            'staff.activity', 'staff.security', 'audit.trail', 'audit.prices', 'audit.api' => $this->audit($key, $from, $to),
            'staff.access' => $this->plain('users', ['name', 'email', 'status', 'job_title', 'last_login_at'], $from, $to, null),
            'management.branches' => $this->branches($from, $to),
            'management.snapshot' => $this->salesSummary($from, $to, $branch),
            'management.targets' => $this->plain('targets', ['metric', 'period', 'target_amount'], $from, $to, $branch),
            'sales.channels' => $this->simple('sales', ['price_list_id', 'total', 'tax_total'], $from, $to, $branch, null),
            default => ['columns' => ['note'], 'rows' => []],
        };

        return $result + ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];
    }

    private function expenseQuery(Carbon $from, Carbon $to, ?string $branch, ?string $createdBy): Builder
    {
        return DB::connection('tenant')->table('expenses')
            ->whereBetween('expense_date', [$from->copy()->timezone('Africa/Dar_es_Salaam')->toDateString(), $to->copy()->timezone('Africa/Dar_es_Salaam')->toDateString()])
            ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch))
            ->when($createdBy, fn (Builder $query) => $query->where('created_by', $createdBy));
    }

    private function expenses(string $key, Carbon $from, Carbon $to, ?string $branch, ?string $createdBy): array
    {
        $query = $this->expenseQuery($from, $to, $branch, $createdBy);
        if ($key === 'expenses.categories') {
            $rows = $query->where('status', 'recorded')->groupBy('category')->selectRaw('category, COUNT(*) as entries, SUM(amount) as amount')->orderByDesc('amount')->get();
            foreach ($rows as $row) {
                $row->category = Expense::categories()[$row->category] ?? $row->category;
            }

            return ['columns' => ['category', 'entries', 'amount'], 'rows' => $rows, 'note' => 'Paid operating expenses in TZS. Voided entries are excluded.'];
        }
        $rows = $query->orderBy('expense_date')->orderBy('number')->get(['number', 'expense_date', 'category', 'description', 'payee', 'payment_method', 'reference', 'amount', 'status', 'void_reason']);
        foreach ($rows as $row) {
            $row->category = Expense::categories()[$row->category] ?? $row->category;
        }

        return ['columns' => ['number', 'expense_date', 'category', 'description', 'payee', 'payment_method', 'reference', 'amount', 'status', 'void_reason'], 'rows' => $rows, 'note' => 'Paid operating expenses in TZS. Voided entries remain visible for audit but do not contribute to expense or profit totals.'];
    }

    private function operatingResult(Carbon $from, Carbon $to, ?string $branch, ?string $createdBy): array
    {
        $sales = DB::connection('tenant')->table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to])
            ->when($branch, fn (Builder $query) => $query->where('sales.branch_id', $branch))
            ->when($createdBy, fn (Builder $query) => $query->where('sales.user_id', $createdBy))
            ->selectRaw("COALESCE(SUM(sale_lines.net), 0) as revenue, COALESCE(SUM(COALESCE(sale_lines.total_cost, sale_lines.unit_cost * sale_lines.qty)), 0) as cost, COUNT(*) FILTER (WHERE sale_lines.costing_status = 'pending') as pending")->first();
        $expenses = Bc::num($this->expenseQuery($from, $to, $branch, $createdBy)->where('status', 'recorded')->sum('amount'));
        $grossProfit = Bc::sub($sales->revenue, $sales->cost);
        $productionLoss = Bc::num(DB::connection('tenant')->table('production_journals')->whereBetween('created_at', [$from, $to])
            ->when($branch, fn (Builder $query) => $query->where('branch_id', $branch))
            ->when($createdBy, fn (Builder $query) => $query->where('created_by', $createdBy))->sum('loss_value'));

        return ['columns' => ['revenue', 'cost_of_goods', 'gross_profit', 'operating_expenses', 'production_loss', 'operating_result', 'costing_status'], 'rows' => [[
            'revenue' => Bc::num($sales->revenue), 'cost_of_goods' => $sales->pending ? null : Bc::num($sales->cost), 'gross_profit' => $sales->pending ? null : $grossProfit, 'operating_expenses' => $expenses, 'production_loss' => $productionLoss, 'operating_result' => $sales->pending ? null : Bc::sub(Bc::sub($grossProfit, $expenses), $productionLoss), 'costing_status' => $sales->pending ? 'Pending costing' : 'Costed',
        ]], 'note' => 'TZS. Completed sales excluding sales tax, less cost of goods, paid operating expenses and separately expensed production losses net of reversals. Absorbed waste remains in inventory cost. Expense amounts are deducted in full; input VAT is not calculated. Purchases already included in stock or production costs must not be recorded again as operating expenses.'];
    }

    private function range(array $filters): array
    {
        $tz = 'Africa/Dar_es_Salaam';
        $from = Carbon::parse($filters['from'] ?? now($tz)->toDateString(), $tz)->startOfDay()->utc();
        $to = Carbon::parse($filters['to'] ?? now($tz)->toDateString(), $tz)->endOfDay()->utc();

        return [$from, $to];
    }

    private function salesSummary($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sales')->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        if ($branch) {
            $query->where('branch_id', $branch);
        }
        $row = $query->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal),0) as net, COALESCE(SUM(discount_total),0) as discounts, COALESCE(SUM(tax_total),0) as vat, COALESCE(SUM(total),0) as gross')->first();

        return ['columns' => ['orders', 'net', 'discounts', 'vat', 'gross'], 'rows' => [$row]];
    }

    private function salesBy(string $key, $from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }
        if ($key === 'sales.categories') {
            $query->leftJoin('categories', 'categories.id', '=', 'items.category_id')->groupBy('categories.name')->selectRaw('categories.name as label, SUM(sale_lines.qty) as qty, SUM(sale_lines.gross) as gross');
        } elseif ($key === 'sales.origin') {
            $bills = DB::connection('tenant')->table('recipes')->whereNull('deleted_at')->select('item_id')->distinct();
            $origin = "CASE WHEN items.type = 'service' THEN 'Service' WHEN produced_items.item_id IS NOT NULL THEN 'Produced' ELSE 'Traded' END";
            $query->leftJoinSub($bills, 'produced_items', fn ($join) => $join->on('produced_items.item_id', '=', 'items.id'))
                ->groupByRaw($origin)->selectRaw($origin.' as label, SUM(sale_lines.qty) as qty, SUM(sale_lines.gross) as gross');
        } else {
            $query->groupBy('items.name', 'items.sku')->selectRaw('items.name as label, items.sku, SUM(sale_lines.qty) as qty, SUM(sale_lines.gross) as gross');
        }

        return ['columns' => ['label', 'qty', 'gross'], 'rows' => $query->orderByDesc('gross')->limit(100)->get()];
    }

    private function byCashier($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sales')->join('users', 'users.id', '=', 'sales.user_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }

        return ['columns' => ['name', 'orders', 'gross'], 'rows' => $query->groupBy('users.name')->selectRaw('users.name, COUNT(*) as orders, SUM(sales.total) as gross')->get()];
    }

    private function byHour($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sales')->where('status', 'completed')->whereBetween('created_at', [$from, $to]);
        if ($branch) {
            $query->where('branch_id', $branch);
        }

        return ['columns' => ['hour', 'orders', 'gross'], 'rows' => $query->selectRaw("to_char(created_at AT TIME ZONE 'Africa/Dar_es_Salaam', 'HH24') as hour, COUNT(*) as orders, SUM(total) as gross")->groupBy('hour')->orderBy('hour')->get()];
    }

    private function byCustomer($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }

        return ['columns' => ['name', 'orders', 'gross'], 'rows' => $query->groupBy('customers.name')->selectRaw("COALESCE(customers.name, 'Walk-in') as name, COUNT(*) as orders, SUM(sales.total) as gross")->orderByDesc('gross')->get()];
    }

    private function margin($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }

        return ['columns' => ['name', 'revenue', 'cost', 'margin', 'costing_status'], 'rows' => $query->groupBy('items.id', 'items.name')->selectRaw("items.name, SUM(sale_lines.net) as revenue, CASE WHEN COUNT(*) FILTER (WHERE sale_lines.costing_status = 'pending') > 0 THEN NULL ELSE SUM(COALESCE(sale_lines.total_cost, sale_lines.unit_cost * sale_lines.qty)) END as cost, CASE WHEN COUNT(*) FILTER (WHERE sale_lines.costing_status = 'pending') > 0 THEN NULL ELSE SUM(sale_lines.net - COALESCE(sale_lines.total_cost, sale_lines.unit_cost * sale_lines.qty)) END as margin, CASE WHEN COUNT(*) FILTER (WHERE sale_lines.costing_status = 'pending') > 0 THEN 'Pending costing' ELSE 'Costed' END as costing_status")->orderBy('margin')->limit(100)->get()];
    }

    private function runs($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('production_runs')->join('production_orders', 'production_orders.id', '=', 'production_runs.production_order_id')->whereBetween('production_runs.created_at', [$from, $to]);
        if ($branch) {
            $query->where('production_orders.branch_id', $branch);
        }

        return ['columns' => ['number', 'good_qty', 'waste_qty', 'waste_cost', 'inventory_value', 'loss_value', 'batch_cost', 'unit_cost'], 'rows' => $query->select(['production_orders.number', 'production_runs.good_qty', 'production_runs.waste_qty', 'production_runs.waste_cost', 'production_runs.inventory_value', 'production_runs.loss_value', 'production_runs.batch_cost', 'production_runs.unit_cost'])->get()];
    }

    private function productionWaste(Carbon $from, Carbon $to, ?string $branch): array
    {
        $rows = DB::connection('tenant')->table('run_waste as waste')
            ->join('production_runs as runs', 'runs.id', '=', 'waste.production_run_id')
            ->join('production_orders as batches', 'batches.id', '=', 'runs.production_order_id')
            ->join('items', 'items.id', '=', 'waste.item_id')
            ->leftJoin('units', 'units.id', '=', 'waste.unit_id')
            ->leftJoin('users', 'users.id', '=', 'waste.recorded_by')
            ->whereBetween('runs.created_at', [$from, $to])
            ->when($branch, fn (Builder $query) => $query->where('batches.branch_id', $branch))
            ->select(['batches.number', 'batches.status', 'items.name as product', 'waste.qty', 'units.symbol as unit', 'waste.cost_value', 'waste.reason', 'batches.branch_id', 'waste.recorded_at', 'users.name as person', 'runs.waste_treatment'])
            ->orderByDesc('runs.created_at')->get();

        return ['columns' => ['number', 'status', 'product', 'qty', 'unit', 'cost_value', 'reason', 'branch_id', 'recorded_at', 'person', 'waste_treatment'], 'rows' => $rows, 'note' => 'Original waste records, including reversed batches for audit. Reversed batches are not current losses; use the production journal or operating result for net accounting values. Historical unrecorded costs remain blank.'];
    }

    private function productionJournal(Carbon $from, Carbon $to, ?string $branch): array
    {
        $rows = DB::connection('tenant')->table('production_journals as journals')
            ->join('production_journal_lines as lines', 'lines.production_journal_id', '=', 'journals.id')
            ->join('production_runs as runs', 'runs.id', '=', 'journals.production_run_id')
            ->join('production_orders as batches', 'batches.id', '=', 'runs.production_order_id')
            ->whereBetween('journals.created_at', [$from, $to])
            ->when($branch, fn (Builder $query) => $query->where('journals.branch_id', $branch))
            ->select(['journals.number as journal', 'batches.number as batch', 'journals.created_at', 'lines.account_code', 'lines.debit', 'lines.credit'])->get();

        return ['columns' => ['journal', 'batch', 'created_at', 'account_code', 'debit', 'credit'], 'rows' => $rows];
    }

    private function orders($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('production_orders')->whereBetween('created_at', [$from, $to]);
        if ($branch) {
            $query->where('branch_id', $branch);
        }

        return ['columns' => ['number', 'status', 'planned_qty', 'created_by'], 'rows' => $query->get(['number', 'status', 'planned_qty', 'created_by'])];
    }

    private function stock(string $key, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('stock_balances')->join('items', 'items.id', '=', 'stock_balances.item_id')->whereNull('stock_balances.lot_id');
        if ($branch) {
            $query->where('stock_balances.branch_id', $branch);
        }
        if ($key === 'inventory.low') {
            $query->whereColumn('stock_balances.qty', '<=', 'items.reorder_level')->where('items.reorder_level', '>', 0);
        }

        return ['columns' => ['name', 'sku', 'qty', 'value', 'avg_cost'], 'rows' => $query->get(['items.name', 'items.sku', 'stock_balances.qty', 'stock_balances.value', 'stock_balances.avg_cost'])];
    }

    private function movements(string $key, $from, $to, ?string $branch, ?string $itemId): array
    {
        $query = DB::connection('tenant')->table('stock_movements')->whereBetween('created_at', [$from, $to]);
        if ($branch) {
            $query->where('branch_id', $branch);
        }
        if ($itemId) {
            $query->where('item_id', $itemId);
        }
        if ($key === 'inventory.adjustments') {
            $query->whereIn('type', ['adjustment', 'wastage']);
        }

        return ['columns' => ['type', 'quantity', 'signed_qty', 'unit_cost', 'total_cost', 'reason', 'created_at'], 'rows' => $query->orderByDesc('created_at')->limit(300)->get(['type', 'quantity', 'signed_qty', 'unit_cost', 'total_cost', 'reason', 'created_at'])];
    }

    private function payments($from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }

        return ['columns' => ['method', 'amount'], 'rows' => $query->groupBy('method')->selectRaw('method, SUM(amount) as amount')->get()];
    }

    private function tax(string $key, $from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to]);
        if ($branch) {
            $query->where('sales.branch_id', $branch);
        }
        if ($key === 'tax.exempt') {
            $query->where('sale_lines.tax_rate', 0);
        }
        if ($key === 'tax.detail') {
            return ['columns' => ['number', 'net', 'tax_rate', 'tax_amount', 'gross'], 'rows' => $query->select(['sales.number', 'sale_lines.net', 'sale_lines.tax_rate', 'sale_lines.tax_amount', 'sale_lines.gross'])->limit(300)->get()];
        }

        return ['columns' => ['tax_rate', 'net', 'vat', 'gross'], 'rows' => $query->groupBy('sale_lines.tax_rate')->selectRaw('sale_lines.tax_rate, SUM(sale_lines.net) as net, SUM(sale_lines.tax_amount) as vat, SUM(sale_lines.gross) as gross')->get()];
    }

    private function audit(string $key, $from, $to): array
    {
        $query = DB::connection('tenant')->table('audit_logs')->whereBetween('created_at', [$from, $to]);
        if ($key === 'staff.security') {
            $query->where('action', 'ilike', 'auth.%');
        }
        if ($key === 'audit.api') {
            $query->where('action', 'api.request');
        }
        if ($key === 'audit.prices') {
            $query->where('action', 'ilike', 'catalog.%');
        }

        return ['columns' => ['action', 'actor_type', 'auditable_type', 'created_at'], 'rows' => $query->orderByDesc('created_at')->limit(200)->get(['action', 'actor_type', 'auditable_type', 'created_at'])];
    }

    private function branches($from, $to): array
    {
        return ['columns' => ['name', 'orders', 'gross'], 'rows' => DB::connection('tenant')->table('sales')->join('branches', 'branches.id', '=', 'sales.branch_id')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$from, $to])->groupBy('branches.name')->selectRaw('branches.name, COUNT(*) as orders, SUM(sales.total) as gross')->get()];
    }

    private function plain(string $table, array $columns, $from, $to, ?string $branch): array
    {
        $query = DB::connection('tenant')->table($table);
        if ($this->hasColumn($table, 'created_at')) {
            $query->whereBetween('created_at', [$from, $to]);
        }
        if ($branch && $this->hasColumn($table, 'branch_id')) {
            $query->where('branch_id', $branch);
        }

        return ['columns' => $columns, 'rows' => $query->limit(200)->get($columns)];
    }

    private function simple(string $table, array $columns, $from, $to, ?string $branch, ?string $parent): array
    {
        if ($parent) {
            $query = DB::connection('tenant')->table($table)->join($parent, $parent.'.id', '=', $table.'.'.rtrim($parent, 's').'_id');
            $query->whereBetween($parent.'.created_at', [$from, $to]);
            if ($branch) {
                $query->where($parent.'.branch_id', $branch);
            }

            return ['columns' => $columns, 'rows' => $query->limit(200)->get(array_map(fn ($c) => $table.'.'.$c, $columns))];
        }

        return $this->plain($table, $columns, $from, $to, $branch);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, ['created_at', 'branch_id'], true) && DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
    }
}
