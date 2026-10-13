<?php

namespace App\Console\Commands;

use App\Models\ProductionOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AccessManager;
use App\Services\ProductionService;
use App\Tenancy\TenancyManager;
use Illuminate\Console\Command;

class MaintainProduction extends Command
{
    protected $signature = 'makerly:production-maintain {--tenant= : Workspace slug}';

    protected $description = 'Repair unposted finished runs and remind assignees about production needing an update';

    public function handle(TenancyManager $tenancy, ProductionService $production): int
    {
        $previous = $tenancy->tenant();
        $tenants = Tenant::query()->where('status', 'active')->when($this->option('tenant'), fn ($query) => $query->where('slug', $this->option('tenant')))->get();
        try {
            foreach ($tenants as $tenant) {
                $tenancy->initialize($tenant);
                $posted = $production->backfillFinished();
                ProductionOrder::query()->where(fn ($query) => $query->where('status', 'in_progress')->orWhere(fn ($legacy) => $legacy->where('workflow_version', 1)->where('status', 'completed')))->where('updated_at', '<=', now()->subDay())
                    ->chunkById(100, function ($orders) use ($tenant): void {
                        foreach ($orders as $order) {
                            $user = User::query()->whereIn('id', array_filter([$order->assignee_id, $order->created_by]))->where('status', 'active')->first();
                            if (! $user || ! app(AccessManager::class)->allows($user, 'production.orders.view', $order)) {
                                $user = User::query()->where('status', 'active')
                                    ->whereHas('branchRoles', fn ($query) => $query->where('branch_id', $order->branch_id))->get()
                                    ->first(fn (User $candidate) => app(AccessManager::class)->allows($candidate, 'production.orders.view', $order));
                            }
                            if (! $user) {
                                continue;
                            }
                            $exists = UserNotification::query()->where('user_id', $user->id)->where('type', 'production_follow_up')
                                ->where('data->order_id', $order->id)->where('data->status', $order->status)->whereDate('created_at', today())->exists();
                            if (! $exists) {
                                UserNotification::query()->create([
                                    'user_id' => $user->id, 'type' => 'production_follow_up', 'title' => 'Production update needed',
                                    'body' => $order->number.' / '.$order->status,
                                    'created_at' => now(),
                                    'data' => ['order_id' => $order->id, 'status' => $order->status, 'url' => '/w/'.$tenant->slug.'/production/batches/'.$order->id],
                                ]);
                            }
                        }
                    });
                $this->info($tenant->slug.': '.$posted.' finished runs checked.');
            }
        } finally {
            $tenancy->end();
            if ($previous) {
                $tenancy->initialize($previous);
            }
        }

        return self::SUCCESS;
    }
}
