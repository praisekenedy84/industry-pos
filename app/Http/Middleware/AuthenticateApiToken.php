<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(TenancyManager::class)->initialized()) {
            abort(404);
        }

        $plain = $request->bearerToken();
        if (! $plain) {
            abort(401, 'Missing bearer token.');
        }

        $token = ApiToken::query()->where('token_hash', hash('sha256', $plain))->first();
        if (! $token || $token->revoked_at || ($token->expires_at && $token->expires_at->isPast())) {
            abort(401, 'Invalid token.');
        }
        $allow = $token->ip_allowlist ?: [];
        if ($allow && ! in_array($request->ip(), $allow, true)) {
            abort(403, 'IP is not allowed for this token.');
        }

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $request->attributes->set('apiToken', $token);
        app(\App\Services\AuditLogger::class)->tenant('api.request', 'api_token', $token->id, null, [
            'path' => $request->path(),
            'system_user_id' => $token->system_user_id,
        ]);

        return $next($request);
    }
}
