<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Services\ProductionService;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class V1Controller extends Controller
{
    public function __construct(private SaleService $sales, private ProductionService $production) {}

    public function items(Request $request): JsonResponse
    {
        $this->scope($request, 'catalog.items.view');

        return response()->json(['data' => Item::query()->orderBy('name')->paginate(50)]);
    }

    public function stock(Request $request): JsonResponse
    {
        $this->scope($request, 'inventory.stock.view');

        return response()->json(['data' => StockBalance::query()->whereNull('lot_id')->paginate(50)]);
    }

    public function customers(Request $request): JsonResponse
    {
        $this->scope($request, 'customers.view');

        return response()->json(['data' => Customer::query()->orderBy('name')->paginate(50)]);
    }

    public function sale(Request $request, Sale $sale): JsonResponse
    {
        $this->scope($request, 'pos.sale.create');

        return response()->json(['data' => $sale->load(['lines', 'payments', 'receipt'])]);
    }

    public function storeSale(Request $request): JsonResponse
    {
        $this->scope($request, 'pos.sale.create');
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'payments' => ['required', 'array', 'min:1'],
            'idempotency_key' => ['required', 'string'],
        ]);
        $sale = $this->sales->complete($data);

        return response()->json(['data' => $sale], 201);
    }

    public function productionOrders(Request $request): JsonResponse
    {
        $this->scope($request, 'production.orders.view');

        return response()->json(['data' => ProductionOrder::query()->latest()->paginate(50)]);
    }

    public function storeProductionOrder(Request $request): JsonResponse
    {
        $this->scope($request, 'production.orders.manage');
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'recipe_id' => ['required', 'uuid'],
            'planned_qty' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json(['data' => $this->production->plan($data)], 201);
    }

    private function scope(Request $request, string $permission): void
    {
        $token = $request->attributes->get('apiToken');
        $scopes = $token->scopes ?? [];
        if (! in_array($permission, $scopes, true) && ! in_array('*', $scopes, true)) {
            abort(403, 'Token is missing the '.$permission.' scope.');
        }
    }
}
