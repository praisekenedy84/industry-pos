<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CentralAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    public function tenant(string $action, ?string $type = null, ?string $id = null, mixed $before = null, mixed $after = null): void
    {
        $impersonator = session('impersonator_id');
        AuditLog::query()->create([
            'actor_type' => $impersonator ? 'platform_admin' : (Auth::check() ? 'user' : 'system'),
            'actor_id' => $impersonator ?: Auth::id(),
            'on_behalf_of' => $impersonator ? Auth::id() : null,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    public function central(string $action, ?string $type = null, ?string $id = null, mixed $before = null, mixed $after = null): void
    {
        CentralAuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'actor_id' => Auth::guard('platform')->id(),
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'before' => $before,
            'after' => $after,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
