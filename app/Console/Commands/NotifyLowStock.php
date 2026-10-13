<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\UserNotification;
use App\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyLowStock extends Command
{
    protected $signature = 'makerly:low-stock';

    protected $description = 'Notify workspace owners when stock is at or below reorder level';

    public function handle(TenancyManager $tenancy): int
    {
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $tenancy->initialize($tenant);
            $rows = DB::connection('tenant')->table('items')
                ->join('stock_balances', function ($join) {
                    $join->on('stock_balances.item_id', '=', 'items.id')->whereNull('stock_balances.lot_id');
                })
                ->whereColumn('stock_balances.qty', '<=', 'items.reorder_level')
                ->where('items.reorder_level', '>', 0)
                ->get(['items.name', 'stock_balances.qty', 'items.reorder_level']);
            $ownerId = DB::connection('tenant')->table('users')->where('status', 'active')->orderBy('created_at')->value('id');
            foreach ($rows as $row) {
                if (! $ownerId) {
                    continue;
                }
                UserNotification::query()->create([
                    'user_id' => $ownerId,
                    'type' => 'low_stock',
                    'title' => 'Low stock',
                    'body' => $row->name.' is at '.$row->qty.' (reorder '.$row->reorder_level.').',
                ]);
            }
            $tenancy->end();
        }

        return self::SUCCESS;
    }
}
