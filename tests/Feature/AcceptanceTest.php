<?php

namespace Tests\Feature;

use App\Auth\SessionVault;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ActiveSession;
use App\Models\ApiToken;
use App\Models\ApiTokenTenant;
use App\Models\Branch;
use App\Models\Item;
use App\Models\PosDraft;
use App\Models\ProductionLine;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\RunWaste;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Services\AccessManager;
use App\Services\ProductionService;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            $schemas = DB::connection('central')->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 't_%'");
            foreach ($schemas as $schema) {
                DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema->schema_name.' CASCADE');
            }
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_disabled_module_is_not_found(): void
    {
        [$slug, $email] = $this->workspace('flags');
        TenantFeature::query()->where('feature_key', 'module.production')->update(['value' => false]);
        $this->login($slug, $email);

        $this->get('/w/'.$slug.'/production/orders')->assertNotFound();
    }

    public function test_navigation_groups_sections_and_hides_disabled_features(): void
    {
        [$slug, $email] = $this->workspace('navigation');
        TenantFeature::query()->whereIn('feature_key', ['module.production', 'pricing.multi_channel', 'branches.multi_branch', 'inventory.lots_expiry'])->update(['value' => false]);
        $this->login($slug, $email);

        $response = $this->get('/w/'.$slug.'/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))]);
        $response->assertOk();
        $navigation = collect($response->json('props.nav'))->keyBy('label');

        $this->assertSame('nav.dashboard', $response->json('props.nav.0.label'));
        $this->assertArrayNotHasKey('nav.production', $navigation->all());
        $this->assertSame(
            ['/w/'.$slug.'/catalog/items', '/w/'.$slug.'/catalog/categories', '/w/'.$slug.'/catalog/units'],
            array_column($navigation['nav.catalog']['children'], 'href'),
        );
        $inventoryLinks = array_column($navigation['nav.inventory']['children'], 'href');
        $this->assertContains('/w/'.$slug.'/inventory/counts', $inventoryLinks);
        $this->assertNotContains('/w/'.$slug.'/inventory/transfers', $inventoryLinks);
        $this->assertNotContains('/w/'.$slug.'/inventory/lots', $inventoryLinks);
        $this->assertContains('/w/'.$slug.'/settings/roles', array_column($navigation['nav.settings']['children'], 'href'));
    }

    public function test_navigation_hides_children_without_permission(): void
    {
        [$slug, $email] = $this->workspace('navpermissions');
        $this->login($slug, $email);
        $this->mock(AccessManager::class, function ($mock) {
            $mock->shouldReceive('allows')->andReturnUsing(fn ($user, $permission) => in_array($permission, ['catalog.items.view', 'users.view'], true));
        });

        $response = $this->get('/w/'.$slug.'/catalog/items', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))]);
        $response->assertOk();
        $navigation = collect($response->json('props.nav'))->keyBy('label');

        $this->assertSame(['People', 'Branches'], array_column($navigation['nav.settings']['children'], 'label'));
        $this->assertArrayNotHasKey('nav.reports', $navigation->all());
        $this->assertArrayNotHasKey('nav.pos', $navigation->all());
    }

    public function test_dashboard_trend_uses_local_days_completed_sales_and_selected_branch(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
        [$slug, $email] = $this->workspace('chartdata');
        $branch = Branch::query()->firstOrFail();
        $otherBranch = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER', 'status' => 'active']);
        $sales = [
            ['2026-10-10 20:59:00', '100', 'completed', $branch->id],
            ['2026-10-10 21:00:00', '200', 'completed', $branch->id],
            ['2026-10-11 10:00:00', '300', 'completed', $branch->id],
            ['2026-10-11 10:00:00', '900', 'void', $branch->id],
            ['2026-10-11 10:00:00', '800', 'completed', $otherBranch->id],
            ['2026-09-11 20:59:00', '700', 'completed', $branch->id],
        ];
        foreach ($sales as $index => [$date, $total, $status, $branchId]) {
            Sale::query()->create(['number' => 'CHART-'.$index, 'branch_id' => $branchId, 'user_id' => User::query()->where('email', $email)->value('id'), 'total' => $total, 'status' => $status, 'created_at' => $date]);
        }
        $this->login($slug, $email);

        $response = $this->get('/w/'.$slug.'/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))]);
        $response->assertOk();
        $trend = $response->json('props.trend');

        $this->assertCount(30, $trend);
        $this->assertSame('2026-09-12', $trend[0]['date']);
        $this->assertSame(0, $trend[0]['orders']);
        $this->assertSame('2026-10-10', $trend[28]['date']);
        $this->assertSame('100.0000', $trend[28]['sales']);
        $this->assertSame('2026-10-11', $trend[29]['date']);
        $this->assertSame('500.0000', $trend[29]['sales']);
        $this->assertSame(2, $trend[29]['orders']);
    }

    public function test_dashboard_does_not_expose_sales_charts_without_permission(): void
    {
        [$slug, $email] = $this->workspace('chartprivate');
        $this->login($slug, $email);
        $this->mock(AccessManager::class, function ($mock) {
            $mock->shouldReceive('allows')->andReturn(false);
        });

        $this->get('/w/'.$slug.'/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))])
            ->assertOk()->assertJsonPath('props.trend', null)->assertJsonPath('props.top', []);
    }

    public function test_deactivated_user_loses_the_session(): void
    {
        [$slug, $email] = $this->workspace('locked');
        $this->login($slug, $email);

        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        app(TenancyManager::class)->initialize($tenant);
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->update(['status' => 'deactivated']);
        ActiveSession::query()->where('user_id', $user->id)->delete();

        $this->get('/w/'.$slug.'/dashboard')
            ->assertRedirect('/w/'.$slug.'/login')
            ->assertSessionHas('error', 'Your access to this workspace has ended. Contact your administrator.');
    }

    public function test_idle_timeout_keeps_the_pos_cart(): void
    {
        [$slug, $email] = $this->workspace('idle');
        $this->login($slug, $email);
        $store = Cache::getStore();
        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);
        $key = collect(array_keys($storage))->first(fn ($name) => str_starts_with($name, 'ws:'));
        $this->assertNotNull($key);
        $payload = $storage[$key]['value'];
        $payload['last_activity'] = time() - (31 * 60);
        $payload['cart'] = [['item_id' => 'held', 'qty' => 2]];
        app(SessionVault::class)->put($key, $payload, 3600);

        $this->get('/w/'.$slug.'/dashboard')
            ->assertRedirect('/w/'.$slug.'/login')
            ->assertSessionHas('status', __('auth.session_ended'));

        $this->assertNotNull(PosDraft::query()->where('name', 'idle')->first());
        $this->get('/w/'.$slug.'/dashboard')->assertRedirect('/w/'.$slug.'/login');
    }

    public function test_locked_workspace_returns_to_login(): void
    {
        [$slug, $email] = $this->workspace('paused');
        $this->login($slug, $email);
        $store = Cache::getStore();
        $storage = (new \ReflectionProperty($store, 'storage'))->getValue($store);
        $key = collect(array_keys($storage))->first(fn ($name) => str_starts_with($name, 'ws:'));
        $this->assertNotNull($key);
        $payload = $storage[$key]['value'];
        $payload['locked'] = true;
        $payload['last_activity'] = time();
        app(SessionVault::class)->put($key, $payload, 3600);

        $this->get('/w/'.$slug.'/dashboard')
            ->assertRedirect('/w/'.$slug.'/login')
            ->assertSessionHas('status', __('auth.session_ended'));
        $this->get('/w/'.$slug.'/unlock')->assertRedirect('/w/'.$slug.'/login');
    }

    public function test_production_yield_and_split_payment(): void
    {
        $this->workspace('yield');
        $owner = User::query()->firstOrFail();
        Auth::login($owner);
        $branch = Branch::query()->firstOrFail();
        $recipe = Recipe::query()->firstOrFail();
        $bread = Item::query()->where('sku', 'BRD-001')->firstOrFail();
        $soda = Item::query()->where('sku', 'SOD-001')->firstOrFail();
        $ledger = app(StockLedger::class);
        $before = $ledger->onHand($bread->id, $branch->id);

        $order = app(ProductionService::class)->plan([
            'branch_id' => $branch->id,
            'recipe_id' => $recipe->id,
            'planned_qty' => 20,
        ]);
        $run = app(ProductionService::class)->complete($order, [
            'good_qty' => 18,
            'waste_qty' => 2,
            'labor_cost' => 0,
            'overhead_cost' => 0,
        ]);

        $this->assertSame(Bc::div('4905', '18'), Bc::num($run->unit_cost));
        $this->assertSame(Bc::add($before, '18'), $ledger->onHand($bread->id, $branch->id));
        $this->assertSame('2.0000', Bc::num(RunWaste::query()->where('production_run_id', $run->id)->value('qty')));

        $sodaBefore = $ledger->onHand($soda->id, $branch->id);
        $sale = app(SaleService::class)->complete([
            'branch_id' => $branch->id,
            'idempotency_key' => 'split-1',
            'lines' => [['item_id' => $soda->id, 'qty' => 1, 'unit_price' => 1500]],
            'payments' => [
                ['method' => 'cash', 'amount' => 900],
                ['method' => 'mobile_money', 'amount' => 600],
            ],
        ]);

        $this->assertCount(2, $sale->payments);
        $this->assertSame('1500.0000', Bc::num($sale->total));
        $this->assertSame(Bc::sub($sodaBefore, '1'), $ledger->onHand($soda->id, $branch->id));

        $this->expectException(\RuntimeException::class);
        StockMovement::query()->first()->update(['reason' => 'changed']);
    }

    public function test_sellers_record_sales_and_payments_directly(): void
    {
        [$slug, $email] = $this->workspace('directsales');
        $owner = User::query()->firstOrFail();
        $soda = Item::query()->where('sku', 'SOD-001')->sole();
        $branch = Branch::query()->firstOrFail();
        $this->login($slug, $email);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))];
        $this->get('/w/'.$slug.'/pos', $headers)->assertOk()->assertJsonMissingPath('props.till');
        $payload = [
            'user_id' => (string) Str::uuid(),
            'idempotency_key' => 'direct-sale',
            'lines' => [['item_id' => $soda->id, 'qty' => '1']],
            'payments' => [['method' => 'cash', 'amount' => '900'], ['method' => 'mobile_money', 'amount' => '600']],
        ];

        $this->post('/w/'.$slug.'/pos/sales', $payload)->assertSessionHasNoErrors()->assertRedirect();

        $sale = Sale::query()->sole();
        $this->assertSame($owner->id, $sale->user_id);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'completed', 'total' => '1500'], 'tenant');
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'method' => 'cash', 'amount' => '900'], 'tenant');
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'method' => 'mobile_money', 'amount' => '600'], 'tenant');
        $this->assertDatabaseHas('receipts', ['sale_id' => $sale->id], 'tenant');
        $this->assertSame('47.0000', app(StockLedger::class)->onHand($soda->id, $branch->id));
        $this->get('/w/'.$slug.'/pos/sales/'.$sale->id, $headers)->assertOk()->assertJsonPath('props.sale.seller.name', $owner->name);
        $this->get('/w/'.$slug.'/pos/sales/'.$sale->id.'/receipt', $headers)->assertOk()->assertJsonPath('props.sale.seller.id', $owner->id);
        $this->get('/w/'.$slug.'/dashboard', $headers)->assertOk()->assertJsonPath('props.metrics.sellers', 1)->assertJsonMissingPath('props.metrics.open_tills');

        $this->post('/w/'.$slug.'/pos/sales', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertDatabaseCount('sale_payments', 2, 'tenant');

        $payload['idempotency_key'] = 'unpaid-sale';
        $payload['payments'] = [['method' => 'cash', 'amount' => '1']];
        $this->post('/w/'.$slug.'/pos/sales', $payload)->assertSessionHasErrors(['payments' => 'Payments do not cover the sale total.']);
        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertSame('47.0000', app(StockLedger::class)->onHand($soda->id, $branch->id));
    }

    public function test_removing_legacy_tills_preserves_sales_payments_and_sellers(): void
    {
        $this->workspace('legacy');
        $owner = User::query()->firstOrFail();
        Auth::setUser($owner);
        $soda = Item::query()->where('sku', 'SOD-001')->sole();
        $sale = app(SaleService::class)->complete([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'lines' => [['item_id' => $soda->id, 'qty' => '1']],
            'payments' => [['method' => 'cash', 'amount' => '1500']],
        ]);
        Schema::connection('tenant')->create('till_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::connection('tenant')->create('till_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::connection('tenant')->table('sales', function (Blueprint $table): void {
            $table->uuid('till_session_id')->nullable();
        });
        $sessionId = (string) Str::uuid();
        DB::connection('tenant')->table('till_sessions')->insert(['id' => $sessionId]);
        DB::connection('tenant')->table('till_movements')->insert(['id' => (string) Str::uuid()]);
        $sale->update(['till_session_id' => $sessionId]);
        DB::connection('tenant')->table('permissions')->insert([
            'key' => 'pos.till.open', 'module' => 'pos', 'resource' => 'tills', 'action' => 'create',
        ]);

        $migration = require database_path('migrations/2026_10_11_215223_remove_tills_from_existing_tenants.php');
        $migration->up();

        $this->assertFalse(Schema::connection('tenant')->hasTable('till_sessions'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('till_movements'));
        $this->assertFalse(Schema::connection('tenant')->hasColumn('sales', 'till_session_id'));
        $this->assertDatabaseMissing('permissions', ['key' => 'pos.till.open'], 'tenant');
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'user_id' => $owner->id, 'total' => '1500'], 'tenant');
        $this->assertDatabaseHas('sale_payments', ['sale_id' => $sale->id, 'amount' => '1500'], 'tenant');
        $this->assertDatabaseHas('receipts', ['sale_id' => $sale->id], 'tenant');
        $this->assertDatabaseHas('sale_lines', ['sale_id' => $sale->id, 'qty' => '1'], 'tenant');
    }

    public function test_api_token_does_not_cross_workspaces(): void
    {
        [$slug] = $this->workspace('apibox');
        $owner = User::query()->firstOrFail();
        $system = SystemUser::query()->create(['name' => 'Sales sync', 'purpose' => 'test', 'owner_user_id' => $owner->id]);
        $plain = 'mk_live_testtoken';
        ApiToken::query()->create([
            'system_user_id' => $system->id,
            'name' => 'Test',
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => 'mk_live_test',
            'scopes' => ['*'],
        ]);
        ApiTokenTenant::query()->create([
            'token_hash' => hash('sha256', $plain),
            'tenant_id' => Tenant::query()->where('slug', $slug)->value('id'),
        ]);

        $this->getJson('/api/v1/items')->assertUnauthorized();
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer '.$plain])->assertOk();
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer mk_live_other'])->assertUnauthorized();
    }

    public function test_reports_render_with_empty_results_and_support_export_and_subscription(): void
    {
        [$slug, $email] = $this->workspace('reports');
        $catalog = app(ReportRegistry::class)->catalog();
        $this->login($slug, $email);

        foreach ($catalog as $report) {
            $response = $this->get('/w/'.$slug.'/reports/'.$report['key'].'?from=2099-01-01&to=2099-01-31', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
            ]);

            $response->assertOk();
            $response->assertJsonPath('component', 'Reports/Show');
            $response->assertJsonPath('props.report.key', $report['key']);
            $response->assertJsonPath('props.filters.from', '2099-01-01');
            $this->assertNotEmpty($response->json('props.result.columns'), $report['key']);
            if ($report['key'] === 'sales.items') {
                $response->assertJsonPath('props.result.rows', []);
                $response->assertJsonPath('props.result.columns', ['label', 'qty', 'gross']);
            }
        }

        $this->get('/w/'.$slug.'/reports/sales.items/export?from=2099-01-01&to=2099-01-31')
            ->assertOk()
            ->assertDownload('sales.items.csv')
            ->assertStreamedContent("label,qty,gross\n");
        $this->post('/w/'.$slug.'/reports/sales.items/subscribe', ['frequency' => 'weekly', 'name' => 'Weekly items'])
            ->assertRedirect();
        $this->assertDatabaseHas('report_subscriptions', ['report_key' => 'sales.items', 'frequency' => 'weekly'], 'tenant');
        $this->assertDatabaseHas('report_views', ['report_key' => 'sales.items', 'name' => 'Weekly items'], 'tenant');

        $this->get('/w/'.$slug.'/reports/unknown.report')->assertNotFound();
        TenantFeature::query()->where('feature_key', 'production.multi_stage')->update(['value' => false]);
        $this->get('/w/'.$slug.'/reports/production.stages')->assertNotFound();
    }

    public function test_production_lines_supply_the_sales_point_and_traded_goods_stay_in_stock_fulfillment(): void
    {
        [$slug, $email] = $this->workspace('lineflow');
        $branch = Branch::query()->firstOrFail();
        $recipe = Recipe::query()->firstOrFail();
        $line = ProductionLine::query()->firstOrFail();
        $bread = Item::query()->where('sku', 'BRD-001')->sole();
        $soda = Item::query()->where('sku', 'SOD-001')->sole();
        $soda->update(['make_mode' => 'make_to_order']);
        $ledger = app(StockLedger::class);
        $before = $ledger->onHand($bread->id, $branch->id);
        $this->login($slug, $email);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))];
        $base = '/w/'.$slug.'/production';
        $this->get($base.'/lines', $headers)->assertOk()->assertJsonPath('component', 'Production/Lines');
        $this->post($base.'/orders', ['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'planned_qty' => 20])->assertRedirect();
        $order = ProductionOrder::query()->sole();
        $this->assertSame($line->id, $order->production_line_id);
        $this->get($base.'/orders/'.$order->id, $headers)->assertOk()->assertJsonPath('props.order.production_line.id', $line->id);
        $this->post($base.'/orders/'.$order->id.'/start')->assertRedirect();
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->post($base.'/orders/'.$order->id.'/complete', ['good_qty' => 18, 'waste_qty' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Bc::add($before, '18'), $ledger->onHand($bread->id, $branch->id));
        $this->post($base.'/orders/'.$order->id.'/complete', ['good_qty' => 18])->assertSessionHasNoErrors();
        $this->assertSame(Bc::add($before, '18'), $ledger->onHand($bread->id, $branch->id));
        $sodaBefore = $ledger->onHand($soda->id, $branch->id);
        $sale = app(SaleService::class)->complete([
            'branch_id' => $branch->id, 'idempotency_key' => 'mixed-production-resale',
            'lines' => [['item_id' => $bread->id, 'qty' => 1, 'unit_price' => 2000], ['item_id' => $soda->id, 'qty' => 1, 'unit_price' => 1500]],
            'payments' => [['method' => 'cash', 'amount' => 3500]],
        ]);
        $this->assertSame('3500.0000', Bc::num($sale->total));
        $this->assertSame(Bc::add($before, '17'), $ledger->onHand($bread->id, $branch->id));
        $this->assertSame(Bc::sub($sodaBefore, '1'), $ledger->onHand($soda->id, $branch->id));
        $this->assertSame(1, ProductionOrder::query()->count());
        $order->update(['production_line_id' => null]);
        $migration = require database_path('tenant/2026_10_11_220914_add_production_lines_to_tenant_schema.php');
        $migration->addToSchema(DB::connection('tenant')->selectOne('SELECT current_schema() AS name')->name);
        $this->assertSame($line->id, $order->fresh()->production_line_id);
        $this->assertSame(Bc::add($before, '17'), $ledger->onHand($bread->id, $branch->id));
        $this->get('/w/'.$slug.'/pos', $headers)->assertOk()->assertJsonStructure(['props' => ['items' => [['available_qty']]]]);
    }

    public function test_line_setup_rejects_inactive_lines_and_accepts_bills_for_stocked_goods(): void
    {
        [$slug, $email] = $this->workspace('linevalidation');
        $this->login($slug, $email);
        $recipe = Recipe::query()->firstOrFail();
        $line = ProductionLine::query()->firstOrFail();
        $base = '/w/'.$slug.'/production';
        $this->post($base.'/lines', ['name' => 'Second line', 'code' => 'SECOND', 'recipe_id' => $recipe->id, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('production_lines', ['code' => 'SECOND', 'branch_id' => $line->branch_id], 'tenant');
        $this->post($base.'/lines', ['name' => 'Duplicate', 'code' => 'SECOND', 'recipe_id' => $recipe->id, 'status' => 'active'])->assertSessionHasErrors('code');
        $this->post($base.'/lines', ['name' => 'Invalid', 'code' => 'BAD', 'recipe_id' => 'invalid', 'status' => 'active'])->assertSessionHasErrors('recipe_id');
        $this->put($base.'/lines/'.$line->id, ['name' => $line->name, 'code' => $line->code, 'recipe_id' => $recipe->id, 'status' => 'inactive'])->assertSessionHasNoErrors();
        $this->post($base.'/orders', ['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'planned_qty' => 10])->assertSessionHasErrors('production_line_id');
        $second = ProductionLine::query()->where('code', 'SECOND')->sole();
        $this->post($base.'/orders', ['production_line_id' => $second->id, 'recipe_id' => $recipe->id, 'planned_qty' => 100000])->assertSessionHasNoErrors();
        $large = ProductionOrder::query()->sole();
        $this->post($base.'/orders/'.$large->id.'/start')->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $large->fresh()->status);
        $second->update(['branch_id' => (string) Str::uuid()]);
        $this->post($base.'/orders', ['production_line_id' => $second->id, 'recipe_id' => $recipe->id, 'planned_qty' => 10])->assertSessionHasErrors('production_line_id');
        $soda = Item::query()->where('sku', 'SOD-001')->sole();
        $raw = Item::query()->where('type', 'raw_material')->firstOrFail();
        $this->post($base.'/recipes', ['name' => 'Resale recipe', 'item_id' => $soda->id, 'yield_qty' => 1, 'lines' => [['item_id' => $raw->id, 'qty' => 1]]])->assertSessionHasNoErrors();
        $this->post($base.'/orders', ['production_line_id' => $line->id, 'recipe_id' => $recipe->id, 'planned_qty' => 0])->assertSessionHasErrors('planned_qty');
    }

    public function test_language_switch_shares_swahili_translations_and_keeps_business_data_unchanged(): void
    {
        [$slug, $email] = $this->workspace('swahili');
        $line = ProductionLine::query()->firstOrFail();
        $owner = User::query()->firstOrFail();
        $this->login($slug, $email);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))];
        $base = '/w/'.$slug;
        $this->post($base.'/locale', ['locale' => 'sw'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sw', $owner->fresh()->locale);
        $this->post($base.'/production/lines', [])->assertSessionHasErrors(['name' => 'Tafadhali jaza Jina.']);
        $response = $this->get($base.'/production/lines', $headers)->assertOk()->assertJsonPath('props.locale', 'sw');
        $translations = $response->json('props.translations');
        $this->assertSame('Mistari ya uzalishaji', $translations['Production lines']);
        $this->assertSame('Panga kundi la uzalishaji', $translations['Plan batch']);
        $this->assertSame('Makundi ya uzalishaji', $translations['nav.production']);
        $this->assertSame('Hakuna data ya kuonyesha', $translations['No data to show']);
        $this->assertSame('Imekamilika', $translations['status.completed']);
        $this->assertSame($line->name, $response->json('props.lines.0.name'));
        $this->post($base.'/locale', ['locale' => 'unsupported'])->assertSessionHasErrors('locale');
        $this->post($base.'/locale', ['locale' => 'en'])->assertRedirect();
        $response = $this->get($base.'/production/lines', $headers)->assertOk()->assertJsonPath('props.locale', 'en');
        $this->assertSame('Production lines', $response->json('props.translations')['Production lines']);
        $this->assertSame($line->name, $response->json('props.lines.0.name'));
    }

    private function login(string $slug, string $email): void
    {
        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]);
        $response->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function workspace(string $slug): array
    {
        $email = $slug.'@example.test';
        app(TenantProvisioner::class)->provision([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'domain' => $slug.'.localhost',
            'owner_name' => 'Owner',
            'owner_email' => $email,
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
            'sample' => true,
        ]);

        return [$slug, $email];
    }
}
