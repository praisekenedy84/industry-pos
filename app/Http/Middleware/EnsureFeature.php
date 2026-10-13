<?php

namespace App\Http\Middleware;

use App\Services\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeature
{
    public function __construct(private FeatureGate $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $branchId = $request->session()->get('branch_id');
        if ($this->features->active($feature, $branchId)) {
            return $next($request);
        }

        return Inertia::render('Errors/Http', [
            'status' => 404,
            'message' => __('errors.module_disabled'),
        ])->toResponse($request)->setStatusCode(404);
    }
}
