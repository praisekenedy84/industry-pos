<?php

namespace App\Http\Controllers;

use App\Auth\WorkspaceSessionManager;
use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\PaymentMethod;
use App\Models\PosDraft;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Services\ReceiptPdf;
use App\Services\SaleService;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    use ResolvesBranch;

    public function __construct(
        private SaleService $sales,
        private FeatureGate $features,
        private AuditLogger $audit,
        private AccessManager $access,
    ) {}

    public function terminal(Request $request): Response
    {
        $this->authorize('pos.sale.create');
        $branchId = $this->branchId();
        $terminal = $this->resolveTerminal($request, $branchId);

        $stock = StockBalance::query()->where('branch_id', $branchId)->whereNull('lot_id')->pluck('qty', 'item_id');

        return Inertia::render('Pos/Terminal', [
            'items' => Item::query()->where(fn ($query) => $query->whereIn('type', ['finished_good', 'trading_good', 'service'])->orWhereHas('billsOfMaterials'))->withExists('billsOfMaterials as has_recipe')->orderBy('name')->get()->each(function (Item $item) use ($stock): void {
                $item->setAttribute('available_qty', $stock[$item->id] ?? '0');
                $item->setAttribute('is_produced', $item->type === 'finished_good' || ($item->type === 'raw_material' && $item->has_recipe));
            }),
            'prices' => PriceListItem::query()->whereNull('variant_id')->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderByRaw('branch_id IS NULL')->get(['item_id', 'price_list_id', 'price']),
            'defaultPriceListId' => PriceList::query()->where('is_default', true)->value('id'),
            'categories' => Category::query()->orderBy('name')->get(),
            'barcodes' => ItemBarcode::query()->get(),
            'customers' => Customer::query()->orderBy('name')->limit(100)->get(['id', 'name', 'phone', 'price_list_id', 'credit_limit', 'balance']),
            'methods' => PaymentMethod::query()->where('enabled', true)->get(),
            'drafts' => PosDraft::query()->where('branch_id', $branchId)->latest()->limit(12)->get(),
            'terminal' => $terminal,
            'offline' => $this->features->active('pos.offline_mode'),
        ]);
    }

    public function rememberCart(Request $request): JsonResponse
    {
        $this->authorize('pos.sale.create');
        $data = $request->validate(['cart' => ['present', 'array']]);
        $request->session()->put('pos_cart', $data['cart']);
        $device = $request->attributes->get('device');
        $workspace = $request->attributes->get('workspace');
        if ($device && $workspace) {
            app(WorkspaceSessionManager::class)->merge($device, $workspace->tenant_id, ['pos_cart' => $data['cart']]);
        }

        return response()->json(['ok' => true]);
    }

    public function sell(Request $request): RedirectResponse
    {
        $this->authorize('pos.sale.create');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.discount' => ['nullable', 'numeric'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.notes' => ['nullable', 'string'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string'],
            'payments.*.amount' => ['required', 'numeric'],
            'payments.*.reference' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'uuid'],
            'price_list_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string'],
            'approval_pin' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'offline_ref' => ['nullable', 'string'],
        ]);
        $sale = $this->sales->complete([
            ...$data,
            'branch_id' => $this->branchId(),
            'terminal_id' => $this->resolveTerminal($request, $this->branchId())->id,
            'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
        ]);

        return redirect()->route('pos.sales.show', ['sale' => $sale])
            ->with('success', 'Sale '.$sale->number.' completed.')
            ->with('open_receipt', true);
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorize('pos.sale.create');
        abort_unless($this->features->active('pos.offline_mode'), 404);
        $data = $request->validate(['sales' => ['required', 'array']]);
        $results = [];
        foreach ($data['sales'] as $sale) {
            try {
                $created = $this->sales->complete([
                    ...$sale,
                    'branch_id' => $this->branchId(),
                    'idempotency_key' => $sale['idempotency_key'],
                ]);
                $results[] = ['idempotency_key' => $sale['idempotency_key'], 'status' => 'synced', 'number' => $created->number];
            } catch (ValidationException $exception) {
                $results[] = ['idempotency_key' => $sale['idempotency_key'] ?? null, 'status' => 'conflict', 'errors' => $exception->errors()];
            }
        }

        return response()->json(['results' => $results]);
    }

    public function hold(Request $request): RedirectResponse
    {
        $this->authorize('pos.sale.create');
        $data = $request->validate(['name' => ['nullable', 'string'], 'cart' => ['required', 'array']]);
        PosDraft::query()->create([
            'branch_id' => $this->branchId(),
            'user_id' => $request->user()->id,
            'terminal_key' => $request->cookie('pos_terminal'),
            'name' => $data['name'] ?? 'Held cart',
            'cart' => $data['cart'],
        ]);

        return back()->with('success', 'Cart held.');
    }

    public function discardDraft(string $workspace, PosDraft $draft): RedirectResponse
    {
        $this->authorize('pos.sale.create');
        $draft->delete();

        return back();
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('pos.sale.create');
        $code = $request->string('code')->toString();
        $sale = Sale::query()->where('number', $code)->orWhereHas('receipt', fn ($q) => $q->where('number', $code))->first();
        if ($sale) {
            return response()->json(['sale' => $sale->load('lines')]);
        }
        $barcode = ItemBarcode::query()->where('barcode', $code)->first();
        $item = $barcode ? Item::query()->find($barcode->item_id) : Item::query()->where('sku', $code)->first();

        return response()->json(['item' => $item]);
    }

    public function returnSale(Request $request): RedirectResponse
    {
        $this->authorize('pos.sale.refund');
        $data = $request->validate([
            'sale_id' => ['required', 'uuid'],
            'reason' => ['required', 'string'],
            'restock' => ['nullable', 'boolean'],
            'approved_by' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
        ]);
        $sale = Sale::query()->findOrFail($data['sale_id']);
        $this->sales->returnSale($sale, $data);

        return back()->with('success', 'Return posted.');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $permission = $this->salesListPermission($user);
        abort_unless($permission, 403);
        if ($request->input('seller') === '') {
            $request->merge(['seller' => null]);
        }
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'seller' => ['nullable', 'uuid'],
        ]);
        $filters['date'] = $filters['date'] ?? now('Africa/Dar_es_Salaam')->toDateString();
        $filters['seller'] = $filters['seller'] ?? '';
        $branchId = $this->branchId();
        $day = Carbon::parse($filters['date'], 'Africa/Dar_es_Salaam');
        $query = Sale::query()
            ->with(['seller:id,name', 'customer:id,name'])
            ->where('branch_id', $branchId)
            ->whereIn('branch_id', $this->access->visibleBranchIds($user, $permission))
            ->whereBetween('created_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()]);
        if ($this->access->ownOnly($user, $permission)) {
            $query->where('user_id', $user->id);
            $filters['seller'] = $user->id;
        } elseif ($filters['seller']) {
            $query->where('user_id', $filters['seller']);
        }
        $total = (clone $query)->where('status', 'completed')->sum('total');
        $sellers = $this->access->ownOnly($user, $permission)
            ? collect([['id' => $user->id, 'name' => $user->name]])
            : User::query()->whereIn('id', Sale::query()->where('branch_id', $branchId)->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Pos/Sales', [
            'sales' => $query->orderByDesc('created_at')->paginate(20)->withQueryString()->through(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'sold_at' => $sale->created_at->timezone('Africa/Dar_es_Salaam')->format('H:i'),
                'seller' => $sale->seller?->name,
                'customer' => $sale->customer?->name,
                'total' => $sale->total,
                'status' => $sale->status,
                'notes' => $sale->notes,
                'void_reason' => $sale->void_reason,
                'can_update' => $sale->status === 'completed' && $user->can('pos.sale.create', $sale),
                'can_delete' => $sale->status === 'completed' && $user->can('pos.sale.void', $sale),
            ]),
            'total' => Bc::num($total),
            'filters' => $filters,
            'sellers' => $sellers,
            'ownOnly' => $this->access->ownOnly($user, $permission),
            'approvers' => $this->voidApprovers($user),
            'can' => [
                'create' => $user->can('pos.sale.create'),
                'update' => $user->can('pos.sale.create'),
                'delete' => $user->can('pos.sale.void'),
            ],
        ]);
    }

    public function update(Request $request, string $workspace, Sale $sale): RedirectResponse
    {
        abort_unless($sale->branch_id === $this->branchId(), 404);
        $this->authorize('pos.sale.create', $sale);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $this->sales->updateNotes($sale, $data['notes'] ?? null);

        return back()->with('success', 'Sale notes saved.');
    }

    public function destroy(Request $request, string $workspace, Sale $sale): RedirectResponse
    {
        abort_unless($sale->branch_id === $this->branchId(), 404);
        $this->authorize('pos.sale.void', $sale);
        $this->assertBranchCanTransact($sale->branch_id);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'approved_by' => ['nullable', 'uuid'],
        ]);
        $this->sales->void($sale, $data);

        return back()->with('success', 'Sale voided. Stock and customer balances were reversed.');
    }

    public function show(string $workspace, Sale $sale): Response
    {
        $this->authorizeSaleRead($sale);

        return Inertia::render('Pos/SaleShow', [
            'canViewMargin' => auth()->user()->can('reports.margin.view'),
            'sale' => $sale->load(['lines', 'payments', 'receipt', 'seller:id,name']),
            'items' => Item::query()->whereIn('id', $sale->lines->pluck('item_id'))->get()->keyBy('id'),
            'footer' => Setting::getValue('receipt_footer', ''),
        ]);
    }

    public function receipt(string $workspace, Sale $sale): Response
    {
        $this->authorize('pos.sale.create', $sale);

        return Inertia::render('Pos/Receipt', [
            'sale' => $sale->load(['lines', 'payments', 'receipt', 'seller:id,name']),
            'items' => Item::query()->whereIn('id', SaleLine::query()->where('sale_id', $sale->id)->pluck('item_id'))->get()->keyBy('id'),
            'tenant' => app(TenancyManager::class)->tenant(),
            'footer' => Setting::getValue('receipt_footer', ''),
        ]);
    }

    public function receiptPdf(Request $request, string $workspace, Sale $sale, ReceiptPdf $pdf): \Illuminate\Http\Response
    {
        $this->authorizeSaleRead($sale);
        $sale->load(['lines', 'payments', 'receipt', 'seller:id,name']);
        $bytes = $pdf->render(
            $sale,
            (string) app(TenancyManager::class)->tenant()?->name,
            (string) Setting::getValue('receipt_footer', ''),
            Item::query()->whereIn('id', $sale->lines->pluck('item_id'))->pluck('name', 'id')->all(),
        );
        $filename = preg_replace('/[^\w.-]+/', '-', $sale->number).'-receipt.pdf';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function reprint(string $workspace, Sale $sale): RedirectResponse
    {
        $this->authorize('pos.sale.create', $sale);
        $receipt = Receipt::query()->where('sale_id', $sale->id)->firstOrFail();
        $receipt->increment('reprint_count');
        $this->audit->tenant('pos.receipt.reprint', 'receipt', $receipt->id);

        return back()->with('success', 'Reprint recorded.');
    }

    private function salesListPermission(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        if ($user->can('reports.sales.view')) {
            return 'reports.sales.view';
        }
        if ($user->can('pos.sale.create')) {
            return 'pos.sale.create';
        }

        return null;
    }

    private function authorizeSaleRead(Sale $sale): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->can('pos.sale.create', $sale) || $user->can('reports.sales.view', $sale)), 403);
    }

    /**
     * @return Collection<int, User>
     */
    private function voidApprovers(User $user): Collection
    {
        if (empty($this->access->constraintsFor($user, 'pos.sale.void')['requires_approval'])) {
            return collect();
        }

        return User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->filter(function (User $candidate): bool {
            return $this->access->allows($candidate, 'pos.sale.void') && empty($this->access->constraintsFor($candidate, 'pos.sale.void')['requires_approval']);
        })->values();
    }

    private function resolveTerminal(Request $request, string $branchId): Terminal
    {
        $key = $request->cookie('pos_terminal');
        if ($key) {
            $existing = Terminal::query()->find($key);
            if ($existing) {
                return $existing;
            }
        }
        $terminal = Terminal::query()->create([
            'branch_id' => $branchId,
            'name' => 'Terminal '.strtoupper(Str::random(4)),
            'paper_width' => 80,
        ]);
        cookie()->queue(cookie('pos_terminal', $terminal->id, 60 * 24 * 365));

        return $terminal;
    }
}
