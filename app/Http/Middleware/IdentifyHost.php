<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('makerly.host')) {
            app()->instance('makerly.host', 'central');
        }

        return $next($request);
    }
}
