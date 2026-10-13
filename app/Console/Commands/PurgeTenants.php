<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\TenancyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeTenants extends Command
{
    protected $signature = 'makerly:purge-tenants';

    protected $description = 'Drop tenant schemas whose retention window has passed';

    public function handle(TenancyManager $tenancy): int
    {
        $tenants = Tenant::query()->where('status', 'pending_deletion')->where('retention_until', '<', now())->get();
        foreach ($tenants as $tenant) {
            if (! preg_match('/^[a-z][a-z0-9_]+$/', $tenant->schema_name)) {
                $this->error('Skipped unsafe schema '.$tenant->schema_name);
                continue;
            }
            $tenancy->end();
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$tenant->schema_name.' CASCADE');
            $tenant->update(['status' => 'deleted']);
            $this->info('Purged '.$tenant->slug);
        }

        return self::SUCCESS;
    }
}
