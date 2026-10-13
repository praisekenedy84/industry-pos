<?php

namespace App\Http\Middleware;

use App\Models\ApiTokenTenant;
use App\Models\Tenant;
use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiTenant
{
    public function __construct(private TenancyManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (! $plain) {
            abort(401, 'Missing bearer token.');
        }
        $row = ApiTokenTenant::query()->where('token_hash', hash('sha256', $plain))->first();
        if (! $row) {
            abort(401, 'Invalid token.');
        }
        $tenant = Tenant::query()->find($row->tenant_id);
        if (! $tenant || $tenant->isSuspended()) {
            abort(403, 'This workspace is not available.');
        }
        $this->tenancy->initialize($tenant);
        app()->instance('makerly.host', 'tenant');

        return $next($request);
    }
}
