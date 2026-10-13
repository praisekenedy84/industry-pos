<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileStock extends Command
{
    protected $signature = 'makerly:reconcile';

    protected $description = 'Compare stock balances with the sum of signed ledger quantities';

    public function handle(TenancyManager $tenancy): int
    {
        $mismatches = 0;
        foreach (Tenant::query()->usable()->get() as $tenant) {
            $tenancy->initialize($tenant);
            $rows = DB::connection('tenant')->select(<<<'SQL'
                SELECT b.item_id, b.branch_id, b.qty AS balance_qty, COALESCE(SUM(m.signed_qty), 0) AS movement_qty
                FROM stock_balances b
                LEFT JOIN stock_movements m ON m.item_id = b.item_id AND m.branch_id = b.branch_id
                WHERE b.lot_id IS NULL
                GROUP BY b.item_id, b.branch_id, b.qty
                HAVING b.qty <> COALESCE(SUM(m.signed_qty), 0)
            SQL);
            foreach ($rows as $row) {
                $mismatches++;
                $this->warn($tenant->slug.' item '.$row->item_id.' balance '.$row->balance_qty.' ledger '.$row->movement_qty);
            }
            $tenancy->end();
        }
        $this->info($mismatches.' mismatches.');

        return $mismatches > 0 ? self::FAILURE : self::SUCCESS;
    }
}
