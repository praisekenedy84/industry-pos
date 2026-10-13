<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\Workspace;
use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InitializeWorkspaceFromPath
{
    public function __construct(private TenancyManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! preg_match('#^w/([a-z0-9-]{3,32})(?:/|$)#', $request->path(), $matches)) {
            return $next($request);
        }

        $slug = $matches[1];
        $workspace = Workspace::query()->where('slug', $slug)->first();
        if (! $workspace) {
            abort(404);
        }
        $tenant = Tenant::query()->find($workspace->tenant_id);
        if (! $tenant) {
            abort(404);
        }

        URL::defaults(['workspace' => $slug]);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('tenant', $tenant);
        app()->instance('makerly.base', '/w/'.$slug);
        app()->instance('makerly.host', 'tenant');

        if ($tenant->isSuspended() || $workspace->status !== 'active') {
            return Inertia::render('Errors/Suspended', [
                'tenant' => $workspace->display_name,
                'reason' => $tenant->suspended_reason ?: 'This workspace is suspended.',
            ])->toResponse($request)->setStatusCode(403);
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
