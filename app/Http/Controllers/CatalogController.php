<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\Category;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\ItemVariant;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\BatchProduction;
use App\Services\FeatureGate;
use App\Services\RecipeWriter;
use App\Services\SkuGenerator;
use App\Services\StockReceipt;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    use ResolvesBranch;

    public function __construct(private AuditLogger $audit, private FeatureGate $features, private SkuGenerator $skus) {}

    public function items(Request $request): Response
    {
        $this->authorize('catalog.items.view');
        $q = $request->string('q')->toString();
        $canRecipe = $request->user()->can('recipes.manage') && $this->features->active('module.production');
        $items = Item::query()
            ->when($request->boolean('archived'), fn ($query) => $query->onlyTrashed())
            ->when($q, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Catalog/Items', [
            'items' => $items,
            'categories' => Category::query()->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'canCreate' => $request->user()->can('catalog.items.create'),
            'canEdit' => $request->user()->can('catalog.items.update'),
            'canArchive' => $request->user()->can('catalog.items.delete'),
            'canRecipe' => $canRecipe,
            'ingredients' => $canRecipe ? Item::query()->where('track_stock', true)->where('type', 'raw_material')->whereNotNull('base_unit_id')->orderBy('name')->get(['id', 'name', 'base_unit_id']) : [],
            'canStart' => $request->user()->can('production.orders.manage') && $this->features->active('module.production'),
            'canReceive' => $request->user()->can('inventory.adjust'),
            'filters' => ['q' => $q, 'type' => $request->string('type')->toString(), 'archived' => $request->boolean('archived')],
        ]);
    }

    public function editItem(string $workspace, Item $item, BatchProduction $batches): Response
    {
        $this->authorize('catalog.items.view');

        $canViewRecipe = (auth()->user()->can('recipes.view') || auth()->user()->can('recipes.manage')) && $this->features->active('module.production');
        $canViewStock = auth()->user()->can('inventory.stock.view') || auth()->user()->can('inventory.adjust');
        $recipe = Recipe::query()->where('item_id', $item->id)->oldest()->orderBy('id')->first();
        $version = $recipe ? RecipeVersion::query()->where('recipe_id', $recipe->id)->where('version', $recipe->current_version)->first() : null;
        $receipts = GoodsReceiptLine::query()->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('goods_receipt_lines.item_id', $item->id)->where('goods_receipts.branch_id', $this->branchId())
            ->orderByDesc('goods_receipts.created_at')->orderByDesc('goods_receipts.number')->limit(20)
            ->get(['goods_receipt_lines.*', 'goods_receipts.number', 'goods_receipts.reference', 'goods_receipts.notes', 'goods_receipts.created_at', 'goods_receipts.user_id']);

        return Inertia::render('Catalog/ItemForm', [
            'item' => $item->load([]),
            'variants' => ItemVariant::query()->where('item_id', $item->id)->get(),
            'barcodes' => ItemBarcode::query()->where('item_id', $item->id)->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'taxes' => TaxRate::query()->orderBy('name')->get(),
            'prices' => PriceListItem::query()->where('item_id', $item->id)->get(),
            'priceLists' => PriceList::query()->get(),
            'wholesalePrice' => PriceListItem::query()->whereIn('price_list_id', PriceList::query()->where('channel', 'wholesale')->select('id'))->where('item_id', $item->id)->whereNull('branch_id')->whereNull('variant_id')->value('price'),
            'recipe' => $canViewRecipe ? $recipe : null,
            'recipeVersion' => $canViewRecipe ? $version : null,
            'recipeEstimate' => $canViewRecipe && $version ? $batches->estimateRecipe($version, $this->branchId()) : null,
            'recipeLines' => $canViewRecipe && $version ? RecipeLine::query()->where('recipe_version_id', $version->id)->get() : [],
            'recipeCosts' => $canViewRecipe && $version ? RecipeCostLine::query()->where('recipe_version_id', $version->id)->get() : [],
            'ingredients' => $canViewRecipe ? Item::query()->where('track_stock', true)->where('type', '!=', 'service')->where('id', '!=', $item->id)->whereNotNull('base_unit_id')->orderBy('name')->get(['id', 'name', 'base_unit_id']) : [],
            'recipeHistory' => $canViewRecipe && $recipe ? RecipeVersion::query()->where('recipe_id', $recipe->id)->orderByDesc('version')->get(['id', 'version', 'yield_qty', 'created_at']) : [],
            'stockReceipts' => $canViewStock ? $receipts->values() : [],
            'latestPurchaseCost' => $canViewStock ? $receipts->first()?->unit_cost : null,
            'availableQty' => $canViewStock ? (StockBalance::query()->where('item_id', $item->id)->where('branch_id', $this->branchId())->whereNull('lot_id')->value('qty') ?? '0') : null,
            'suppliers' => $canViewStock ? Supplier::query()->orderBy('name')->get(['id', 'name']) : [],
            'canViewRecipe' => $canViewRecipe,
            'canViewStock' => $canViewStock,
            'legacyCostNeeded' => ProductionOrder::query()->where('item_id', $item->id)->whereNull('recipe_version_id')->whereNull('stock_posted_at')->whereIn('status', ['planned', 'in_progress', 'on_hold'])->exists(),
            'canCreate' => auth()->user()->can('catalog.items.create'),
            'canEdit' => auth()->user()->can('catalog.items.update'),
            'canRecipe' => auth()->user()->can('recipes.manage') && $this->features->active('module.production'),
            'canStart' => auth()->user()->can('production.orders.manage') && $this->features->active('module.production'),
            'canReceive' => auth()->user()->can('inventory.adjust'),
            'canArchive' => auth()->user()->can('catalog.items.delete'),
        ]);
    }

    public function addStock(Request $request, string $workspace, Item $item, StockReceipt $receipts, AccessManager $access): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $this->assertBranchCanTransact();
        $receipts->receive($item, $this->branchId(), $request->only(['request_id', 'qty', 'unit_cost', 'supplier_id', 'reference', 'notes']), $access->constraintsFor($request->user(), 'inventory.adjust')['max_adjustment_value'] ?? null);

        return back()->with('success', 'Stock received. Selling prices are unchanged.');
    }

    public function estimateMixture(Request $request, BatchProduction $batches): JsonResponse
    {
        $this->authorize('recipes.manage');
        $data = $request->validate([
            'yield_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('tenant.items', 'id')->where('track_stock', true)->where('type', '!=', 'service')->whereNotNull('base_unit_id')->whereNull('deleted_at')],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'lines.*.unit_id' => ['nullable', 'uuid', 'exists:tenant.units,id'],
            'labor' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'overhead' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
        ]);

        return response()->json($batches->estimateMixture($data, $this->branchId()));
    }

    public function storeItem(Request $request, RecipeWriter $recipes): RedirectResponse
    {
        $this->authorize('catalog.items.create');
        $this->guardItemLimit();
        $request->validate(['recipe' => ['nullable', 'array']]);
        if ($request->filled('recipe')) {
            $this->authorize('recipes.manage');
            abort_unless($this->features->active('module.production'), 403);
            if ($request->input('type') !== 'finished_good') {
                throw ValidationException::withMessages(['recipe' => 'Only produced products have a recipe.']);
            }
        }
        $item = DB::connection('tenant')->transaction(function () use ($request, $recipes): Item {
            $item = $this->saveItem(new Item, $request);
            if ($request->filled('recipe')) {
                $recipeRequest = clone $request;
                $recipeRequest->replace([...collect($request->input('recipe'))->only(['yield_qty', 'lines', 'labor', 'overhead'])->all(), 'item_id' => $item->id, 'name' => $item->name]);
                $recipe = $recipes->save(new Recipe, $recipeRequest);
                $this->audit->tenant('recipe.versioned', 'recipe', $recipe->id, null, ['version' => $recipe->current_version]);
            }
            $this->audit->tenant('catalog.item.created', 'item', $item->id, null, $item->toArray());

            return $item;
        });

        return redirect()->route('catalog.items.edit', $item)->with('success', 'Item saved.');
    }

    public function updateItem(Request $request, string $workspace, Item $item): RedirectResponse
    {
        $this->authorize('catalog.items.update');
        $before = $item->toArray();
        $this->saveItem($item, $request);
        $this->audit->tenant('catalog.item.updated', 'item', $item->id, $before, $item->fresh()->toArray());

        return back()->with('success', 'Item updated.');
    }

    public function destroyItem(string $workspace, Item $item): RedirectResponse
    {
        $this->authorize('catalog.items.delete');
        DB::connection('tenant')->transaction(function () use ($item): void {
            $item->delete();
            $this->audit->tenant('catalog.item.deleted', 'item', $item->id);
        });

        return redirect()->route('catalog.items')->with('success', 'Item archived.');
    }

    public function restoreItem(string $workspace, Item $item): RedirectResponse
    {
        $this->authorize('catalog.items.delete');
        DB::connection('tenant')->transaction(function () use ($item): void {
            $item = Item::withTrashed()->lockForUpdate()->findOrFail($item->id);
            if ($item->trashed()) {
                $this->guardItemLimit();
                $item->restore();
                $this->audit->tenant('catalog.item.restored', 'item', $item->id);
            }
        });

        return back()->with('success', 'Item restored.');
    }

    public function categories(): Response
    {
        $this->authorize('catalog.items.view');

        return Inertia::render('Catalog/Categories', ['categories' => Category::withTrashed()->orderBy('name')->get(), 'canCreate' => auth()->user()->can('catalog.items.create'), 'canEdit' => auth()->user()->can('catalog.items.update'), 'canDelete' => auth()->user()->can('catalog.items.delete')]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('catalog.items.create');
        $data = $request->validate([
            'name' => ['required', 'string'],
            'name_alt' => ['nullable', 'string'],
            'color' => ['nullable', 'string'],
        ]);
        Category::query()->create($data);

        return back()->with('success', 'Category saved.');
    }

    public function units(): Response
    {
        $this->authorize('catalog.items.view');
        $units = Unit::query()->orderBy('name')->get();

        return Inertia::render('Catalog/Units', [
            'units' => $units,
            'conversions' => UnitConversion::query()->get()->map(function (UnitConversion $conversion) use ($units): array {
                $from = $units->firstWhere('id', $conversion->from_unit_id);
                $to = $units->firstWhere('id', $conversion->to_unit_id);

                return [
                    'id' => $conversion->id,
                    'from_unit_id' => $conversion->from_unit_id,
                    'to_unit_id' => $conversion->to_unit_id,
                    'from_symbol' => $from?->symbol,
                    'to_symbol' => $to?->symbol,
                    'factor' => $conversion->factor,
                ];
            })->values(),
            'canCreate' => auth()->user()->can('catalog.items.create'),
            'canEdit' => auth()->user()->can('catalog.items.update'),
            'canDelete' => auth()->user()->can('catalog.items.delete'),
        ]);
    }

    public function updateCategory(Request $request, string $workspace, Category $category): RedirectResponse
    {
        $this->authorize('catalog.items.update');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'name_alt' => ['nullable', 'string', 'max:255'], 'color' => ['nullable', 'string', 'max:32']]);
        DB::connection('tenant')->transaction(function () use ($category, $data): void {
            $before = $category->toArray();
            $category->update($data);
            $this->audit->tenant('category.updated', 'category', $category->id, $before, $category->fresh()->toArray());
        });

        return back()->with('success', 'Category updated.');
    }

    public function destroyCategory(string $workspace, Category $category): RedirectResponse
    {
        $this->authorize('catalog.items.delete');
        DB::connection('tenant')->transaction(function () use ($category): void {
            $category->delete();
            $this->audit->tenant('category.archived', 'category', $category->id);
        });

        return back()->with('success', 'Category archived. Existing product classifications are retained.');
    }

    public function restoreCategory(string $workspace, Category $category): RedirectResponse
    {
        $this->authorize('catalog.items.delete');
        DB::connection('tenant')->transaction(function () use ($category): void {
            $category->restore();
            $this->audit->tenant('category.restored', 'category', $category->id);
        });

        return back()->with('success', 'Category restored.');
    }

    public function updateUnit(Request $request, string $workspace, Unit $unit): RedirectResponse
    {
        $this->authorize('catalog.items.update');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'symbol' => ['required', 'string', 'max:12']]);
        DB::connection('tenant')->transaction(function () use ($unit, $data): void {
            $before = $unit->toArray();
            $unit->update($data);
            $this->audit->tenant('unit.updated', 'unit', $unit->id, $before, $unit->fresh()->toArray());
        });

        return back()->with('success', 'Unit label updated. Conversion factors are unchanged.');
    }

    public function destroyUnit(string $workspace, Unit $unit): RedirectResponse
    {
        $this->authorize('catalog.items.delete');
        DB::connection('tenant')->transaction(function () use ($unit): void {
            $unit = Unit::query()->lockForUpdate()->findOrFail($unit->id);
            $references = ['items' => ['base_unit_id', 'purchase_unit_id', 'sales_unit_id'], 'recipe_lines' => ['unit_id'], 'production_orders' => ['output_unit_id'], 'run_waste' => ['unit_id'], 'unit_conversions' => ['from_unit_id', 'to_unit_id']];
            foreach ($references as $table => $columns) {
                foreach ($columns as $column) {
                    if (DB::connection('tenant')->table($table)->where($column, $unit->id)->exists()) {
                        throw ValidationException::withMessages(['unit' => 'This unit is used by products, recipes, batches or conversions. Keep it to preserve their quantities.']);
                    }
                }
            }
            $unit->delete();
            $this->audit->tenant('unit.deleted', 'unit', $unit->id);
        });

        return back()->with('success', 'Unused unit deleted.');
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $this->authorize('catalog.items.create');
        $request->merge([
            'name' => trim($request->string('name')->toString()),
            'symbol' => trim($request->string('symbol')->toString()),
        ]);
        $data = $request->validate([
            'name' => ['bail', 'required', 'string', 'max:255'],
            'symbol' => ['bail', 'required', 'string', 'max:12', function (string $attribute, mixed $value, Closure $fail): void {
                if (Unit::query()->whereRaw('lower(symbol) = lower(?)', [$value])->exists()) {
                    $fail('A unit with this symbol already exists.');
                }
            }],
            'from_unit_id' => ['nullable', 'uuid', 'required_with:factor', Rule::exists('tenant.units', 'id')],
            'factor' => ['nullable', 'required_with:from_unit_id', 'numeric', 'decimal:0,6', 'gt:0', 'max:999999999'],
        ], [
            'name.required' => 'Enter a unit name.',
            'symbol.required' => 'Enter a unit symbol.',
            'symbol.max' => 'Use a symbol of 12 characters or fewer.',
            'from_unit_id.required_with' => 'Choose the unit this converts from.',
            'factor.required_with' => 'Enter how many of the new unit equal one of the selected unit.',
            'factor.gt' => 'Enter a conversion greater than zero.',
            'factor.decimal' => 'Use at most 6 decimal places for the conversion.',
        ]);
        $unit = DB::connection('tenant')->transaction(function () use ($data): Unit {
            $unit = Unit::query()->create(['name' => $data['name'], 'symbol' => $data['symbol']]);
            if (! empty($data['from_unit_id'])) {
                UnitConversion::query()->create([
                    'from_unit_id' => $data['from_unit_id'],
                    'to_unit_id' => $unit->id,
                    'factor' => $data['factor'],
                ]);
            }
            $this->audit->tenant('unit.created', 'unit', $unit->id, null, $unit->fresh()->toArray());

            return $unit;
        });

        return back()->with('success', 'Unit saved.')->with('created_unit_id', $unit->id);
    }

    public function storeConversion(Request $request): RedirectResponse
    {
        $this->authorize('catalog.items.create');
        $data = $request->validate([
            'from_unit_id' => ['required', 'uuid', 'different:to_unit_id', Rule::exists('tenant.units', 'id')],
            'to_unit_id' => ['required', 'uuid', Rule::exists('tenant.units', 'id')],
            'factor' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:999999999'],
        ], [
            'from_unit_id.different' => 'Choose two different units.',
            'factor.required' => 'Enter how many of the second unit equal one of the first unit.',
            'factor.gt' => 'Enter a conversion greater than zero.',
            'factor.decimal' => 'Use at most 6 decimal places for the conversion.',
        ]);
        $this->guardNewConversion($data['from_unit_id'], $data['to_unit_id']);
        DB::connection('tenant')->transaction(function () use ($data): void {
            $conversion = UnitConversion::query()->create([
                'from_unit_id' => $data['from_unit_id'],
                'to_unit_id' => $data['to_unit_id'],
                'factor' => $data['factor'],
            ]);
            $this->audit->tenant('unit_conversion.created', 'unit_conversion', $conversion->id, null, $conversion->toArray());
        });

        return back()->with('success', 'Conversion saved.');
    }

    public function priceLists(): Response
    {
        $this->authorize('catalog.items.view');
        abort_unless($this->features->active('pricing.multi_channel'), 404);

        return Inertia::render('Catalog/PriceLists', [
            'lists' => PriceList::query()->get(),
            'prices' => PriceListItem::query()->limit(200)->get(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name', 'sku']),
            'canManage' => auth()->user()->can('catalog.prices.manage'),
        ]);
    }

    public function updatePriceList(Request $request, string $workspace, PriceList $priceList): RedirectResponse
    {
        $this->authorize('catalog.prices.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        DB::connection('tenant')->transaction(function () use ($priceList, $data): void {
            $before = $priceList->toArray();
            $priceList->update($data);
            $this->audit->tenant('price_list.updated', 'price_list', $priceList->id, $before, $priceList->fresh()->toArray());
        });

        return back()->with('success', 'Price list updated.');
    }

    public function destroyPriceList(string $workspace, PriceList $priceList): RedirectResponse
    {
        $this->authorize('catalog.prices.manage');
        DB::connection('tenant')->transaction(function () use ($priceList): void {
            $priceList = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);
            if ($priceList->is_default || DB::connection('tenant')->table('customers')->where('price_list_id', $priceList->id)->exists() || DB::connection('tenant')->table('sales')->where('price_list_id', $priceList->id)->exists() || PriceListItem::query()->where('price_list_id', $priceList->id)->exists()) {
                throw ValidationException::withMessages(['price_list' => 'Only an empty, unused, non-default price list can be deleted. Remove its prices and customer assignments first; lists referenced by sales must be retained.']);
            }
            $priceList->delete();
            $this->audit->tenant('price_list.deleted', 'price_list', $priceList->id);
        });

        return back()->with('success', 'Unused price list deleted.');
    }

    public function updatePrice(Request $request, string $workspace, PriceListItem $price): RedirectResponse
    {
        $this->authorize('catalog.prices.manage');
        $data = $request->validate(['price' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999']]);
        DB::connection('tenant')->transaction(function () use ($price, $data): void {
            $before = $price->toArray();
            $price->update($data);
            $this->audit->tenant('price.updated', 'price_list_item', $price->id, $before, $price->fresh()->toArray());
        });

        return back()->with('success', 'Price updated for future sales.');
    }

    public function destroyPrice(string $workspace, PriceListItem $price): RedirectResponse
    {
        $this->authorize('catalog.prices.manage');
        DB::connection('tenant')->transaction(function () use ($price): void {
            $before = $price->toArray();
            $price->delete();
            $this->audit->tenant('price.deleted', 'price_list_item', $price->id, $before);
        });

        return back()->with('success', 'Price override removed. Existing sales are unchanged.');
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $this->authorize('catalog.prices.manage');
        $data = $request->validate([
            'price_list_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string'],
            'channel' => ['nullable', 'string'],
            'item_id' => ['nullable', 'uuid'],
            'price' => ['nullable', 'numeric'],
            'branch_id' => ['nullable', 'uuid'],
        ]);
        $listId = $data['price_list_id'] ?? null;
        if (! $listId && ! empty($data['name'])) {
            $listId = PriceList::query()->create([
                'name' => $data['name'],
                'channel' => $data['channel'] ?? 'custom',
                'is_default' => false,
            ])->id;
        }
        if ($listId && ! empty($data['item_id'])) {
            PriceListItem::query()->create([
                'price_list_id' => $listId,
                'item_id' => $data['item_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'price' => $data['price'] ?? 0,
            ]);
        }

        return back()->with('success', 'Price list updated.');
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorize('catalog.items.create');
        $data = $request->validate([
            'csv' => ['required', 'string'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $rows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($data['csv'])) ?: []);
        $header = array_map('trim', array_shift($rows) ?: []);
        $errors = [];
        $ready = [];
        foreach ($rows as $index => $row) {
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }
            $record = array_combine($header, array_pad($row, count($header), null));
            if (empty($record['name']) || empty($record['type'])) {
                $errors[] = 'Row '.($index + 2).' needs name and type.';

                continue;
            }
            $ready[] = $record;
        }
        if ($request->boolean('dry_run') || $errors) {
            return back()->with('success', count($ready).' rows valid. '.implode(' ', $errors));
        }
        foreach ($ready as $record) {
            $this->guardItemLimit();
            Item::query()->create([
                'name' => $record['name'],
                'name_alt' => $record['name_alt'] ?? null,
                'type' => $record['type'],
                'sku' => filled($record['sku'] ?? null) ? $record['sku'] : $this->skus->next(),
                'default_price' => $record['price'] ?? 0,
                'track_stock' => ($record['type'] ?? '') !== 'service',
                'created_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', count($ready).' items imported.');
    }

    private function saveItem(Item $item, Request $request): Item
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'name_alt' => ['nullable', 'string'],
            'type' => ['required', 'in:raw_material,finished_good,trading_good,service'],
            'category_id' => ['nullable', 'uuid', 'exists:tenant.categories,id'],
            'base_unit_id' => ['required_unless:type,service', 'nullable', 'uuid', 'exists:tenant.units,id'],
            'tax_rate_id' => ['nullable', 'uuid', 'exists:tenant.tax_rates,id'],
            'default_price' => ['required_unless:type,raw_material', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'wholesale_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'production_unit_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'reorder_level' => ['nullable', 'numeric'],
            'make_mode' => ['nullable', 'in:make_to_stock,make_to_order'],
            'negative_stock_policy' => ['nullable', 'in:inherit,block,warn,allow'],
            'description' => ['nullable', 'string'],
            'track_stock' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'barcodes' => ['nullable', 'array'],
        ]);

        return DB::connection('tenant')->transaction(function () use ($item, $data, $request): Item {
            if ($item->exists) {
                $item = Item::query()->lockForUpdate()->findOrFail($item->id);
                $hasHistory = StockMovement::query()->where('item_id', $item->id)->exists() || Recipe::query()->where('item_id', $item->id)->exists() || RecipeLine::query()->where('item_id', $item->id)->exists() || ProductionOrder::query()->where('item_id', $item->id)->exists();
                if ($hasHistory && ($item->type !== $data['type'] || $item->base_unit_id !== ($data['base_unit_id'] ?? null))) {
                    throw ValidationException::withMessages(['type' => 'This product has stock or recipe history. Create a new product to change its type or stock unit.']);
                }
            }
            $item->fill([
                'name' => $data['name'],
                'name_alt' => $data['name_alt'] ?? null,
                'type' => $data['type'],
                'sku' => $item->sku ?: $this->skus->next(),
                'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'] ?? null,
                'purchase_unit_id' => $data['base_unit_id'] ?? null,
                'sales_unit_id' => $data['base_unit_id'] ?? null,
                'tax_rate_id' => $data['tax_rate_id'] ?? null,
                'default_price' => $data['default_price'] ?? 0,
                'production_unit_cost' => array_key_exists('production_unit_cost', $data) ? $data['production_unit_cost'] : $item->production_unit_cost,
                'reorder_level' => $data['reorder_level'] ?? 0,
                'make_mode' => $data['type'] === 'finished_good' ? ($data['make_mode'] ?? 'make_to_stock') : 'make_to_stock',
                'negative_stock_policy' => $data['negative_stock_policy'] ?? 'inherit',
                'description' => $data['description'] ?? null,
                'track_stock' => $data['type'] !== 'service',
                'created_by' => $item->created_by ?: $request->user()->id,
            ])->save();

            foreach (['retail' => $data['default_price'] ?? 0, 'wholesale' => $data['wholesale_price'] ?? null] as $channel => $price) {
                if ($price === null) {
                    continue;
                }
                $list = PriceList::query()->where('channel', $channel)->first();
                if (! $list && $channel === 'retail') {
                    continue;
                }
                $list ??= PriceList::query()->create(['name' => 'Wholesale', 'channel' => 'wholesale', 'is_default' => false]);
                PriceListItem::query()->updateOrCreate(['price_list_id' => $list->id, 'item_id' => $item->id, 'branch_id' => null, 'variant_id' => null], ['price' => $price]);
            }

            if (array_key_exists('variants', $data)) {
                ItemVariant::query()->where('item_id', $item->id)->delete();
                foreach ($data['variants'] as $variant) {
                    if (empty($variant['name'])) {
                        continue;
                    }
                    ItemVariant::query()->create([
                        'item_id' => $item->id,
                        'name' => $variant['name'],
                        'sku' => $variant['sku'] ?? null,
                        'price_delta' => $variant['price_delta'] ?? 0,
                    ]);
                }
            }
            if (array_key_exists('barcodes', $data)) {
                ItemBarcode::query()->where('item_id', $item->id)->delete();
                foreach ($data['barcodes'] as $barcode) {
                    if (empty($barcode)) {
                        continue;
                    }
                    ItemBarcode::query()->create(['item_id' => $item->id, 'barcode' => $barcode]);
                }
            }

            return $item;
        });
    }

    private function guardNewConversion(string $fromUnitId, string $toUnitId): void
    {
        $exists = UnitConversion::query()
            ->where(function ($query) use ($fromUnitId, $toUnitId): void {
                $query->where(fn ($forward) => $forward->where('from_unit_id', $fromUnitId)->where('to_unit_id', $toUnitId))
                    ->orWhere(fn ($reverse) => $reverse->where('from_unit_id', $toUnitId)->where('to_unit_id', $fromUnitId));
            })
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['factor' => 'These units already have a conversion.']);
        }
    }

    private function guardItemLimit(): void
    {
        $max = $this->features->limit('max_items');
        if ($max && Item::query()->count() >= $max) {
            throw ValidationException::withMessages([
                'limit' => "Your plan allows {$max} items. Contact support to increase.",
            ]);
        }
    }
}
