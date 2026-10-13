<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Sale;
use App\Services\StockLedger;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Database\Seeders\JuiceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JuiceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_makes CASCADE');
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_other CASCADE');
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_seeds_balanced_juice_sales_without_duplicates_or_changes_to_another_tenant(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $provisioner = app(TenantProvisioner::class);
        $juiceTenant = $provisioner->provision([
            'name' => 'Makers', 'slug' => 'makes', 'industry_key' => 'juice',
            'owner_name' => 'Juice Owner', 'owner_email' => 'juice@example.test',
            'owner_password' => 'Password123!', 'sample' => true,
        ]);
        $otherTenant = $provisioner->provision([
            'name' => 'Other', 'slug' => 'other', 'industry_key' => 'bakery',
            'owner_name' => 'Other Owner', 'owner_email' => 'other@example.test',
            'owner_password' => 'Password123!', 'sample' => true,
        ]);

        $this->seed(JuiceDemoSeeder::class);
        $this->seed(JuiceDemoSeeder::class);

        $this->assertSame($otherTenant->id, app(TenancyManager::class)->id());
        $this->assertDatabaseCount('sales', 0, 'tenant');
        app(TenancyManager::class)->initialize($juiceTenant);
        $this->assertDatabaseCount('sales', 56, 'tenant');
        $this->assertDatabaseCount('sale_payments', 56, 'tenant');
        $this->assertDatabaseCount('receipts', 56, 'tenant');
        $this->assertDatabaseCount('production_runs', 14, 'tenant');
        $this->assertDatabaseCount('production_orders', 15, 'tenant');
        $this->assertDatabaseCount('customers', 4, 'tenant');
        $this->assertDatabaseCount('suppliers', 2, 'tenant');
        $this->assertSame('384000.0000', Sale::query()->sum('total'));
        $this->assertSame('384000.0000', DB::connection('tenant')->table('sale_payments')->sum('amount'));
        $this->assertDatabaseHas('sales', ['created_at' => '2026-09-29 09:15:00'], 'tenant');
        $juice = Item::query()->where('sku', 'JCE-001')->sole();
        $branchId = DB::connection('tenant')->table('branches')->value('id');
        $this->assertSame('228.0000', app(StockLedger::class)->onHand($juice->id, $branchId));

        $this->travelBack();
    }
}
