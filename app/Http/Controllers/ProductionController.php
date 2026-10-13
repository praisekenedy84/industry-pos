<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ProductionLine;
use App\Models\ProductionOrder;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeStage;
use App\Models\RecipeVersion;
use App\Models\RunInput;
use App\Models\RunOutput;
use App\Models\RunWaste;
use App\Models\StockBalance;
use App\Models\Unit;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\BatchProduction;
use App\Services\FeatureGate;
use App\Services\ProductionService;
use App\Services\RecipeWriter;
use App\Support\Bc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductionController extends Controller
{
    use ResolvesBranch;

    public function __construct(private ProductionService $production, private AuditLogger $audit, private FeatureGate $features, private RecipeWriter $recipes) {}

    public function recipes(Request $request): Response
    {
        $this->authorize('recipes.view');
        $costs = StockBalance::query()->where('branch_id', $this->branchId())->whereNull('lot_id')->pluck('avg_cost', 'item_id');

        return Inertia::render('Production/Recipes', [
            'recipes' => Recipe::query()->latest()->get(),
            'units' => Unit::query()->orderBy('name')->get(['id', 'name', 'symbol']),
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku', 'type', 'track_stock', 'base_unit_id'])->map(fn (Item $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'type' => $item->type,
                'track_stock' => $item->track_stock,
                'base_unit_id' => $item->base_unit_id,
                'unit_cost' => Bc::num($costs[$item->id] ?? 0),
            ]),
            'canCreateItems' => $request->user()->can('catalog.items.create'),
            'canSetPrices' => $request->user()->can('catalog.items.create') || $request->user()->can('catalog.items.update') || $request->user()->can('catalog.prices.manage'),
        ]);
    }

    public function showRecipe(string $workspace, Recipe $recipe): Response
    {
        $this->authorize('recipes.view');
        $version = RecipeVersion::query()->where('recipe_id', $recipe->id)->where('version', $recipe->current_version)->first();

        return Inertia::render('Production/RecipeForm', [
            'recipe' => $recipe,
            'version' => $version,
            'lines' => $version ? RecipeLine::query()->where('recipe_version_id', $version->id)->get() : [],
            'costs' => $version ? RecipeCostLine::query()->where('recipe_version_id', $version->id)->get() : [],
            'stages' => $version ? RecipeStage::query()->where('recipe_version_id', $version->id)->orderBy('position')->get() : [],
            'units' => Unit::query()->get(['id', 'name', 'symbol'])->keyBy('id'),
            'history' => RecipeVersion::query()->where('recipe_id', $recipe->id)->orderByDesc('version')->get(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku', 'type']),
            'multiStage' => $this->features->active('production.multi_stage'),
        ]);
    }

    public function storeRecipe(Request $request): RedirectResponse
    {
        $this->authorize('recipes.manage');
        $recipe = $this->writeRecipe(new Recipe, $request);

        return redirect()->route('production.recipes.show', ['recipe' => $recipe])->with('success', 'Recipe saved.');
    }

    public function updateRecipe(Request $request, string $workspace, Recipe $recipe): RedirectResponse
    {
        $this->authorize('recipes.manage');
        $this->writeRecipe($recipe, $request, true);
        $this->audit->tenant('recipe.versioned', 'recipe', $recipe->id, null, ['version' => $recipe->current_version]);

        return back()->with('success', 'New recipe version saved. Past runs keep the old version.');
    }

    public function orders(Request $request): Response
    {
        $this->authorize('production.orders.view');

        $this->production->backfillFinished($this->branchId());

        return Inertia::render('Production/Orders', [
            'orders' => ProductionOrder::query()->where('branch_id', $this->branchId())->with(['item', 'recipe.item', 'run.waste'])->latest()->paginate(20),
            'products' => Item::query()->where('track_stock', true)->where('type', 'finished_good')->whereNotNull('base_unit_id')->orderBy('name')->get(['id', 'name', 'base_unit_id']),
            'selectedProduct' => $request->string('item_id')->toString(),
            'units' => Unit::query()->get(['id', 'name', 'symbol'])->keyBy('id'),
            'lines' => ProductionLine::query()->where('branch_id', $this->branchId())->where('status', 'active')->with('recipe.item')->orderBy('name')->get(),
            'canManage' => $request->user()->can('production.orders.manage'),
        ]);
    }

    public function storeBatch(Request $request, BatchProduction $batches): RedirectResponse
    {
        $this->authorize('production.orders.manage');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid', 'exists:tenant.items,id'],
            'planned_qty' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $batches->start([...$data, 'branch_id' => $this->branchId()]);

        return redirect()->route('production.batches.show', ['order' => $order]);
    }

    public function completeBatch(Request $request, string $workspace, ProductionOrder $order, BatchProduction $batches): RedirectResponse
    {
        $this->authorize('production.runs.record', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $batches->complete($order, $request->only(['good_qty', 'waste_qty', 'waste_unit_id', 'waste_reason', 'notes', 'actual_usage', 'labor_cost', 'overhead_cost', 'waste_cost']));

        return back()->with('success', 'Batch completed. Good output is available to sell.');
    }

    public function reverseBatch(Request $request, string $workspace, ProductionOrder $order, BatchProduction $batches): RedirectResponse
    {
        $this->authorize('production.orders.manage', $order);
        $this->authorize('production.runs.record', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $batches->reverse($order, $data['reason']);

        return back()->with('success', 'Batch reversed. Start a new batch with the corrected quantities.');
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $this->authorize('production.orders.manage');
        $this->assertBranchCanTransact();
        $data = $request->validate([
            'recipe_id' => ['required', 'uuid', 'exists:tenant.recipes,id'],
            'production_line_id' => ['required', 'uuid', 'exists:tenant.production_lines,id'],
            'planned_qty' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'notes' => ['nullable', 'string'],
            'lot_code' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'lot_attributes' => ['nullable', 'array'],
            'lot_attributes.*' => ['nullable', 'string', 'max:500'],
        ]);
        $order = $this->production->plan([...$data, 'branch_id' => $this->branchId(), 'assignee_id' => $request->user()->id]);

        return redirect()->route('production.orders.show', ['order' => $order]);
    }

    public function showOrder(string $workspace, ProductionOrder $order): Response
    {
        $this->authorize('production.orders.view', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);

        return Inertia::render('Production/OrderShow', [
            'order' => $order->load(['productionLine', 'item', 'recipe.item']),
            'units' => Unit::query()->get(['id', 'name', 'symbol'])->keyBy('id'),
            'waste' => RunWaste::query()->whereIn('production_run_id', ProductionRun::query()->where('production_order_id', $order->id)->select('id'))->get(),
            'journal' => DB::connection('tenant')->table('production_journals')->where('kind', 'completion')->whereIn('production_run_id', ProductionRun::query()->where('production_order_id', $order->id)->select('id'))->first(),
            'reversal' => DB::connection('tenant')->table('production_journals')->where('kind', 'reversal')->whereIn('production_run_id', ProductionRun::query()->where('production_order_id', $order->id)->select('id'))->first(),
            'journalLines' => DB::connection('tenant')->table('production_journal_lines')->whereIn('production_journal_id', DB::connection('tenant')->table('production_journals')->whereIn('production_run_id', ProductionRun::query()->where('production_order_id', $order->id)->select('id'))->select('id'))->get(),
            'canManage' => auth()->user()->can('production.orders.manage'),
            'canRecord' => auth()->user()->can('production.runs.record'),
            'recipe' => Recipe::query()->withTrashed()->find($order->recipe_id),
            'recipeVersion' => RecipeVersion::query()->find($order->recipe_version_id),
            'recipeLines' => RecipeLine::query()->where('recipe_version_id', $order->recipe_version_id)->get(),
            'recipeCosts' => RecipeCostLine::query()->where('recipe_version_id', $order->recipe_version_id)->get(),
            'run' => ProductionRun::query()->where('production_order_id', $order->id)->first(),
            'recordedInputs' => RunInput::query()->whereIn('production_run_id', ProductionRun::query()->where('production_order_id', $order->id)->select('id'))->get(),
            'output' => RunOutput::query()->where('run_number', $order->number)->where('branch_id', $order->branch_id)->first(),
            'history' => AuditLog::query()->where('auditable_type', 'production_order')->where('auditable_id', $order->id)->where('action', 'production.status_changed')->orderBy('created_at')->get(),
            'people' => User::query()->pluck('name', 'id'),
            'destinations' => Branch::query()->whereIn('id', app(AccessManager::class)->visibleBranchIds(auth()->user(), 'inventory.transfer'))->where('id', '!=', $order->branch_id)->where('status', 'active')->get(['id', 'name']),
            'canTransfer' => auth()->user()->can('inventory.transfer') && $this->features->active('branches.multi_branch'),
            'requirements' => $this->production->requirements($order),
            'estimate' => $this->production->estimate($order),
            'items' => Item::withTrashed()->get(['id', 'name', 'sku', 'base_unit_id'])->keyBy('id'),
            'stages' => RecipeStage::query()->where('recipe_version_id', $order->recipe_version_id)->orderBy('position')->get(),
            'multiStage' => $this->features->active('production.multi_stage'),
        ]);
    }

    public function complete(Request $request, string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.runs.record', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact($order->branch_id);
        $data = $this->completionData($request);
        $this->production->complete($order, array_filter($data, fn ($value) => $value !== null));

        return back()->with('success', 'Production posted. Stock and cost are updated.');
    }

    public function start(string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.orders.manage', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $this->production->start($order);

        return back()->with('success', 'Production batch started.');
    }

    public function cancel(string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.orders.manage', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $this->production->cancel($order);

        return back()->with('success', 'Production order cancelled.');
    }

    public function hold(Request $request, string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.orders.manage', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $this->production->hold($order, $data['notes'] ?? null);

        return back()->with('success', 'Production run put on hold.');
    }

    public function resume(string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.orders.manage', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $this->production->resume($order);

        return back()->with('success', 'Production run resumed.');
    }

    public function release(Request $request, string $workspace, ProductionOrder $order): RedirectResponse
    {
        $this->authorize('production.runs.record', $order);
        abort_unless($order->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $data = [...$this->completionData($request), ...$request->validate([
            'destination_branch_id' => ['nullable', 'uuid', 'exists:tenant.branches,id'],
            'handover_note' => ['required_without:destination_branch_id', 'nullable', 'string', 'max:2000'],
        ])];
        if (! empty($data['destination_branch_id'])) {
            $this->authorize('inventory.transfer');
            abort_unless($this->features->active('branches.multi_branch'), 403);
            abort_unless(in_array($data['destination_branch_id'], app(AccessManager::class)->visibleBranchIds($request->user(), 'inventory.transfer'), true), 403);
        }
        $this->production->release($order, array_filter($data, fn ($value) => $value !== null));

        return back()->with('success', 'Production released and handover recorded.');
    }

    private function completionData(Request $request): array
    {
        return $request->validate([
            'good_qty' => ['nullable', 'numeric', 'gt:0'],
            'waste_qty' => ['nullable', 'numeric', 'min:0'],
            'waste_reason' => ['nullable', 'string', 'max:2000'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'labor_hours' => ['nullable', 'numeric', 'min:0'],
            'stages' => ['nullable', 'array'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.recipe_stage_id' => ['nullable', 'uuid', 'exists:tenant.recipe_stages,id'],
            'stages.*.status' => ['nullable', 'in:done,pending,in_progress'],
            'lot_code' => ['nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
        ]);
    }

    public function saveProductRecipe(Request $request, string $workspace, Item $item): RedirectResponse
    {
        $this->authorize('recipes.manage');
        abort_unless($item->type === 'finished_good', 422, 'Only produced products have recipes.');
        DB::connection('tenant')->transaction(function () use ($request, $item): void {
            $item = Item::query()->lockForUpdate()->findOrFail($item->id);
            $recipe = Recipe::query()->where('item_id', $item->id)->oldest()->orderBy('id')->first() ?? new Recipe;
            $request->replace([...$request->only(['yield_qty', 'lines', 'labor', 'overhead', 'notes']), 'item_id' => $item->id, 'name' => $item->name]);
            $saved = $this->writeRecipe($recipe, $request);
            $this->audit->tenant('recipe.versioned', 'recipe', $saved->id, null, ['version' => $saved->current_version]);
        });

        return back()->with('success', 'Recipe saved. Existing batches keep their original recipe.');
    }

    private function writeRecipe(Recipe $recipe, Request $request, bool $version = false): Recipe
    {
        return $this->recipes->save($recipe, $request, $version);
    }
}
