<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\FeatureGate;
use App\Support\Paths;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use ResolvesBranch;

    public function __invoke(Request $request, AccessManager $access, FeatureGate $features): Response
    {
        $user = $request->user();
        $branchId = $this->branchId();
        $start = now('Africa/Dar_es_Salaam')->startOfDay()->utc();
        $end = now('Africa/Dar_es_Salaam')->endOfDay()->utc();
        $canSales = $access->allows($user, 'reports.sales.view');
        $canMargin = $access->allows($user, 'reports.margin.view');
        $sales = $canSales ? Sale::query()->where('branch_id', $branchId)->where('status', 'completed')->whereBetween('created_at', [$start, $end]) : null;

        return Inertia::render('Dashboard', [
            'metrics' => [
                'sales' => $sales ? $sales->sum('total') : null,
                'orders' => $sales ? (clone $sales)->count() : null,
                'production' => $features->active('module.production') && $access->allows($user, 'production.orders.view')
                    ? ProductionOrder::query()->where('branch_id', $branchId)->whereIn('status', ['completed', 'released'])->whereBetween('completed_at', [$start, $end])->count()
                    : null,
                'low_stock' => $access->allows($user, 'inventory.stock.view') ? $this->lowStock($branchId) : [],
                'expiring' => $features->active('inventory.lots_expiry') ? StockLot::query()->where('branch_id', $branchId)->whereDate('expiry_date', '<=', now()->addDays(14))->where('qty', '>', 0)->limit(8)->get() : [],
                'sellers' => $sales ? (clone $sales)->distinct()->count('user_id') : null,
                'approvals' => $access->allows($user, 'approvals.resolve')
                    ? ApprovalRequest::query()->where('status', 'pending')->limit(8)->get() : [],
                'receivables' => $features->active('customers.credit') && $access->allows($user, 'customers.view')
                    ? Customer::query()->where('balance', '>', 0)->sum('balance') : null,
                'margin' => $canMargin ? $this->margin($branchId, $start, $end) : null,
            ],
            'checklist' => $this->checklist($features),
            'top' => $canSales ? $this->topSellers($branchId, $start, $end) : [],
            'trend' => $canSales ? $this->salesTrend($branchId) : null,
        ]);
    }

    /** @return array<int, array{date: string, sales: string, orders: int}> */
    private function salesTrend(string $branchId): array
    {
        $today = CarbonImmutable::now('Africa/Dar_es_Salaam')->startOfDay();
        $firstDay = $today->subDays(29);
        $daily = Sale::query()
            ->where('branch_id', $branchId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$firstDay->utc(), $today->endOfDay()->utc()])
            ->selectRaw("(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Africa/Dar_es_Salaam')::date as day, SUM(total) as sales, COUNT(*) as orders")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $trend = [];
        for ($offset = 0; $offset < 30; $offset++) {
            $date = $firstDay->addDays($offset)->toDateString();
            $row = $daily->get($date);
            $trend[] = ['date' => $date, 'sales' => (string) ($row?->sales ?? '0'), 'orders' => (int) ($row?->orders ?? 0)];
        }

        return $trend;
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $items = Item::query()->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")->limit(8)->get(['id', 'name', 'sku']);
        $sales = Sale::query()->where('number', 'ilike', "%{$q}%")->limit(5)->get(['id', 'number']);

        return response()->json([
            'results' => [
                ...$items->map(fn ($item) => ['type' => 'item', 'label' => $item->name, 'href' => Paths::to('/catalog/items/'.$item->id.'/edit')]),
                ...$sales->map(fn ($sale) => ['type' => 'sale', 'label' => $sale->number, 'href' => Paths::to('/pos/sales/'.$sale->id)]),
            ],
        ]);
    }

    public function onboarding(): Response
    {
        return Inertia::render('Onboarding', ['checklist' => $this->checklist(app(FeatureGate::class))]);
    }

    public function completeOnboarding(Request $request): RedirectResponse
    {
        Setting::putValue('onboarding_dismissed', true);

        return redirect()->route('dashboard');
    }

    private function lowStock(string $branchId): array
    {
        return DB::connection('tenant')->table('items')
            ->join('stock_balances', function ($join) use ($branchId) {
                $join->on('stock_balances.item_id', '=', 'items.id')->where('stock_balances.branch_id', $branchId)->whereNull('stock_balances.lot_id');
            })
            ->whereColumn('stock_balances.qty', '<=', 'items.reorder_level')
            ->where('items.reorder_level', '>', 0)
            ->limit(8)
            ->get(['items.id', 'items.name', 'items.sku', 'stock_balances.qty', 'items.reorder_level'])
            ->all();
    }

    private function margin(string $branchId, $start, $end): ?string
    {
        $row = DB::connection('tenant')->table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw("CASE WHEN COUNT(*) FILTER (WHERE sale_lines.costing_status = 'pending') > 0 THEN NULL ELSE COALESCE(SUM(sale_lines.net - COALESCE(sale_lines.total_cost, sale_lines.unit_cost * sale_lines.qty)), 0) END as margin")
            ->first();

        return $row->margin === null ? null : (string) $row->margin;
    }

    private function topSellers(string $branchId, $start, $end): array
    {
        return DB::connection('tenant')->table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.branch_id', $branchId)
            ->where('sales.status', 'completed')
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('items.id', 'items.name')
            ->orderByDesc(DB::raw('SUM(sale_lines.gross)'))
            ->limit(5)
            ->get(['items.name', DB::raw('SUM(sale_lines.qty) as qty'), DB::raw('SUM(sale_lines.gross) as gross')])
            ->all();
    }

    private function checklist(FeatureGate $features): array
    {
        return [
            'dismissed' => (bool) Setting::getValue('onboarding_dismissed', false),
            'steps' => [
                ['key' => 'branch', 'done' => Branch::query()->exists(), 'href' => Paths::to('/settings/branches'), 'label' => 'checklist.branch'],
                ['key' => 'item', 'done' => Item::query()->exists(), 'href' => Paths::to('/catalog/items'), 'label' => 'checklist.item'],
                ['key' => 'staff', 'done' => User::query()->count() > 1, 'href' => Paths::to('/settings/users'), 'label' => 'checklist.staff'],
                ['key' => 'recipe', 'done' => ! $features->active('module.production') || Recipe::query()->exists(), 'href' => Paths::to('/production/recipes'), 'label' => 'checklist.recipe'],
                ['key' => 'sale', 'done' => ! $features->active('module.pos') || Sale::query()->exists(), 'href' => Paths::to('/pos'), 'label' => 'checklist.sale'],
            ],
        ];
    }
}
