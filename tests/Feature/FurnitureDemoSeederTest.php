<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Tenant;
use App\Reports\ReportRegistry;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Database\Seeders\FurnitureDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurnitureDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_furniture_demo CASCADE');
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_other CASCADE');
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_seeds_historical_furniture_sales_and_reports_once_without_changing_another_workspace(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(18, 0));
        $other = app(TenantProvisioner::class)->provision([
            'name' => 'Other', 'slug' => 'other', 'industry_key' => 'bakery',
            'owner_name' => 'Other Owner', 'owner_email' => 'other@example.test',
            'owner_password' => 'Password123!', 'sample' => true,
        ]);

        $this->seed(FurnitureDemoSeeder::class);
        $this->seed(FurnitureDemoSeeder::class);

        $this->assertSame($other->id, app(TenancyManager::class)->id());
        $this->assertDatabaseCount('sales', 0, 'tenant');
        $this->assertSame('2026-10-12 18:00:00', now()->format('Y-m-d H:i:s'));
        $tenant = Tenant::query()->where('slug', 'furniture-demo')->sole();
        $this->assertSame('furniture', $tenant->industry_key);
        app(TenancyManager::class)->initialize($tenant);
        $this->assertDatabaseCount('sales', 156, 'tenant');
        $this->assertDatabaseCount('sale_payments', 156, 'tenant');
        $this->assertDatabaseCount('receipts', 156, 'tenant');
        $this->assertDatabaseCount('production_runs', 78, 'tenant');
        $this->assertDatabaseCount('production_orders', 81, 'tenant');
        $this->assertDatabaseCount('customers', 6, 'tenant');
        $this->assertDatabaseCount('suppliers', 3, 'tenant');
        $this->assertSame(Sale::query()->sum('total'), DB::connection('tenant')->table('sale_payments')->sum('amount'));
        $this->assertDatabaseHas('sales', ['created_at' => '2026-04-20 11:00:00'], 'tenant');
        $this->assertDatabaseHas('sales', ['created_at' => '2026-10-12 14:20:00'], 'tenant');
        $this->assertSame(0, DB::connection('tenant')->table('stock_balances')->where('qty', '<', 0)->count());
        $reports = app(ReportRegistry::class);
        $filters = ['from' => '2026-04-20', 'to' => '2026-10-12'];
        $this->assertSame(156, $reports->run('sales.summary', $filters)['rows'][0]->orders);
        $this->assertCount(3, $reports->run('sales.items', $filters)['rows']);
        $this->assertCount(6, $reports->run('sales.customers', $filters)['rows']);
        foreach (['profit.margin', 'production.summary', 'production.yield', 'inventory.valuation', 'cash.methods', 'tax.summary'] as $report) {
            $this->assertNotEmpty($reports->run($report, $filters)['rows'], $report);
        }
        $this->travelBack();
    }
}
