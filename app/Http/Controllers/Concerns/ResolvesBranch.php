<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;

trait ResolvesBranch
{
    protected function branchId(): string
    {
        $user = auth()->user();
        $assigned = $user->branchRoles()->pluck('branch_id');
        $current = session('branch_id');
        if (! $current || ! $assigned->contains($current)) {
            $current = $assigned->first();
            session(['branch_id' => $current]);
            $device = request()->attributes->get('device');
            $workspace = request()->attributes->get('workspace');
            if ($device && $workspace && $current) {
                app(\App\Auth\WorkspaceSessionManager::class)->merge($device, $workspace->tenant_id, ['branch_id' => $current]);
            }
        }
        return (string) $current;
    }

    protected function assertBranchCanTransact(?string $branchId = null): void
    {
        $branch = Branch::query()->findOrFail($branchId ?: $this->branchId());
        if ($branch->status === 'suspended') {
            abort(403, 'This branch is suspended and cannot transact.');
        }
    }
}
