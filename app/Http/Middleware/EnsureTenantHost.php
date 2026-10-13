<?php

namespace App\Http\Middleware;

use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(TenancyManager::class)->initialized()) {
            return $next($request);
        }

        abort(404);
    }
}
