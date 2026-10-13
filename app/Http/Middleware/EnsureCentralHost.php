<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->bound('makerly.host') && app('makerly.host') === 'central') {
            return $next($request);
        }

        abort(404);
    }
}
