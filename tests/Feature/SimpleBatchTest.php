<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionRun;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Reports\ReportRegistry;
use App\Services\AccessManager;
use App\Services\BatchProduction;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SimpleBatchTest extends TestCase
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

    public function test_start_and_completion_post_good_stock_and_expensed_waste_once_and_allow_sales(): void
    {
        $this->workspace();
        Setting::putValue('production_waste_treatment', 'expensed');
        Setting::putValue('production_account_production_loss', '6100-Production-loss');
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $branch = Branch::query()->firstOrFail();
        $movements = StockMovement::query()->count();

        $this->post($this->base().'/production/batches', ['request_id' => (string) Str::uuid(), 'item_id' => $product->id, 'notes' => 'Morning batch'])->assertSessionHasNoErrors()->assertRedirect();

        $order = ProductionOrder::query()->sole();
        $this->assertSame('in_progress', $order->status);
        $this->assertNull($order->planned_qty);
        $this->assertNull($order->production_line_id);
        $this->assertNotNull($order->number);
        $this->assertSame($movements, StockMovement::query()->count());
        $url = $this->base().'/production/batches/'.$order->id.'/complete';
        $data = ['good_qty' => 18, 'waste_qty' => 2, 'waste_reason' => 'Damaged', 'notes' => 'Morning result'];
        $this->post($url, $data)->assertSessionHasNoErrors();
        $run = ProductionRun::query()->sole();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('7950.0000', $run->batch_cost);
        $this->assertSame('7155.0000', $run->inventory_value);
        $this->assertSame('795.0000', $run->waste_cost);
        $this->assertSame('795.0000', $run->loss_value);
        $this->assertSame('42.0000', app(StockLedger::class)->onHand($product->id, $branch->id));
        $this->assertDatabaseHas('run_waste', ['production_run_id' => $run->id, 'item_id' => $product->id, 'qty' => 2, 'cost_value' => 795, 'unit_id' => $product->base_unit_id, 'branch_id' => $branch->id, 'recorded_by' => User::query()->firstOrFail()->id], 'tenant');
        $this->assertDatabaseHas('stock_movements', ['reference_id' => $run->id, 'type' => 'production_output', 'quantity' => 18, 'total_cost' => 7155], 'tenant');
        $this->assertDatabaseHas('production_journal_lines', ['account_code' => '6100-Production-loss', 'debit' => 795], 'tenant');
        $this->assertSame('7950.0000', DB::connection('tenant')->table('production_journal_lines')->sum('debit'));
        $this->assertSame('7950.0000', DB::connection('tenant')->table('production_journal_lines')->sum('credit'));
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        $this->assertSame('45.0000', app(StockLedger::class)->onHand($flour->id, $branch->id));
        $posted = StockMovement::query()->count();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame($posted, StockMovement::query()->count());
        $this->assertDatabaseCount('production_journals', 1, 'tenant');
        $this->assertDatabaseCount('run_waste', 1, 'tenant');
        $this->post($url, [...$data, 'good_qty' => 19])->assertSessionHasErrors('good_qty');
        $this->post($url, [...$data, 'waste_qty' => 3])->assertSessionHasErrors('good_qty');
        $this->post($this->base().'/production/batches/'.$order->id.'/cancel')->assertSessionHasErrors('status');
        $this->assertSame($posted, StockMovement::query()->count());
        $this->get($this->base().'/production/batches/'.$order->id, $this->headers())->assertOk()->assertJsonPath('props.run.good_qty', '18.0000')->assertJsonPath('props.journal.inventory_value', '7155.0000');
        app(SaleService::class)->complete(['branch_id' => $branch->id, 'idempotency_key' => 'batch-sale', 'lines' => [['item_id' => $product->id, 'qty' => 1, 'unit_price' => 2500]], 'payments' => [['method' => 'cash', 'amount' => 2500]]]);
        $this->assertSame('41.0000', app(StockLedger::class)->onHand($product->id, $branch->id));
    }

    public function test_absorbed_waste_remains_visible_without_duplicate_loss_and_receipt_value_is_exact(): void
    {
        $this->workspace();
        $order = $this->startBatch();

        $run = app(BatchProduction::class)->complete($order, ['good_qty' => 18, 'waste_qty' => 2]);

        $this->assertSame('7950.0000', $run->inventory_value);
        $this->assertSame('795.0000', $run->waste_cost);
        $this->assertSame('0.0000', $run->loss_value);
        $this->assertDatabaseMissing('production_journal_lines', ['purpose' => 'production_loss'], 'tenant');
        $this->assertDatabaseHas('stock_movements', ['reference_id' => $run->id, 'type' => 'production_output', 'total_cost' => 7950], 'tenant');
        $this->assertDatabaseHas('stock_balances', ['item_id' => $order->item_id, 'lot_id' => null, 'value' => 15150, 'qty' => 42], 'tenant');
        $filters = ['from' => '2026-10-12', 'to' => '2026-10-12', 'branch_id' => $order->branch_id];
        $this->assertSame('795.0000', app(ReportRegistry::class)->run('production.waste', $filters)['rows'][0]->cost_value);
        $this->assertSame('0.0000', app(ReportRegistry::class)->run('profit.operating', $filters)['rows'][0]['production_loss']);
    }

    public function test_entirely_wasted_batch_posts_only_loss_and_materials_and_can_be_retried(): void
    {
        $this->workspace();
        $order = $this->startBatch();
        $url = $this->base().'/production/batches/'.$order->id.'/complete';

        $this->post($url, ['good_qty' => 0, 'waste_qty' => 20])->assertSessionHasNoErrors();
        $this->post($url, ['good_qty' => 0, 'waste_qty' => 20])->assertSessionHasNoErrors();

        $run = ProductionRun::query()->sole();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('0.0000', $run->good_qty);
        $this->assertSame('0.0000', $run->inventory_value);
        $this->assertSame('7950.0000', $run->loss_value);
        $this->assertDatabaseCount('run_outputs', 0, 'tenant');
        $this->assertDatabaseCount('production_journals', 1, 'tenant');
        $this->assertDatabaseMissing('stock_movements', ['type' => 'production_output'], 'tenant');
        $this->assertSame('24.0000', app(StockLedger::class)->onHand($order->item_id, $order->branch_id));
        $filters = ['from' => '2026-10-12', 'to' => '2026-10-12', 'branch_id' => $order->branch_id];
        $result = app(ReportRegistry::class)->run('profit.operating', $filters)['rows'][0];
        $this->assertSame('7950.0000', $result['production_loss']);
        $this->assertSame('-7950.0000', $result['operating_result']);
    }

    public function test_invalid_quantities_and_missing_cost_or_account_leave_every_posting_unchanged(): void
    {
        $this->workspace();
        $order = $this->startBatch();
        $url = $this->base().'/production/batches/'.$order->id.'/complete';
        $before = StockMovement::query()->count();
        $this->post($url, ['good_qty' => -1, 'waste_qty' => 2])->assertSessionHasErrors('good_qty');
        $this->post($url, ['good_qty' => 1, 'waste_qty' => -2])->assertSessionHasErrors('waste_qty');
        $this->post($url, ['good_qty' => 0, 'waste_qty' => 0])->assertSessionHasErrors('good_qty');
        $flour = Item::query()->where('sku', 'FLR-001')->sole();
        StockBalance::query()->where('item_id', $flour->id)->update(['avg_cost' => 0]);
        $this->post($url, ['good_qty' => 18, 'waste_qty' => 2])->assertSessionHasErrors('cost');
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertDatabaseCount('production_runs', 0, 'tenant');
        StockBalance::query()->where('item_id', $flour->id)->update(['avg_cost' => 1000]);
        Setting::putValue('production_account_finished_inventory', '');
        $this->post($url, ['good_qty' => 18, 'waste_qty' => 2])->assertSessionHasErrors('cost');
        $this->assertSame($before, StockMovement::query()->count());
        $this->assertDatabaseCount('production_runs', 0, 'tenant');
        $this->assertDatabaseCount('run_waste', 0, 'tenant');
        $this->assertDatabaseCount('production_journals', 0, 'tenant');
        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_waste_in_another_unit_requires_conversion_and_uses_the_converted_quantity(): void
    {
        $this->workspace();
        $product = Item::query()->where('sku', 'BRD-001')->sole();
        $kilogram = Unit::query()->where('symbol', 'kg')->sole();
        $gram = Unit::query()->where('symbol', 'g')->sole();
        $litre = Unit::query()->where('symbol', 'L')->sole();
        $product->update(['base_unit_id' => $kilogram->id]);
        $order = $this->startBatch();
        $url = $this->base().'/production/batches/'.$order->id.'/complete';

        $this->post($url, ['good_qty' => 18, 'waste_qty' => 2, 'waste_unit_id' => $litre->id])->assertSessionHasErrors('waste_unit_id');
        $this->assertDatabaseCount('production_runs', 0, 'tenant');
        $this->post($url, ['good_qty' => 18, 'waste_qty' => 2000, 'waste_unit_id' => $gram->id])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('run_waste', ['qty' => 2000, 'unit_id' => $gram->id, 'output_equivalent_qty' => 2, 'cost_value' => 795], 'tenant');
        $this->assertSame('7950.0000', ProductionRun::query()->sole()->batch_cost);
    }

    public function test_material_tracking_can_be_disabled_and_a_product_without_recipe_requires_configured_cost(): void
    {
        $this->workspace();
        $this->put($this->base().'/settings', ['production_track_materials' => false, 'production_waste_treatment' => 'absorbed'])->assertSessionHasNoErrors();
        $order = $this->startBatch();
        app(BatchProduction::class)->complete($order, ['good_qty' => 18, 'waste_qty' => 2]);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'production_consume'], 'tenant');
        $this->assertDatabaseHas('production_journal_lines', ['purpose' => 'production_clearing', 'credit' => 7950], 'tenant');
        $product = Item::query()->where('sku', 'SOD-001')->sole();
        $this->post($this->base().'/production/batches', ['request_id' => (string) Str::uuid(), 'item_id' => $product->id])->assertSessionHasErrors('item_id');
        $batch = ProductionOrder::query()->create(['number' => 'LEGACY-BATCH', 'item_id' => $product->id, 'output_unit_id' => $product->base_unit_id, 'branch_id' => $order->branch_id, 'workflow_version' => 2, 'status' => 'in_progress']);
        $url = $this->base().'/production/batches/'.$batch->id.'/complete';
        $this->post($url, ['good_qty' => 4, 'waste_qty' => 1])->assertSessionHasErrors('cost');
        $this->put($this->base().'/catalog/items/'.$product->id, ['name' => $product->name, 'type' => $product->type, 'sku' => $product->sku, 'base_unit_id' => $product->base_unit_id, 'default_price' => $product->default_price, 'production_unit_cost' => 800])->assertSessionHasNoErrors();
        $this->post($url, ['good_qty' => 4, 'waste_qty' => 1])->assertSessionHasNoErrors();
        $this->assertSame('4000.0000', ProductionRun::query()->where('production_order_id', $batch->id)->sole()->batch_cost);
    }

    public function test_batches_have_unique_numbers_and_cannot_be_completed_from_another_branch_or_by_a_cashier(): void
    {
        $this->workspace();
        $one = $this->startBatch();
        $two = $this->startBatch();
        $this->assertNotSame($one->number, $two->number);
        $branch = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER', 'status' => 'active']);
        $other = app(BatchProduction::class)->start(['item_id' => $one->item_id, 'branch_id' => $branch->id]);
        $this->post($this->base().'/production/batches/'.$other->id.'/complete', ['good_qty' => 1])->assertNotFound();
        $this->post($this->base().'/production/orders/'.$one->id.'/hold')->assertSessionHasErrors('status');
        $this->post($this->base().'/production/orders/'.$one->id.'/release', ['handover_note' => 'Driver'])->assertSessionHasErrors('status');
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->post($this->base().'/production/batches/'.$one->id.'/complete', ['good_qty' => 1])->assertForbidden();
        $this->assertDatabaseCount('production_runs', 0, 'tenant');
    }

    public function test_reversal_restores_inventory_and_materials_and_reverses_the_journal_once(): void
    {
        $this->workspace();
        Setting::putValue('production_waste_treatment', 'expensed');
        $batch = $this->startBatch();
        $run = app(BatchProduction::class)->complete($batch, ['good_qty' => 18, 'waste_qty' => 2]);
        $url = $this->base().'/production/batches/'.$batch->id.'/reverse';

        $this->post($url, ['reason' => 'Wrong recorded output'])->assertSessionHasNoErrors();
        $this->post($url, ['reason' => 'Wrong recorded output'])->assertSessionHasNoErrors();

        $this->assertSame('reversed', $batch->fresh()->status);
        $this->assertSame('18.0000', $run->fresh()->good_qty);
        $this->assertDatabaseCount('run_waste', 1, 'tenant');
        $this->assertDatabaseCount('production_journals', 2, 'tenant');
        $this->assertSame('0.0000', DB::connection('tenant')->table('production_journals')->sum('loss_value'));
        $this->assertSame(DB::connection('tenant')->table('production_journal_lines')->sum('debit'), DB::connection('tenant')->table('production_journal_lines')->sum('credit'));
        $this->assertDatabaseHas('stock_balances', ['item_id' => $batch->item_id, 'lot_id' => null, 'qty' => 24, 'value' => 7200], 'tenant');
        $this->assertDatabaseHas('stock_balances', ['item_id' => Item::query()->where('sku', 'FLR-001')->sole()->id, 'lot_id' => null, 'qty' => 50, 'value' => 50000], 'tenant');
        $this->post($this->base().'/production/batches/'.$batch->id.'/complete', ['good_qty' => 18, 'waste_qty' => 2])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('audit_logs', ['action' => 'production.reversed', 'auditable_id' => $run->id], 'tenant');

        $wasted = $this->startBatch();
        app(BatchProduction::class)->complete($wasted, ['good_qty' => 0, 'waste_qty' => 20]);
        $this->post($this->base().'/production/batches/'.$wasted->id.'/reverse', ['reason' => 'Wrong waste entry'])->assertSessionHasNoErrors();
        $this->assertSame('0.0000', DB::connection('tenant')->table('production_journals')->sum('loss_value'));
        $this->assertSame('24.0000', app(StockLedger::class)->onHand($batch->item_id, $batch->branch_id));
    }

    public function test_reversal_requires_remaining_stock_and_completed_results_cannot_be_edited(): void
    {
        $this->workspace();
        $batch = $this->startBatch();
        $run = app(BatchProduction::class)->complete($batch, ['good_qty' => 18, 'waste_qty' => 2]);
        try {
            $run->update(['good_qty' => 19]);
            $this->fail('Completed output was edited.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('good_qty', $exception->errors());
        }
        $this->assertSame('18.0000', $run->fresh()->good_qty);
        $this->actingAs(User::query()->firstOrFail());
        app(SaleService::class)->complete(['branch_id' => $batch->branch_id, 'idempotency_key' => 'sell-batch-stock', 'lines' => [['item_id' => $batch->item_id, 'qty' => 25, 'unit_price' => 2500]], 'payments' => [['method' => 'cash', 'amount' => 62500]]]);
        $before = StockMovement::query()->count();

        $this->post($this->base().'/production/batches/'.$batch->id.'/reverse', ['reason' => 'Correction'])->assertSessionHasErrors('stock');

        $this->assertSame($before, StockMovement::query()->count());
        $this->assertDatabaseCount('production_journals', 1, 'tenant');
        $this->assertSame('completed', $batch->fresh()->status);
        $unfinished = $this->startBatch();
        $this->post($this->base().'/production/batches/'.$unfinished->id.'/cancel')->assertSessionHasNoErrors();
        $this->post($this->base().'/production/batches/'.$unfinished->id.'/complete', ['good_qty' => 1, 'waste_qty' => 0])->assertSessionHasErrors('status');
        $this->assertSame($before, StockMovement::query()->count());
    }

    private function workspace(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'batch'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision(['name' => 'Batch test', 'slug' => $this->slug, 'owner_name' => 'Owner', 'owner_email' => $this->slug.'@example.test', 'owner_password' => 'Password123!', 'industry_key' => 'bakery', 'sample' => true]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Password123!'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function startBatch(): ProductionOrder
    {
        return app(BatchProduction::class)->start(['item_id' => Item::query()->where('sku', 'BRD-001')->sole()->id, 'branch_id' => Branch::query()->firstOrFail()->id]);
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
