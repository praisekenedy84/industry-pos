<?php

namespace App\Console\Commands;

use App\Models\TenantFeature;
use Illuminate\Console\Command;

class ExpireFeatureOverrides extends Command
{
    protected $signature = 'makerly:flags-expire';

    protected $description = 'Drop feature overrides whose trial window has ended';

    public function handle(): int
    {
        $count = TenantFeature::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $this->info($count.' expired feature overrides removed.');

        return self::SUCCESS;
    }
}
