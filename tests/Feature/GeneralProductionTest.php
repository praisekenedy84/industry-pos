<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductionLine;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\Role;
use App\Models\RunOutput;
use App\Models\StockMovement;
use App\Models\TenantFeature;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Models\UserNotification;
use App\Services\AccessManager;
use App\Services\ProductionService;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GeneralProductionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $slug;

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            foreach (DB::connection('central')->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 't_%'") as $schema) {
                DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema->schema_name.' CASCADE');
            }
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_run_history_actual_scaling_and_idempotent_completion_and_handover(): void
    {
        $this->workspace();
        $branch = Branch::query()->firstOrFail();
        $recipe = Recipe::query()->firstOrFail();
        $line = ProductionLine::query()->firstOrFail();
        $ledger = app(StockLedger::class);
        $before = $ledger->onHand($recipe->item_id, $branch->id);
        $materials = RecipeLine::query()->get()->mapWithKeys(fn ($row) => [$row->item_id => $ledger->onHand($row->item_id, $branch->id)]);
        $this->post($this->base().'/production/orders', ['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'planned_qty' => 20, 'lot_code' => 'LOT-TEST', 'expiry_date' => '2027-01-01', 'lot_attributes' => ['grade' => 'A']])->assertSessionHasNoErrors();
        $order = ProductionOrder::query()->sole();
        $this->assertMatchesRegularExpression('/^PR-\d{8}-0001$/', $order->number);
        $this->assertSame($before, $ledger->onHand($recipe->item_id, $branch->id));
        $url = $this->base().'/production/orders/'.$order->id;
        $this->post($url.'/start')->assertSessionHasNoErrors();
        $this->post($url.'/hold', ['notes' => 'Waiting for packaging'])->assertSessionHasNoErrors();
        $this->post($url.'/complete', ['good_qty' => 18])->assertSessionHasErrors('status');
        $this->post($url.'/resume')->assertSessionHasNoErrors();
        $this->post($url.'/complete', ['good_qty' => 18, 'waste_qty' => 2])->assertSessionHasNoErrors();
        $this->assertSame(Bc::add($before, 18), $ledger->onHand($recipe->item_id, $branch->id));
        $version = RecipeVersion::query()->findOrFail($order->recipe_version_id);
        foreach (RecipeLine::query()->where('recipe_version_id', $version->id)->get() as $input) {
            $this->assertSame(Bc::sub($materials[$input->item_id], Bc::mul($input->qty, Bc::div(18, $version->yield_qty))), $ledger->onHand($input->item_id, $branch->id));
        }
        $count = StockMovement::query()->count();
        $this->post($url.'/complete', ['good_qty' => 18])->assertSessionHasNoErrors();
        $this->assertSame($count, StockMovement::query()->count());
        $this->post($url.'/complete', ['good_qty' => 19])->assertSessionHasErrors('good_qty');
        $this->post($url.'/start')->assertSessionHasErrors('status');
        $this->post($url.'/release', ['handover_note' => 'Collected by delivery driver'])->assertSessionHasNoErrors();
        $this->assertSame($before, $ledger->onHand($recipe->item_id, $branch->id));
        $this->post($url.'/release', ['handover_note' => 'Collected by delivery driver'])->assertSessionHasNoErrors();
        $this->assertSame($count + 1, StockMovement::query()->count());
        $this->assertSame('released', $order->fresh()->status);
        $this->assertSame(['planned', 'in_progress', 'on_hold', 'in_progress', 'completed', 'released'], AuditLog::query()->where('action', 'production.status_changed')->orderBy('created_at')->get()->map(fn ($event) => $event->after['status'])->all());
        $output = RunOutput::query()->sole();
        $this->assertSame($order->number, $output->run_number);
        $this->assertNotNull($output->lot_id);
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('props.order.actual_qty', '18.0000')->assertJsonCount(6, 'props.history');
        $this->assertDatabaseHas('stock_lots', ['lot_code' => 'LOT-TEST', 'qty' => '0.0000'], 'tenant');
    }

    public function test_stocked_components_can_be_outputs_and_produced_goods_can_be_inputs(): void
    {
        $this->workspace();
        $output = Item::query()->where('type', 'raw_material')->firstOrFail();
        $component = Item::query()->where('type', 'finished_good')->firstOrFail();
        $this->post($this->base().'/production/recipes', ['name' => 'General assembly bill', 'item_id' => $output->id, 'yield_qty' => 2, 'lines' => [['item_id' => $component->id, 'qty' => 1]]])->assertSessionHasNoErrors()->assertRedirect();
        $recipe = Recipe::query()->where('name', 'General assembly bill')->sole();
        $this->post($this->base().'/production/lines', ['name' => 'Assembly line', 'code' => 'ASSEMBLY', 'recipe_id' => $recipe->id, 'status' => 'active'])->assertSessionHasNoErrors();
        $line = ProductionLine::query()->where('code', 'ASSEMBLY')->sole();
        $this->post($this->base().'/production/orders', ['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'planned_qty' => 4])->assertSessionHasNoErrors();
        $order = ProductionOrder::query()->sole();
        $url = $this->base().'/production/orders/'.$order->id;
        $this->post($url.'/start')->assertSessionHasNoErrors();
        $this->post($url.'/complete')->assertSessionHasNoErrors();
        $this->assertSame('4.0000', $order->fresh()->actual_qty);
        $products = $this->get($this->base().'/pos', $this->headers())->assertOk()->json('props.items');
        $this->assertTrue(collect($products)->firstWhere('id', $output->id)['is_produced']);
        $this->assertDatabaseHas('run_outputs', ['item_id' => $output->id, 'qty' => 4], 'tenant');
        $this->post($this->base().'/production/recipes', ['name' => 'Self-consuming', 'item_id' => $output->id, 'yield_qty' => 1, 'lines' => [['item_id' => $output->id, 'qty' => 1]]])->assertSessionHasErrors('lines');
        try {
            $recipe->delete();
            $this->fail('A used bill was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipe', $exception->errors());
        }
        $this->assertNull($recipe->fresh()->deleted_at);
    }

    public function test_material_shortages_are_negative_and_late_receipts_clear_them(): void
    {
        $this->workspace();
        $recipe = Recipe::query()->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $ledger = app(StockLedger::class);
        $input = RecipeLine::query()->firstOrFail();
        $before = $ledger->onHand($input->item_id, $branch->id);
        $version = RecipeVersion::query()->findOrFail($input->recipe_version_id);
        $order = app(ProductionService::class)->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 10000]);
        app(ProductionService::class)->start($order);
        app(ProductionService::class)->complete($order, []);
        $after = Bc::sub($before, Bc::mul($input->qty, Bc::div(10000, $version->yield_qty)));
        $this->assertLessThan(0, Bc::cmp($after, 0));
        $this->assertSame($after, $ledger->onHand($input->item_id, $branch->id));
        $received = Bc::add(Bc::mul($after, -1), 10);
        $this->post($this->base().'/inventory/direct-receipts', ['request_id' => (string) Str::uuid(), 'item_id' => $input->item_id, 'qty' => $received, 'unit_cost' => 100])->assertSessionHasNoErrors();
        $this->assertSame('10.0000', $ledger->onHand($input->item_id, $branch->id));
        $this->assertSame('10.0000', Bc::num(StockMovement::query()->where('branch_id', $branch->id)->where('item_id', $input->item_id)->sum('signed_qty')));
    }

    public function test_direct_receipts_consume_bills_and_purchased_goods_do_not_consume_materials(): void
    {
        $this->workspace();
        $bread = Item::query()->where('sku', 'BRD-001')->sole();
        $soda = Item::query()->where('sku', 'SOD-001')->sole();
        $url = $this->base().'/inventory/direct-receipts';
        $made = ['request_id' => (string) Str::uuid(), 'item_id' => $bread->id, 'qty' => 10];
        $this->post($url, $made)->assertSessionHasNoErrors();
        $count = StockMovement::query()->count();
        $this->post($url, $made)->assertSessionHasNoErrors();
        $this->assertSame($count, StockMovement::query()->count());
        $this->post($url, [...$made, 'qty' => 11])->assertSessionHasErrors('request_id');
        $this->post($url, ['request_id' => (string) Str::uuid(), 'item_id' => $soda->id, 'qty' => 7, 'unit_cost' => 800])->assertSessionHasNoErrors();
        $this->assertSame($count + 1, StockMovement::query()->count());
        $this->assertDatabaseCount('production_orders', 0, 'tenant');
        $this->assertDatabaseCount('production_receipts', 2, 'tenant');
        $this->assertNotNull(ProductionReceipt::query()->where('item_id', $bread->id)->sole()->recipe_version_id);
        $this->assertNull(ProductionReceipt::query()->where('item_id', $soda->id)->sole()->recipe_version_id);
        $this->get($url, $this->headers())->assertOk()->assertJsonPath('component', 'Inventory/DirectReceipts');
        $input = RecipeLine::query()->firstOrFail();
        $this->post($this->base().'/production/recipes', ['name' => 'Alternative bread bill', 'item_id' => $bread->id, 'yield_qty' => 1, 'lines' => [['item_id' => $input->item_id, 'qty' => 1]]])->assertSessionHasNoErrors();
        app(SaleService::class)->complete([
            'branch_id' => Branch::query()->firstOrFail()->id, 'idempotency_key' => 'direct-receipt-sale',
            'lines' => [['item_id' => $bread->id, 'qty' => 1, 'unit_price' => 2000], ['item_id' => $soda->id, 'qty' => 1, 'unit_price' => 1500]],
            'payments' => [['method' => 'cash', 'amount' => 3500]],
        ]);
        $rows = $this->get($this->base().'/reports/sales.origin?from=2026-01-01&to=2027-01-01', $this->headers())->assertOk()->json('props.result.rows');
        $this->assertSame('2000.0000', collect($rows)->firstWhere('label', 'Produced')['gross']);
        $this->assertSame('1500.0000', collect($rows)->firstWhere('label', 'Traded')['gross']);
    }

    public function test_list_backfills_unposted_runs_and_retries_do_not_repeat_partial_inputs(): void
    {
        $this->workspace();
        $recipe = Recipe::query()->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $service = app(ProductionService::class);
        $order = $service->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 10]);
        $order->update(['status' => 'completed', 'actual_qty' => 10]);
        $run = ProductionRun::query()->create(['production_order_id' => $order->id, 'status' => 'completed', 'good_qty' => 10]);
        $input = RecipeLine::query()->firstOrFail();
        $version = RecipeVersion::query()->findOrFail($input->recipe_version_id);
        $qty = Bc::mul($input->qty, Bc::div(10, $version->yield_qty));
        app(StockLedger::class)->post(['item_id' => $input->item_id, 'branch_id' => $branch->id, 'type' => 'production_consume', 'quantity' => $qty, 'signed_qty' => Bc::mul($qty, -1), 'reference_type' => 'production_run', 'reference_id' => $run->id]);
        $this->get($this->base().'/production/orders', $this->headers())->assertOk();
        $this->assertNotNull($order->fresh()->stock_posted_at);
        $this->assertSame(1, StockMovement::query()->where('reference_id', $run->id)->where('type', 'production_consume')->where('item_id', $input->item_id)->count());
        $count = StockMovement::query()->count();
        $this->get($this->base().'/production/orders', $this->headers())->assertOk();
        $this->artisan('makerly:production-maintain', ['--tenant' => $this->slug])->assertSuccessful();
        $this->assertSame($count, StockMovement::query()->count());
        $order->update(['updated_at' => now()->subDays(2)]);
        $this->artisan('makerly:production-maintain', ['--tenant' => $this->slug])->assertSuccessful();
        $this->artisan('makerly:production-maintain', ['--tenant' => $this->slug])->assertSuccessful();
        $this->assertSame(1, UserNotification::query()->where('type', 'production_follow_up')->count());
    }

    public function test_release_transfers_once_and_other_locations_are_isolated(): void
    {
        $this->workspace();
        $recipe = Recipe::query()->firstOrFail();
        $source = Branch::query()->firstOrFail();
        $destination = Branch::query()->create(['name' => 'Sales shop', 'code' => 'SHOP', 'status' => 'active']);
        TenantFeature::query()->where('feature_key', 'branches.multi_branch')->update(['value' => true]);
        $order = app(ProductionService::class)->plan(['recipe_id' => $recipe->id, 'branch_id' => $source->id, 'planned_qty' => 6]);
        $url = $this->base().'/production/orders/'.$order->id;
        $this->post($url.'/start')->assertSessionHasNoErrors();
        $this->post($url.'/release', ['destination_branch_id' => $destination->id])->assertSessionHasNoErrors();
        $ledger = app(StockLedger::class);
        $this->assertSame('6.0000', $ledger->onHand($recipe->item_id, $destination->id));
        $count = StockMovement::query()->count();
        $this->post($url.'/release', ['destination_branch_id' => $destination->id])->assertSessionHasNoErrors();
        $this->assertSame($count, StockMovement::query()->count());
        $line = ProductionLine::factory()->create(['branch_id' => $destination->id, 'recipe_id' => $recipe->id]);
        $otherOrder = app(ProductionService::class)->plan(['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'branch_id' => $destination->id, 'planned_qty' => 1]);
        $this->get($this->base().'/production/orders/'.$otherOrder->id)->assertNotFound();
        $this->post($this->base().'/production/orders/'.$otherOrder->id.'/start')->assertNotFound();
        $this->get($this->base().'/production/orders/'.$order->id, $this->headers())->assertOk();
    }

    public function test_bill_creates_the_made_item_and_stores_mixture_cost_and_selling_prices(): void
    {
        $this->workspace();
        $unit = Unit::query()->firstOrFail();

        $this->post($this->base().'/production/recipes', [
            'name' => 'Honey cake',
            'yield_qty' => 10,
            'item' => ['name' => 'Honey cake', 'base_unit_id' => $unit->id],
            'retail_price' => 800,
            'wholesale_price' => 600,
            'lines' => [
                ['name' => 'Cake flour', 'base_unit_id' => $unit->id, 'qty' => 2, 'unit_cost' => 1000],
                ['name' => 'Honey', 'base_unit_id' => $unit->id, 'qty' => 1, 'unit_cost' => 500],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $cake = Item::query()->where('name', 'Honey cake')->sole();
        $flour = Item::query()->where('name', 'Cake flour')->sole();
        $honey = Item::query()->where('name', 'Honey')->sole();
        $version = RecipeVersion::query()->where('recipe_id', Recipe::query()->where('name', 'Honey cake')->sole()->id)->sole();
        $retail = PriceList::query()->where('channel', 'retail')->sole();
        $wholesale = PriceList::query()->where('channel', 'wholesale')->sole();

        $this->assertSame('finished_good', $cake->type);
        $this->assertSame('800.0000', $cake->default_price);
        $this->assertSame('raw_material', $flour->type);
        $this->assertSame('raw_material', $honey->type);
        $this->assertSame('10.0000', $version->yield_qty);
        $this->assertSame('2.0000', RecipeLine::query()->where('item_id', $flour->id)->sole()->qty);
        $this->assertSame('1000.0000', RecipeLine::query()->where('item_id', $flour->id)->sole()->unit_cost);
        $this->assertSame('1.0000', RecipeLine::query()->where('item_id', $honey->id)->sole()->qty);
        $this->assertSame('500.0000', RecipeLine::query()->where('item_id', $honey->id)->sole()->unit_cost);
        $this->assertSame('800.0000', PriceListItem::query()->where('price_list_id', $retail->id)->where('item_id', $cake->id)->sole()->price);
        $this->assertSame('600.0000', PriceListItem::query()->where('price_list_id', $wholesale->id)->where('item_id', $cake->id)->sole()->price);
    }

    public function test_bill_requires_the_item_being_made(): void
    {
        $this->workspace();
        $material = Item::query()->where('type', 'raw_material')->firstOrFail();

        $this->post($this->base().'/production/recipes', [
            'name' => 'Unnamed output',
            'yield_qty' => 1,
            'lines' => [['item_id' => $material->id, 'qty' => 1, 'unit_cost' => 10]],
        ])->assertSessionHasErrors(['item.name' => 'Name the item being made, or choose an existing one.']);
    }

    public function test_production_lead_cannot_create_items_or_change_selling_prices_from_a_bill(): void
    {
        $this->workspace();
        $owner = User::query()->firstOrFail();
        $output = Item::query()->where('type', 'finished_good')->firstOrFail();
        $material = Item::query()->where('type', 'raw_material')->firstOrFail();
        $price = $output->default_price;
        $unit = Unit::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'production_lead')->value('id')]);
        app(AccessManager::class)->forget($owner);

        $this->post($this->base().'/production/recipes', [
            'name' => 'Secret cake',
            'yield_qty' => 1,
            'item' => ['name' => 'Secret cake', 'base_unit_id' => $unit->id],
            'lines' => [['item_id' => $material->id, 'qty' => 1, 'unit_cost' => 10]],
        ])->assertSessionHasErrors(['item.name' => 'You need permission to create catalog items.']);
        $this->assertDatabaseMissing('items', ['name' => 'Secret cake'], 'tenant');

        $this->post($this->base().'/production/recipes', [
            'name' => 'Price change bill',
            'item_id' => $output->id,
            'yield_qty' => 1,
            'retail_price' => 1,
            'lines' => [['item_id' => $material->id, 'qty' => 1, 'unit_cost' => 10]],
        ])->assertSessionHasErrors(['retail_price' => 'You need permission to set selling prices.']);
        $this->assertSame($price, $output->fresh()->default_price);
        $this->assertDatabaseMissing('recipes', ['name' => 'Price change bill'], 'tenant');
    }

    public function test_daily_run_numbers_reset_and_cashiers_cannot_post_production_or_receipts(): void
    {
        $this->workspace();
        $recipe = Recipe::query()->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $service = app(ProductionService::class);
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $one = $service->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 1]);
        $two = $service->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 1]);
        $this->assertSame('PR-20261012-0001', $one->number);
        $this->assertSame('PR-20261012-0002', $two->number);
        $this->travel(1)->days();
        $three = $service->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 1]);
        $this->assertSame('PR-20261013-0001', $three->number);
        $this->travelBack();
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->post($this->base().'/production/orders/'.$one->id.'/start')->assertForbidden();
        $this->post($this->base().'/inventory/direct-receipts', ['request_id' => (string) Str::uuid(), 'item_id' => $recipe->item_id, 'qty' => 1])->assertForbidden();
    }

    private function workspace(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'flow'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision(['name' => 'General flow', 'slug' => $this->slug, 'owner_name' => 'Owner', 'owner_email' => $this->slug.'@example.test', 'owner_password' => 'Makerly#2026', 'industry_key' => 'bakery', 'sample' => true]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Makerly#2026'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
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
