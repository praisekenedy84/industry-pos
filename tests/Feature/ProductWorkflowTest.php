<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionOrder;
use App\Models\ProductionRun;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Reports\ReportRegistry;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\BatchProduction;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Services\StockReceipt;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $slug = '';

    protected function tearDown(): void
    {
        if ($this->slug !== '' && config('database.connections.central.database') === 'makerly_test') {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_'.$this->slug.' CASCADE');
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_products_capture_units_and_independent_prices_and_recipes_keep_started_batches_unchanged(): void
    {
        $this->workspace();
        $unit = Unit::query()->where('symbol', 'pc')->sole();
        $this->post($this->base().'/catalog/items', ['name' => 'Small loaf', 'type' => 'finished_good', 'base_unit_id' => $unit->id, 'default_price' => 120, 'wholesale_price' => 100])->assertSessionHasNoErrors()->assertRedirect();
        $item = Item::query()->where('name', 'Small loaf')->sole();
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        $recipe = ['yield_qty' => 10, 'lines' => [['item_id' => $flour->id, 'qty' => 1]], 'labor' => 100, 'overhead' => 20, 'retail_price' => 1];
        $this->put($this->base().'/catalog/items/'.$item->id.'/recipe', $recipe)->assertSessionHasNoErrors();
        $batch = app(BatchProduction::class)->start(['item_id' => $item->id, 'branch_id' => $this->branch()]);
        $this->put($this->base().'/catalog/items/'.$item->id.'/recipe', [...$recipe, 'yield_qty' => 20])->assertSessionHasNoErrors();

        $this->assertSame('120.0000', $item->fresh()->default_price);
        $this->assertSame('100.0000', app(SaleService::class)->priceFor($item, PriceList::query()->where('channel', 'wholesale')->value('id'), branchId: $this->branch()));
        $this->assertSame('10.0000', RecipeVersion::query()->findOrFail($batch->recipe_version_id)->yield_qty);
        $this->assertSame(2, RecipeVersion::query()->where('recipe_id', $batch->recipe_id)->count());
        $run = app(BatchProduction::class)->complete($batch, ['good_qty' => 9, 'waste_qty' => 1]);
        $this->assertSame('1120.0000', $run->batch_cost);
        $new = app(BatchProduction::class)->start(['item_id' => $item->id, 'branch_id' => $this->branch()]);
        $this->assertNotSame($batch->recipe_version_id, $new->recipe_version_id);
        $this->get($this->base().'/catalog/items/'.$item->id.'/edit', $this->headers())->assertOk()->assertJsonPath('props.recipeVersion.version', 2);
    }

    public function test_new_items_receive_the_next_sku_and_edits_keep_that_sku(): void
    {
        $this->workspace();
        $unit = Unit::query()->where('symbol', 'pc')->value('id');
        Item::query()->create(['name' => 'Retired code', 'type' => 'trading_good', 'sku' => 'SKU-00001', 'base_unit_id' => $unit, 'default_price' => 1, 'track_stock' => true])->delete();

        $this->post($this->base().'/catalog/items', ['name' => 'First generated', 'type' => 'trading_good', 'base_unit_id' => $unit, 'default_price' => 10, 'sku' => 'CUSTOM'])->assertSessionHasNoErrors()->assertRedirect();

        $first = Item::query()->where('name', 'First generated')->sole();
        $this->assertSame('SKU-00002', $first->sku);

        $this->post($this->base().'/catalog/items', ['name' => 'Second generated', 'type' => 'trading_good', 'base_unit_id' => $unit, 'default_price' => 10])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('SKU-00003', Item::query()->where('name', 'Second generated')->value('sku'));

        $this->put($this->base().'/catalog/items/'.$first->id, ['name' => 'First generated', 'type' => 'trading_good', 'base_unit_id' => $unit, 'default_price' => 12, 'sku' => 'CHANGED'])->assertSessionHasNoErrors();
        $this->assertSame('SKU-00002', $first->fresh()->sku);
        $this->assertSame('12.0000', $first->fresh()->default_price);
        $this->get($this->base().'/catalog/items/'.$first->id.'/edit', $this->headers())->assertOk()->assertJsonPath('props.item.sku', 'SKU-00002');

        $bread = Item::query()->where('sku', 'BRD-001')->sole();
        $this->put($this->base().'/catalog/items/'.$bread->id.'/recipe', [
            'yield_qty' => 1,
            'lines' => [['name' => 'Generated flour', 'base_unit_id' => Unit::query()->where('symbol', 'kg')->value('id'), 'qty' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('SKU-00004', Item::query()->where('name', 'Generated flour')->value('sku'));

        $this->post($this->base().'/catalog/import', ['csv' => "name,type,sku,price\nImported service,service,,25\n", 'dry_run' => false])->assertSessionHasNoErrors();
        $this->assertSame('SKU-00005', Item::query()->where('name', 'Imported service')->value('sku'));
        $this->post($this->base().'/catalog/import', ['csv' => "name,type,sku,price\nKept code,service,KEEP-9,25\n", 'dry_run' => false])->assertSessionHasNoErrors();
        $this->assertSame('KEEP-9', Item::query()->where('name', 'Kept code')->value('sku'));
    }

    public function test_new_product_saves_its_mixture_and_yield_atomically_with_independent_selling_price(): void
    {
        $this->workspace();
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        $data = ['name' => 'Mixture loaf', 'type' => 'finished_good', 'base_unit_id' => Unit::query()->where('symbol', 'pc')->value('id'), 'default_price' => 200,
            'recipe' => ['yield_qty' => 10, 'lines' => [['item_id' => $flour->id, 'unit_id' => $flour->base_unit_id, 'qty' => 1]], 'labor' => 50, 'overhead' => 20]];
        $movements = StockMovement::query()->count();
        $this->post($this->base().'/catalog/items', $data)->assertSessionHasNoErrors()->assertRedirect();
        $product = Item::query()->where('name', 'Mixture loaf')->sole();
        $this->get($this->base().'/catalog/items/'.$product->id.'/edit', $this->headers())->assertOk()
            ->assertJsonPath('props.recipeVersion.yield_qty', '10.0000')
            ->assertJsonPath('props.recipeEstimate.total', '1070.0000')
            ->assertJsonPath('props.recipeEstimate.unit_cost', '107.0000');
        $this->assertSame('200.0000', $product->default_price);
        $batch = app(BatchProduction::class)->start(['item_id' => $product->id, 'branch_id' => $this->branch()]);
        $this->assertNotNull($batch->recipe_version_id);
        $this->assertSame($movements, StockMovement::query()->count());

        $data['name'] = 'Invalid mixture';
        $data['recipe']['lines'][0]['qty'] = 0;
        $this->post($this->base().'/catalog/items', $data)->assertSessionHasErrors('lines.0.qty');
        $this->assertDatabaseMissing('items', ['name' => 'Invalid mixture'], 'tenant');
        $data['type'] = 'trading_good';
        $this->post($this->base().'/catalog/items', $data)->assertSessionHasErrors('recipe');
        $this->assertDatabaseMissing('items', ['name' => 'Invalid mixture'], 'tenant');
    }

    public function test_unsaved_mixture_can_be_costed_without_creating_products_or_moving_stock(): void
    {
        $this->workspace();
        $this->withCredentials();
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        $data = ['yield_qty' => 20, 'lines' => [['item_id' => $flour->id, 'qty' => 2]], 'labor' => 100, 'overhead' => 0];
        $items = Item::query()->count();
        $movements = StockMovement::query()->count();
        $versions = RecipeVersion::query()->count();
        $this->get($this->base().'/catalog/items', $this->headers())->assertOk()->assertJsonPath('props.canRecipe', true);
        $this->postJson($this->base().'/catalog/recipe-estimate', $data)->assertOk()->assertJsonPath('total', '2100.0000')->assertJsonPath('unit_cost', '105.0000');
        $this->postJson($this->base().'/catalog/recipe-estimate', [...$data, 'yield_qty' => 0])->assertUnprocessable()->assertJsonValidationErrors('yield_qty');
        $this->postJson($this->base().'/catalog/recipe-estimate', [...$data, 'lines' => [['item_id' => (string) Str::uuid(), 'qty' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
        $this->assertSame($items, Item::query()->count());
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($versions, RecipeVersion::query()->count());
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->postJson($this->base().'/catalog/recipe-estimate', $data)->assertForbidden();
    }

    public function test_new_product_ingredient_choices_only_include_raw_materials(): void
    {
        $this->workspace();
        $this->traded();
        $expected = Item::query()->where('type', 'raw_material')->where('track_stock', true)->whereNotNull('base_unit_id')->orderBy('name')->pluck('id')->all();

        $response = $this->get($this->base().'/catalog/items', $this->headers())->assertOk();

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, array_column($response->json('props.ingredients'), 'id'));
    }

    public function test_partial_recipe_scales_ingredients_labour_and_overhead_and_ignores_recipe_prices(): void
    {
        $this->workspace();
        RecipeLine::query()->update(['unit_cost' => 999999]);
        $batch = $this->batch();

        $this->post($this->base().'/production/batches/'.$batch->id.'/complete', ['good_qty' => 9, 'waste_qty' => 1])->assertSessionHasNoErrors();

        $run = ProductionRun::query()->sole();
        $this->assertSame('3975.0000', $run->batch_cost);
        $this->assertSame('1000.0000', $run->labor_cost);
        $this->assertSame('250.0000', $run->overhead_cost);
        $this->assertSame('397.5000', $run->waste_cost);
        $this->assertSame('0.0000', $run->loss_value);
        $this->assertDatabaseHas('run_inputs', ['item_id' => Item::query()->where('sku', 'FLR-001')->value('id'), 'actual_qty' => 2.5, 'unit_cost' => 1000], 'tenant');
    }

    public function test_ingredients_need_no_selling_price_and_saved_recipe_cost_uses_branch_inventory(): void
    {
        $this->workspace();
        $unit = Unit::query()->where('symbol', 'kg')->sole();
        $this->post($this->base().'/catalog/items', ['name' => 'Recipe ingredient', 'type' => 'raw_material', 'base_unit_id' => $unit->id])->assertSessionHasNoErrors()->assertRedirect();
        $ingredient = Item::query()->where('name', 'Recipe ingredient')->sole();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $recipe = ['yield_qty' => 10, 'lines' => [['item_id' => $ingredient->id, 'qty' => 2]], 'labor' => 20, 'overhead' => 10];
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', $recipe)->assertSessionHasNoErrors();
        $other = Branch::query()->create(['name' => 'Other branch', 'code' => 'OTHER', 'status' => 'active']);
        app(StockReceipt::class)->receive($ingredient, $other->id, ['request_id' => (string) Str::uuid(), 'qty' => 10, 'unit_cost' => 900]);
        $url = $this->base().'/catalog/items/'.$product->id.'/edit';
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('props.recipeEstimate.total', null);

        $this->post($this->base().'/catalog/items/'.$ingredient->id.'/stock', ['request_id' => (string) Str::uuid(), 'qty' => 10, 'unit_cost' => 100])->assertSessionHasNoErrors();
        $movements = StockMovement::query()->count();
        $this->get($url, $this->headers())->assertOk()
            ->assertJsonPath('props.recipeEstimate.material', '200.0000')
            ->assertJsonPath('props.recipeEstimate.total', '230.0000')
            ->assertJsonPath('props.recipeEstimate.unit_cost', '23.0000')
            ->assertJsonPath('props.recipeEstimate.issues', []);
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame('10.0000', app(StockLedger::class)->onHand($ingredient->id, $this->branch()));
        $this->assertSame('0.0000', $ingredient->default_price);
    }

    public function test_recipe_estimate_converts_units_and_uses_fifo_and_explicit_zero_cost(): void
    {
        $this->workspace();
        Setting::putValue('valuation', 'fifo');
        $ingredient = $this->traded();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $pack = Unit::query()->create(['name' => 'Pair', 'symbol' => 'pair']);
        UnitConversion::query()->create(['from_unit_id' => $pack->id, 'to_unit_id' => $ingredient->base_unit_id, 'factor' => 2]);
        $recipe = ['yield_qty' => 10, 'lines' => [['item_id' => $ingredient->id, 'qty' => 2, 'unit_id' => $pack->id]], 'labor' => 0, 'overhead' => 0];
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', $recipe)->assertSessionHasNoErrors();
        $receipts = app(StockReceipt::class);
        $receipts->receive($ingredient, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 2, 'unit_cost' => 0]);
        $receipts->receive($ingredient, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 4, 'unit_cost' => 20]);
        $url = $this->base().'/catalog/items/'.$product->id.'/edit';
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('props.recipeEstimate.total', '40.0000')->assertJsonPath('props.recipeEstimate.unit_cost', '4.0000');

        $recipe['lines'][0]['qty'] = 1;
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', $recipe)->assertSessionHasNoErrors();
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('props.recipeEstimate.total', '0.0000');
        $recipe['lines'][0]['qty'] = 4;
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', $recipe)->assertSessionHasNoErrors();
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('props.recipeEstimate.total', null);
    }

    public function test_actual_usage_overrides_defaults_and_changed_retries_cannot_edit_completed_costs(): void
    {
        $this->workspace();
        $batch = $this->batch();
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        $line = RecipeLine::query()->where('recipe_version_id', $batch->recipe_version_id)->where('item_id', $flour->id)->sole();
        $data = ['good_qty' => 18, 'waste_qty' => 2, 'actual_usage' => [['recipe_line_id' => $line->id, 'qty' => 6]], 'labor_cost' => 0, 'overhead_cost' => 200];
        $url = $this->base().'/production/batches/'.$batch->id.'/complete';

        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'labor_cost' => 10])->assertSessionHasErrors('good_qty');

        $this->assertSame('6650.0000', ProductionRun::query()->sole()->batch_cost);
        $this->assertDatabaseHas('run_inputs', ['item_id' => $flour->id, 'planned_qty' => 5, 'actual_qty' => 6], 'tenant');
        $this->assertDatabaseCount('production_journals', 1, 'tenant');
        $this->assertSame('44.0000', app(StockLedger::class)->onHand($flour->id, $this->branch()));
    }

    public function test_incompatible_waste_can_use_full_actual_usage_and_explicit_allocation(): void
    {
        $this->workspace();
        Setting::putValue('production_waste_treatment', 'expensed');
        $batch = $this->batch();
        $data = [
            'good_qty' => 18, 'waste_qty' => 2, 'waste_unit_id' => Unit::query()->where('symbol', 'kg')->value('id'),
            'actual_usage' => RecipeLine::query()->where('recipe_version_id', $batch->recipe_version_id)->get()->map(fn ($line) => ['recipe_line_id' => $line->id, 'qty' => $line->qty])->all(),
            'labor_cost' => 2000, 'overhead_cost' => 500, 'waste_cost' => 950,
        ];
        $url = $this->base().'/production/batches/'.$batch->id.'/complete';
        $this->post($url, ['good_qty' => 18, 'waste_qty' => 2, 'waste_unit_id' => $data['waste_unit_id']])->assertSessionHasErrors('waste_unit_id');
        $this->post($url, $data)->assertSessionHasNoErrors();

        $run = ProductionRun::query()->sole();
        $this->assertSame('7950.0000', $run->batch_cost);
        $this->assertSame('7000.0000', $run->inventory_value);
        $this->assertSame('950.0000', $run->loss_value);
        $this->assertDatabaseHas('run_waste', ['production_run_id' => $run->id, 'output_equivalent_qty' => null], 'tenant');
    }

    public function test_receipts_keep_delivery_costs_and_weighted_average_sale_cost_is_historical(): void
    {
        $this->workspace();
        $item = $this->traded();
        $url = $this->base().'/catalog/items/'.$item->id.'/stock';
        $one = ['request_id' => (string) Str::uuid(), 'qty' => 10, 'unit_cost' => 100, 'reference' => 'First delivery'];
        $this->post($url, $one)->assertSessionHasNoErrors();
        $this->post($url, $one)->assertSessionHasNoErrors();
        $this->post($url, [...$one, 'unit_cost' => 101])->assertSessionHasErrors('request_id');
        $this->post($url, ['request_id' => (string) Str::uuid(), 'qty' => 10, 'unit_cost' => 200])->assertSessionHasNoErrors();
        $sale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'idempotency_key' => 'traded-sale', 'lines' => [['item_id' => $item->id, 'qty' => 4, 'discount' => 40]], 'payments' => [['method' => 'cash', 'amount' => 1160]]]);

        $this->assertSame('150.0000', $sale->lines->sole()->unit_cost);
        $this->assertSame('600.0000', $sale->lines->sole()->total_cost);
        $this->assertSame('16.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
        $this->assertSame('300.0000', $item->fresh()->default_price);
        $this->assertDatabaseCount('goods_receipts', 2, 'tenant');
        $this->assertDatabaseCount('production_orders', 0, 'tenant');
        $this->assertDatabaseMissing('stock_movements', ['type' => 'production_consume'], 'tenant');
        $this->assertSame(['100.0000', '200.0000'], GoodsReceiptLine::query()->orderBy('unit_cost')->pluck('unit_cost')->all());
        $this->post($url, ['request_id' => (string) Str::uuid(), 'qty' => 5, 'unit_cost' => 400])->assertSessionHasNoErrors();
        $this->assertSame('600.0000', $sale->lines->sole()->fresh()->total_cost);
        $this->get($this->base().'/catalog/items/'.$item->id.'/edit', $this->headers())->assertOk()->assertJsonPath('props.latestPurchaseCost', '400.0000');
        $profit = app(ReportRegistry::class)->run('profit.operating', ['from' => '2026-10-13', 'to' => '2026-10-13', 'branch_id' => $this->branch()])['rows'][0];
        $this->assertSame('383.0508', $profit['gross_profit']);
    }

    public function test_fifo_sales_and_batches_cost_every_consumed_lot(): void
    {
        $this->workspace();
        Setting::putValue('valuation', 'fifo');
        $item = $this->traded();
        $receiver = app(StockReceipt::class);
        $receiver->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 3, 'unit_cost' => 10]);
        $receiver->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 4, 'unit_cost' => 20]);

        $sale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'lines' => [['item_id' => $item->id, 'qty' => 5]], 'payments' => [['method' => 'cash', 'amount' => 1500]]]);

        $this->assertSame('70.0000', $sale->lines->sole()->total_cost);
        $this->assertSame('14.0000', $sale->lines->sole()->unit_cost);
        $this->assertDatabaseHas('stock_balances', ['item_id' => $item->id, 'qty' => 2, 'value' => 40], 'tenant');
        $batch = $this->batch();
        $this->put($this->base().'/catalog/items/'.$batch->item_id.'/recipe', ['yield_qty' => 1, 'lines' => [['item_id' => $item->id, 'qty' => 1]]])->assertSessionHasNoErrors();
        $newBatch = $this->batch();
        $run = app(BatchProduction::class)->complete($newBatch, ['good_qty' => 1, 'waste_qty' => 0]);
        $this->assertSame('20.0000', $run->material_cost);
    }

    public function test_missing_purchase_cost_is_rejected_but_explicit_zero_cost_can_be_received_and_produced(): void
    {
        $this->workspace();
        $item = $this->traded();
        $url = $this->base().'/catalog/items/'.$item->id.'/stock';
        $data = ['request_id' => (string) Str::uuid(), 'qty' => 5];
        $this->post($url, $data)->assertSessionHasErrors('unit_cost');
        $this->post($url, [...$data, 'unit_cost' => -1])->assertSessionHasErrors('unit_cost');
        $this->post($url, [...$data, 'unit_cost' => 0, 'qty' => 0])->assertSessionHasErrors('qty');
        $this->assertDatabaseCount('goods_receipts', 0, 'tenant');
        $this->post($url, [...$data, 'unit_cost' => 0])->assertSessionHasNoErrors();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', ['yield_qty' => 1, 'lines' => [['item_id' => $item->id, 'qty' => 1]], 'labor' => 0, 'overhead' => 0])->assertSessionHasNoErrors();
        $run = app(BatchProduction::class)->complete($this->batch(), ['good_qty' => 1, 'waste_qty' => 0]);
        $this->assertSame('0.0000', $run->batch_cost);
        $this->assertSame('4.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
    }

    public function test_new_batches_require_produced_products_with_recipes_and_start_is_idempotent(): void
    {
        $this->workspace();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $data = ['request_id' => (string) Str::uuid(), 'item_id' => $product->id];
        $before = StockMovement::query()->count();
        $this->post($this->base().'/production/batches', $data)->assertSessionHasNoErrors();
        $this->post($this->base().'/production/batches', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('production_orders', 1, 'tenant');
        $this->assertSame($before, StockMovement::query()->count());
        $this->post($this->base().'/production/batches', ['request_id' => (string) Str::uuid(), 'item_id' => $this->traded()->id])->assertSessionHasErrors('item_id');
        $this->post($this->base().'/catalog/items/'.$product->id.'/stock', ['request_id' => (string) Str::uuid(), 'qty' => 1, 'unit_cost' => 10])->assertSessionHasErrors('stock');
    }

    public function test_receipts_use_current_branch_and_reject_unauthorized_users_and_foreign_items(): void
    {
        $this->workspace();
        $item = $this->traded();
        $other = Branch::query()->create(['name' => 'Other branch', 'code' => 'OTHER', 'status' => 'active']);
        $data = ['request_id' => (string) Str::uuid(), 'qty' => 3, 'unit_cost' => 10, 'branch_id' => $other->id];
        $this->post($this->base().'/catalog/items/'.$item->id.'/stock', $data)->assertSessionHasNoErrors();
        $this->assertSame('0.0000', app(StockLedger::class)->onHand($item->id, $other->id));
        $this->assertSame('3.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
        app(StockReceipt::class)->receive($item, $other->id, $data);
        $this->assertSame('3.0000', app(StockLedger::class)->onHand($item->id, $other->id));
        $this->post($this->base().'/catalog/items/'.Str::uuid().'/stock', $data)->assertNotFound();
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->post($this->base().'/catalog/items/'.$item->id.'/stock', [...$data, 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('goods_receipts', 2, 'tenant');
    }

    public function test_produced_and_traded_sales_store_costs_while_services_never_move_stock(): void
    {
        $this->workspace();
        $batch = $this->batch();
        $run = app(BatchProduction::class)->complete($batch, ['good_qty' => 18, 'waste_qty' => 2]);
        $item = $this->traded();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 2, 'unit_cost' => 50]);
        $service = Item::query()->create(['name' => 'Delivery', 'sku' => 'DELIVERY', 'type' => 'service', 'default_price' => 100, 'track_stock' => false]);
        $sale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'idempotency_key' => 'mixed-sale', 'lines' => [['item_id' => $batch->item_id, 'qty' => 1], ['item_id' => $item->id, 'qty' => 1], ['item_id' => $service->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2900]]]);
        $this->assertSame('360.7142', $sale->lines->firstWhere('item_id', $batch->item_id)->unit_cost);
        $this->assertSame('50.0000', $sale->lines->firstWhere('item_id', $item->id)->total_cost);
        $this->assertSame('0.0000', $sale->lines->firstWhere('item_id', $service->id)->total_cost);
        $this->assertDatabaseMissing('stock_movements', ['item_id' => $service->id], 'tenant');
        $before = StockMovement::query()->count();
        app(SaleService::class)->complete(['branch_id' => $this->branch(), 'idempotency_key' => 'mixed-sale', 'lines' => [], 'payments' => []]);
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame('7950.0000', $run->inventory_value);
    }

    public function test_make_to_order_profit_stays_pending(): void
    {
        $this->workspace();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $product->update(['make_mode' => 'make_to_order']);
        $sale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'lines' => [['item_id' => $product->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2500]]]);

        $this->assertSame('pending', $sale->lines->sole()->costing_status);
        $profit = app(ReportRegistry::class)->run('profit.operating', ['from' => '2026-10-13', 'to' => '2026-10-13', 'branch_id' => $this->branch()])['rows'][0];
        $this->assertNull($profit['gross_profit']);
        $this->assertNull($profit['operating_result']);
        $this->assertSame('Pending costing', $profit['costing_status']);
        $this->assertSame('24.0000', app(StockLedger::class)->onHand($product->id, $this->branch()));
    }

    public function test_customer_prices_and_branch_overrides_are_used_in_pos_and_in_sales(): void
    {
        $this->workspace();
        $item = $this->traded();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 5, 'unit_cost' => 50]);
        $list = PriceList::query()->create(['name' => 'Trade customers', 'channel' => 'custom', 'is_default' => false]);
        $customer = Customer::query()->create(['name' => 'Cafe', 'price_list_id' => $list->id]);
        PriceListItem::query()->create(['price_list_id' => $list->id, 'item_id' => $item->id, 'price' => 250]);
        PriceListItem::query()->create(['price_list_id' => $list->id, 'item_id' => $item->id, 'branch_id' => $this->branch(), 'price' => 200]);
        $other = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER', 'status' => 'active']);
        PriceListItem::query()->create(['price_list_id' => $list->id, 'item_id' => $item->id, 'branch_id' => $other->id, 'price' => 10]);

        $prices = $this->get($this->base().'/pos', $this->headers())->assertOk()->json('props.prices');
        $this->assertSame('200.0000', collect($prices)->firstWhere('item_id', $item->id)['price']);
        $this->assertNotContains('10.0000', array_column($prices, 'price'));
        $sale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'customer_id' => $customer->id, 'lines' => [['item_id' => $item->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 200]]]);
        $this->assertSame('200.0000', $sale->lines->sole()->unit_price);
        $this->assertSame('50.0000', $sale->lines->sole()->total_cost);
        PriceList::query()->update(['is_default' => false]);
        $this->assertSame('300.0000', app(SaleService::class)->priceFor($item, null, branchId: $this->branch()));
    }

    public function test_in_progress_output_and_entirely_wasted_output_cannot_be_sold_even_with_negative_stock_enabled(): void
    {
        $this->workspace();
        Setting::putValue('negative_stock', 'allow');
        $unit = Unit::query()->where('symbol', 'pc')->sole();
        $this->post($this->base().'/catalog/items', ['name' => 'New loaf', 'type' => 'finished_good', 'base_unit_id' => $unit->id, 'default_price' => 300])->assertSessionHasNoErrors();
        $product = Item::query()->where('name', 'New loaf')->sole();
        $this->put($this->base().'/catalog/items/'.$product->id.'/recipe', ['yield_qty' => 1, 'lines' => [['item_id' => Item::query()->where('sku', 'FLR-001')->value('id'), 'qty' => 1]]])->assertSessionHasNoErrors();
        $batch = app(BatchProduction::class)->start(['item_id' => $product->id, 'branch_id' => $this->branch()]);
        $sale = ['idempotency_key' => (string) Str::uuid(), 'lines' => [['item_id' => $product->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 300]]];

        $this->post($this->base().'/pos/sales', $sale)->assertSessionHasErrors('stock');
        app(BatchProduction::class)->complete($batch, ['good_qty' => 0, 'waste_qty' => 1]);
        $this->post($this->base().'/pos/sales', $sale)->assertSessionHasErrors('stock');

        $this->assertDatabaseCount('sales', 0, 'tenant');
        $this->assertSame('0.0000', app(StockLedger::class)->onHand($product->id, $this->branch()));
        $this->assertDatabaseMissing('stock_movements', ['item_id' => $product->id, 'type' => 'sale'], 'tenant');
    }

    public function test_receipt_history_is_immutable_and_adjustments_preserve_original_delivery_cost(): void
    {
        $this->workspace();
        $item = $this->traded();
        $receipt = app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 5, 'unit_cost' => 50]);
        $line = GoodsReceiptLine::query()->sole();
        foreach ([$receipt, $line] as $record) {
            try {
                $record->delete();
                $this->fail('A posted receipt was deleted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('stock', $exception->errors());
            }
        }
        try {
            $line->update(['unit_cost' => 100]);
            $this->fail('A historical purchase cost was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $adjustment = ['request_id' => (string) Str::uuid(), 'item_id' => $item->id, 'signed_qty' => -1, 'reason' => 'One damaged bottle on receipt '.$receipt->number];
        $this->post($this->base().'/inventory/adjustments', $adjustment)->assertSessionHasNoErrors();
        $this->post($this->base().'/inventory/adjustments', $adjustment)->assertSessionHasNoErrors();

        $this->assertSame('50.0000', $line->fresh()->unit_cost);
        $this->assertSame('5.0000', $line->fresh()->qty);
        $this->assertSame('4.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjusted', 'auditable_id' => $item->id], 'tenant');
    }

    public function test_receipt_failure_rolls_back_receipt_lines_inventory_and_lots(): void
    {
        $this->workspace();
        $item = $this->traded();
        $before = StockMovement::query()->count();
        $this->mock(AuditLogger::class)->shouldReceive('tenant')->once()->andThrow(new \RuntimeException('Audit unavailable'));

        try {
            app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 5, 'unit_cost' => 50]);
            $this->fail('A partially audited receipt was committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('goods_receipts', 0, 'tenant');
        $this->assertDatabaseCount('goods_receipt_lines', 0, 'tenant');
        $this->assertDatabaseMissing('stock_lots', ['item_id' => $item->id], 'tenant');
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertSame('0.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
    }

    private function workspace(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'product'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision(['name' => 'Product test', 'slug' => $this->slug, 'owner_name' => 'Owner', 'owner_email' => $this->slug.'@example.test', 'owner_password' => 'Password123!', 'industry_key' => 'bakery', 'sample' => true]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Password123!'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->actingAs(User::query()->firstOrFail());
    }

    private function branch(): string
    {
        return Branch::query()->where('code', 'MAIN')->value('id') ?? Branch::query()->oldest()->value('id');
    }

    private function batch(): ProductionOrder
    {
        return app(BatchProduction::class)->start(['item_id' => Item::query()->where('sku', 'BRD-001')->sole()->id, 'branch_id' => $this->branch()]);
    }

    private function traded(): Item
    {
        return Item::query()->create(['name' => 'Bottled water', 'sku' => 'WATER', 'type' => 'trading_good', 'base_unit_id' => Unit::query()->where('symbol', 'pc')->value('id'), 'default_price' => 300, 'track_stock' => true]);
    }

    private function base(): string
    {
        return '/w/'.$this->slug;
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }
}
