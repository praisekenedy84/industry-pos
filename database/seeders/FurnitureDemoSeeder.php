<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ProductionLine;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FurnitureDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(TenancyManager::class);
        $previousTenant = $tenancy->tenant();
        $previousUser = Auth::user();
        $previousTime = Carbon::getTestNow();
        $today = now()->startOfDay();
        try {
            $tenant = Tenant::query()->where('slug', 'furniture-demo')->first();
            if ($tenant && $tenant->industry_key !== 'furniture') {
                throw new \RuntimeException('The furniture-demo slug belongs to another industry.');
            }
            if (! $tenant) {
                Carbon::setTestNow($today->copy()->subWeeks(25)->setTime(7, 0));
                $tenant = app(TenantProvisioner::class)->provision([
                    'name' => 'Mbao Furniture Demo', 'slug' => 'furniture-demo', 'industry_key' => 'furniture',
                    'owner_name' => 'Furniture Demo Owner', 'owner_email' => 'owner@furniture-demo.test',
                    'owner_password' => 'Furniture#2026', 'branch_name' => 'Furniture Workshop',
                    'branch_code' => 'FUR', 'sample' => true,
                ]);
            }
            $tenancy->initialize($tenant);
            DB::connection('tenant')->transaction(function () use ($tenant, $today): void {
                $branch = Branch::query()->where('status', 'active')->orderBy('id')->lockForUpdate()->firstOrFail();
                if (Setting::getValue('furniture_demo_seeded', false)) {
                    $this->command?->info('Furniture demo already exists; nothing added.');

                    return;
                }
                $owner = User::query()->where('email', $tenant->owner_email)->firstOrFail();
                Auth::setUser($owner);
                Carbon::setTestNow($today->copy()->subWeeks(25)->setTime(7, 30));
                $timber = Item::query()->where('sku', 'WOD-001')->sole();
                $chair = Item::query()->where('sku', 'CHR-001')->sole();
                $customers = collect(['Sunrise Hotel', 'Greenfield School', 'Harbour Offices', 'City Home Store', 'Amani Interiors', 'Walk-in Customer'])
                    ->map(fn (string $name) => Customer::query()->firstOrCreate(['name' => $name], ['group_name' => 'Furniture demo customers', 'created_by' => $owner->id]));
                foreach (['Highland Timber Supply', 'Workshop Hardware Ltd', 'Coastal Finishes'] as $name) {
                    Supplier::query()->firstOrCreate(['name' => $name], ['payment_terms' => '30 days', 'notes' => 'Furniture demo supplier.']);
                }
                app(StockLedger::class)->post([
                    'item_id' => $timber->id, 'branch_id' => $branch->id, 'type' => 'opening_balance',
                    'quantity' => 4000, 'signed_qty' => 4000, 'unit_cost' => 15000,
                    'user_id' => $owner->id, 'reason' => 'Demo timber for six months of production',
                ]);
                $products = [[$chair, Recipe::query()->where('item_id', $chair->id)->sole(), 10000, 2000]];
                foreach ([['TBL-001', 'Dining table', 220000, 6, 40000, 10000], ['CAB-001', 'Storage cabinet', 350000, 8, 60000, 15000]] as [$sku, $name, $price, $wood, $labor, $overhead]) {
                    $item = Item::query()->firstOrCreate(['sku' => $sku], [
                        'name' => $name, 'type' => 'finished_good', 'category_id' => $chair->category_id,
                        'base_unit_id' => $chair->base_unit_id, 'purchase_unit_id' => $chair->base_unit_id,
                        'sales_unit_id' => $chair->base_unit_id, 'tax_rate_id' => $chair->tax_rate_id,
                        'track_stock' => true, 'make_mode' => 'make_to_stock', 'default_price' => $price, 'created_by' => $owner->id,
                    ]);
                    $recipe = Recipe::query()->create(['item_id' => $item->id, 'name' => $name.' assembly plan', 'current_version' => 1]);
                    $version = RecipeVersion::query()->create(['recipe_id' => $recipe->id, 'item_id' => $item->id, 'version' => 1, 'yield_qty' => 1, 'created_by' => $owner->id]);
                    RecipeLine::query()->create(['recipe_version_id' => $version->id, 'item_id' => $timber->id, 'qty' => $wood, 'unit_id' => $timber->base_unit_id]);
                    foreach (['labor' => $labor, 'overhead' => $overhead] as $type => $amount) {
                        RecipeCostLine::query()->create(['recipe_version_id' => $version->id, 'type' => $type, 'name' => ucfirst($type), 'amount' => $amount]);
                    }
                    ProductionLine::query()->create(['branch_id' => $branch->id, 'recipe_id' => $recipe->id, 'name' => $name.' assembly', 'code' => $sku, 'status' => 'active']);
                    $products[] = [$item, $recipe, $labor, $overhead];
                }
                $production = app(ProductionService::class);
                $sales = app(SaleService::class);
                for ($week = 0; $week < 26; $week++) {
                    $date = $today->copy()->subWeeks(25 - $week);
                    foreach ($products as $index => [$item, $recipe, $labor, $overhead]) {
                        Carbon::setTestNow($date->copy()->setTime(8, $index * 10));
                        $actual = 6 + $week % 4;
                        $variance = $week % 5 === 0 ? 1 : 0;
                        $order = $production->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => $actual + $variance, 'assignee_id' => $owner->id, 'notes' => 'Demo weekly furniture production']);
                        $production->complete($order, [
                            'good_qty' => $actual, 'waste_qty' => $variance, 'waste_reason' => 'Finishing quality reject',
                            'labor_cost' => $labor * $actual, 'overhead_cost' => $overhead * $actual,
                            'lot_code' => 'FUR-'.$date->format('Ymd').'-'.$item->sku,
                        ]);
                        for ($saleIndex = 0; $saleIndex < 2; $saleIndex++) {
                            Carbon::setTestNow($date->copy()->setTime(11 + $saleIndex * 3, $index * 10));
                            $quantity = 1 + ($week + $index + $saleIndex) % 3;
                            $price = $sales->priceFor($item, null, null, $branch->id);
                            $method = ['cash', 'mobile_money', 'card'][($week + $index + $saleIndex) % 3];
                            $sales->complete([
                                'branch_id' => $branch->id, 'customer_id' => $customers[($week + $index + $saleIndex) % 6]->id,
                                'idempotency_key' => 'furniture-demo-'.$week.'-'.$index.'-'.$saleIndex,
                                'notes' => 'Demo furniture sale', 'lines' => [['item_id' => $item->id, 'qty' => $quantity]],
                                'payments' => [['method' => $method, 'amount' => Bc::mul($price, $quantity), 'reference' => 'FUR-DEMO-'.$week.'-'.$index.'-'.$saleIndex]],
                            ]);
                        }
                    }
                }
                Carbon::setTestNow($today->copy()->setTime(16, 0));
                foreach ($products as $index => [$item, $recipe]) {
                    $order = $production->plan(['recipe_id' => $recipe->id, 'branch_id' => $branch->id, 'planned_qty' => 6, 'due_date' => $today->copy()->addDays(3)->toDateString(), 'assignee_id' => $owner->id, 'notes' => 'Demo upcoming furniture job']);
                    if ($index > 0) {
                        $production->start($order);
                    }
                    if ($index === 2) {
                        $production->hold($order, 'Waiting for cabinet fittings');
                    }
                }
                Setting::putValue('furniture_demo_seeded', true);
                $this->command?->info('Furniture demo: 156 sales, 78 completed jobs, 3 open jobs, 6 customers and 3 suppliers across 26 weeks.');
            });
        } finally {
            Carbon::setTestNow($previousTime);
            Auth::forgetUser();
            if ($previousTenant) {
                $tenancy->initialize($previousTenant);
            } else {
                $tenancy->end();
            }
            if ($previousUser) {
                Auth::setUser($previousUser);
            }
        }
    }
}
