<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Support\Bc;
use App\Tenancy\TenancyManager;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JuiceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'makes')->where('industry_key', 'juice')->sole();
        $tenancy = app(TenancyManager::class);
        $previousTenant = $tenancy->tenant();
        $previousUser = Auth::user();
        $previousTime = Carbon::getTestNow();
        $today = now()->startOfDay();
        $tenancy->initialize($tenant);

        try {
            DB::connection('tenant')->transaction(function () use ($tenant, $today): void {
                if (Setting::getValue('juice_demo_seeded', false)) {
                    $this->command?->info('Juice demo data already exists; nothing added.');

                    return;
                }

                $branch = Branch::query()->where('status', 'active')->firstOrFail();
                $owner = User::query()->where('email', $tenant->owner_email)->firstOrFail();
                Auth::setUser($owner);
                $mangoes = Item::query()->where('sku', 'MNG-001')->sole();
                $juice = Item::query()->where('sku', 'JCE-001')->sole();
                $recipe = Recipe::query()->where('item_id', $juice->id)->firstOrFail();
                $customers = collect(['Demo Sunrise Cafe', 'Demo Harbour Hotel', 'Demo Fresh Market', 'Demo Office Catering'])
                    ->map(fn (string $name) => Customer::query()->firstOrCreate(['name' => $name], [
                        'group_name' => 'Demo customers',
                        'notes' => 'Demo data for the Juice production workspace.',
                        'created_by' => $owner->id,
                    ]));
                foreach (['Demo Orchard Fruit Supply', 'Demo Coastal Packaging'] as $name) {
                    Supplier::query()->firstOrCreate(['name' => $name], [
                        'payment_terms' => '30 days',
                        'notes' => 'Demo supplier.',
                    ]);
                }

                Carbon::setTestNow($today->copy()->subDays(13)->setTime(7, 0));
                app(StockLedger::class)->post([
                    'item_id' => $mangoes->id,
                    'branch_id' => $branch->id,
                    'type' => 'opening_balance',
                    'quantity' => '250',
                    'signed_qty' => '250',
                    'unit_cost' => '1500',
                    'user_id' => $owner->id,
                    'reason' => 'Demo mango stock for two weeks of production',
                ]);

                $production = app(ProductionService::class);
                $sales = app(SaleService::class);
                $price = $sales->priceFor($juice, null, null, $branch->id);
                $taxRate = TaxRate::query()->find($juice->tax_rate_id)?->rate ?? 0;

                for ($day = 13; $day >= 0; $day--) {
                    $date = $today->copy()->subDays($day);
                    Carbon::setTestNow($date->copy()->setTime(8, 0));
                    $order = $production->plan([
                        'recipe_id' => $recipe->id,
                        'branch_id' => $branch->id,
                        'planned_qty' => '30',
                        'notes' => 'Demo daily mango juice batch',
                    ]);
                    $production->complete($order, [
                        'good_qty' => '30',
                        'lot_code' => 'DEMO-JUICE-'.$date->format('Ymd'),
                        'expiry_date' => $date->copy()->addDays(21)->toDateString(),
                    ]);
                    foreach (['cash', 'mobile_money', 'card', 'cash'] as $index => $method) {
                        Carbon::setTestNow($date->copy()->setTime(9 + $index * 2, 15));
                        $quantity = (string) (1 + ($day + $index) % 6);
                        $amount = Bc::mul($price, $quantity);
                        if (! Setting::getValue('prices_include_tax', true)) {
                            $amount = Bc::add($amount, Bc::mul($amount, Bc::div($taxRate, '100')));
                        }
                        $sales->complete([
                            'branch_id' => $branch->id,
                            'customer_id' => $customers[$index]->id,
                            'idempotency_key' => 'juice-demo-'.$date->format('Ymd').'-'.$index,
                            'notes' => 'Demo juice sale',
                            'lines' => [['item_id' => $juice->id, 'qty' => $quantity]],
                            'payments' => [[
                                'method' => $method,
                                'amount' => $amount,
                                'reference' => $method === 'cash' ? null : 'DEMO-'.$date->format('Ymd').'-'.$index,
                            ]],
                        ]);
                    }

                }
                Carbon::setTestNow($today->copy()->setTime(8, 0));
                $production->plan([
                    'recipe_id' => $recipe->id,
                    'branch_id' => $branch->id,
                    'planned_qty' => '30',
                    'due_date' => $today->copy()->addDay()->toDateString(),
                    'notes' => 'Demo next-day production plan',
                ]);
                Setting::putValue('juice_demo_seeded', true);
                $this->command?->info('Added 56 sales, 14 completed batches, 1 planned batch, 4 customers and 2 suppliers to Makers.');
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
