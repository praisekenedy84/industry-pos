<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeStage;
use App\Models\RecipeVersion;
use App\Models\UnitConversion;
use App\Support\Bc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class RecipeWriter
{
    public function __construct(private AuditLogger $audit, private FeatureGate $features, private SkuGenerator $skus) {}

    public function save(Recipe $recipe, Request $request, bool $version = false): Recipe
    {
        $stockedItem = fn (): Exists => Rule::exists('tenant.items', 'id')->where('track_stock', true)->where('type', '!=', 'service')->whereNotNull('base_unit_id')->whereNull('deleted_at');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'item_id' => ['nullable', 'uuid', $stockedItem()],
            'item' => ['nullable', 'array'],
            'item.name' => ['nullable', 'string', 'max:255'],
            'item.base_unit_id' => ['nullable', 'uuid', Rule::exists('tenant.units', 'id')],
            'yield_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid', $stockedItem()],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.base_unit_id' => ['nullable', 'uuid', Rule::exists('tenant.units', 'id')],
            'lines.*.unit_id' => ['nullable', 'uuid', Rule::exists('tenant.units', 'id')],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'labor' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'overhead' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'stages' => ['nullable', 'array'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.requires_quality' => ['nullable', 'boolean'],
        ]);
        $this->assertRecipeIdentity($data, $request);

        return DB::connection('tenant')->transaction(function () use ($recipe, $request, $data): Recipe {
            if ($recipe->exists) {
                $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipe->id);
            }
            $createdOutput = false;
            if (! empty($data['item']['name'])) {
                $output = $this->createStockedItem($request, $data['item']['name'], $data['item']['base_unit_id'], 'finished_good', $data['retail_price'] ?? 0);
                $data['item_id'] = $output->id;
                $createdOutput = true;
            }
            $resolvedLines = [];
            foreach ($data['lines'] as $line) {
                if (! empty($line['name'])) {
                    $material = $this->createStockedItem($request, $line['name'], $line['base_unit_id'], 'raw_material', 0);
                    $line['item_id'] = $material->id;
                }
                $resolvedLines[] = $line;
            }
            $data['lines'] = $resolvedLines;
            if (collect($data['lines'])->contains(fn (array $line): bool => $line['item_id'] === $data['item_id'])) {
                throw ValidationException::withMessages(['lines' => 'A bill cannot consume its own output.']);
            }
            if ($recipe->exists && $recipe->item_id !== $data['item_id']) {
                throw ValidationException::withMessages(['item_id' => 'Create a new recipe to produce a different product.']);
            }
            $previousCosts = collect();
            if ($recipe->exists) {
                $current = RecipeVersion::query()->where('recipe_id', $recipe->id)->where('version', $recipe->current_version)->first();
                if ($current) {
                    $previousCosts = RecipeLine::query()->where('recipe_version_id', $current->id)->pluck('unit_cost', 'item_id');
                }
            }
            if (! $recipe->exists) {
                $recipe->fill(['item_id' => $data['item_id'], 'name' => $data['name'], 'notes' => $data['notes'] ?? null, 'current_version' => 1])->save();
                $number = 1;
            } else {
                $number = $recipe->current_version + 1;
                $recipe->update(['name' => $data['name'], 'notes' => $data['notes'] ?? null, 'item_id' => $data['item_id'], 'current_version' => $number]);
            }
            $versionModel = RecipeVersion::query()->create([
                'recipe_id' => $recipe->id,
                'item_id' => $data['item_id'],
                'version' => $number,
                'yield_qty' => $data['yield_qty'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            foreach ($data['lines'] as $line) {
                $material = Item::query()->findOrFail($line['item_id']);
                $unitId = $line['unit_id'] ?? $material->base_unit_id;
                if ($unitId !== $material->base_unit_id && ! UnitConversion::query()->where('factor', '>', 0)->where(fn ($query) => $query
                    ->where(fn ($forward) => $forward->where('from_unit_id', $unitId)->where('to_unit_id', $material->base_unit_id))
                    ->orWhere(fn ($reverse) => $reverse->where('to_unit_id', $unitId)->where('from_unit_id', $material->base_unit_id)))->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Choose the ingredient stock unit or define a valid conversion first.']);
                }
                $unitCost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== ''
                    ? $line['unit_cost']
                    : ($previousCosts[$line['item_id']] ?? 0);
                RecipeLine::query()->create([
                    'recipe_version_id' => $versionModel->id,
                    'item_id' => $line['item_id'],
                    'qty' => $line['qty'],
                    'unit_id' => $unitId,
                    'unit_cost' => $unitCost,
                ]);
            }
            if (($data['labor'] ?? 0) > 0) {
                RecipeCostLine::query()->create(['recipe_version_id' => $versionModel->id, 'type' => 'labor', 'name' => 'Labor', 'amount' => $data['labor']]);
            }
            if (($data['overhead'] ?? 0) > 0) {
                RecipeCostLine::query()->create(['recipe_version_id' => $versionModel->id, 'type' => 'overhead', 'name' => 'Overhead', 'amount' => $data['overhead']]);
            }
            foreach ($data['stages'] ?? [] as $index => $stage) {
                if (empty($stage['name'])) {
                    continue;
                }
                RecipeStage::query()->create([
                    'recipe_version_id' => $versionModel->id,
                    'name' => $stage['name'],
                    'position' => $index + 1,
                    'requires_quality' => (bool) ($stage['requires_quality'] ?? false),
                ]);
            }
            $outputItem = Item::query()->findOrFail($data['item_id']);
            $this->saveSellingPrices($request, $outputItem, $data['retail_price'] ?? null, $data['wholesale_price'] ?? null, $createdOutput);

            return $recipe;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRecipeIdentity(array $data, Request $request): void
    {
        $createsOutput = ! empty($data['item']['name']);
        if (empty($data['item_id']) && ! $createsOutput) {
            throw ValidationException::withMessages(['item.name' => 'Name the item being made, or choose an existing one.']);
        }
        if (! empty($data['item_id']) && $createsOutput) {
            throw ValidationException::withMessages(['item_id' => 'Choose an existing item or create a new one.']);
        }
        if ($createsOutput && empty($data['item']['base_unit_id'])) {
            throw ValidationException::withMessages(['item.base_unit_id' => 'Choose a unit for the new item.']);
        }
        foreach ($data['lines'] as $index => $line) {
            $hasItem = ! empty($line['item_id']);
            $hasName = ! empty($line['name']);
            if ($hasItem === $hasName) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'Choose a raw material or name a new one.']);
            }
        }
        foreach ($data['lines'] as $index => $line) {
            if (! empty($line['name']) && empty($line['base_unit_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.base_unit_id" => 'Choose a unit for the new raw material.']);
            }
        }
        $createsMaterials = collect($data['lines'])->contains(fn (array $line): bool => ! empty($line['name']));
        if (($createsOutput || $createsMaterials) && ! $request->user()->can('catalog.items.create')) {
            throw ValidationException::withMessages(['item.name' => 'You need permission to create catalog items.']);
        }
    }

    private function createStockedItem(Request $request, string $name, string $unitId, string $type, mixed $price): Item
    {
        $this->guardItemLimit();
        $item = Item::query()->create([
            'name' => $name,
            'type' => $type,
            'sku' => $this->skus->next(),
            'base_unit_id' => $unitId,
            'purchase_unit_id' => $unitId,
            'sales_unit_id' => $unitId,
            'track_stock' => true,
            'make_mode' => 'make_to_stock',
            'reorder_level' => 0,
            'default_price' => $type === 'finished_good' ? Bc::num($price) : '0.0000',
            'negative_stock_policy' => 'inherit',
            'created_by' => $request->user()->id,
        ]);
        $this->audit->tenant('catalog.item.created', 'item', $item->id, null, $item->only(['id', 'name', 'type', 'sku', 'default_price']));

        return $item;
    }

    private function saveSellingPrices(Request $request, Item $item, mixed $retail, mixed $wholesale, bool $createdOutput): void
    {
        if ($retail === null && $wholesale === null) {
            return;
        }
        $canSetPrices = $createdOutput || $request->user()->can('catalog.prices.manage') || $request->user()->can('catalog.items.update');
        if (! $canSetPrices) {
            throw ValidationException::withMessages(['retail_price' => 'You need permission to set selling prices.']);
        }
        if ($retail !== null) {
            $item->update(['default_price' => Bc::num($retail)]);
            $this->upsertChannelPrice($item, 'retail', 'Retail', $retail, true);
        }
        if ($wholesale !== null) {
            $this->upsertChannelPrice($item, 'wholesale', 'Wholesale', $wholesale, false);
        }
    }

    private function upsertChannelPrice(Item $item, string $channel, string $name, mixed $price, bool $isDefault): void
    {
        $list = PriceList::query()->firstOrCreate(
            ['channel' => $channel],
            ['name' => $name, 'is_default' => $isDefault],
        );
        $row = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->where('item_id', $item->id)
            ->whereNull('branch_id')
            ->whereNull('variant_id')
            ->first();
        if ($row) {
            $row->update(['price' => Bc::num($price)]);

            return;
        }
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'item_id' => $item->id,
            'price' => Bc::num($price),
        ]);
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
