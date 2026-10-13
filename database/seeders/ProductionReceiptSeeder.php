<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Item;
use App\Services\ProductionService;
use App\Services\StockLedger;
use App\Tenancy\TenancyManager;
use Illuminate\Database\Seeder;

class ProductionReceiptSeeder extends Seeder
{
    public function run(): void
    {
        if (! app(TenancyManager::class)->initialized()) {
            throw new \RuntimeException('Initialize a demo tenant before seeding direct receipts.');
        }
        $branch = Branch::query()->where('status', 'active')->first();
        $item = Item::query()->where('type', 'trading_good')->where('track_stock', true)->whereNotNull('base_unit_id')->first();
        if (! $branch || ! $item) {
            return;
        }
        app(ProductionService::class)->receive([
            'branch_id' => $branch->id, 'item_id' => $item->id, 'qty' => 5,
            'unit_cost' => app(StockLedger::class)->averageCost($item->id, $branch->id),
            'request_id' => 'dd9c2893-6e2b-4ac0-b586-dd32555935c6',
            'notes' => 'Demo direct receipt of purchased goods',
        ]);
    }
}
