<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\WorkspaceApproval;
use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancy
{
    public function __construct(private TenancyManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('workspace');
        $workspace = is_string($slug) ? Workspace::query()->where('slug', $slug)->first() : null;
        if (! $workspace || ! $workspace->tenant) {
            abort(404, 'Unknown workspace.');
        }

        $tenant = $workspace->tenant;
        $blocked = WorkspaceApproval::deny($request, $tenant, $workspace);
        if ($blocked) {
            return $blocked;
        }

        $this->tenancy->initialize($tenant);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
