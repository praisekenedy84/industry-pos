<?php

namespace App\Auth;

use App\Models\Identity;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Models\Workspace;
use App\Models\WorkspaceSession as WorkspaceSessionRow;
use App\Tenancy\TenancyManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkspaceSessionManager
{
    public function __construct(private SessionVault $vault, private TenancyManager $tenancy) {}

    public function current(array $device, string $tenantId): ?array
    {
        $payload = $this->vault->get($this->key($tenantId, $device['id']));
        if (! $payload || ! empty($payload['revoked_at'])) {
            return null;
        }
        if (($payload['absolute_expires_at'] ?? 0) < time()) {
            $this->endPayload($payload);

            return null;
        }

        return $payload;
    }

    public function open(Request $request, array $device, Identity $identity, Workspace $workspace, Membership $membership, bool $force = false, array $flags = []): array
    {
        $tenant = $workspace->tenant;
        $previous = $this->tenancy->tenant();
        $this->tenancy->initialize($tenant);
        try {
            $user = User::query()->findOrFail($membership->tenant_user_id);
            if ($user->status !== 'active') {
                throw new \RuntimeException('Membership is not active.');
            }
            $role = $user->branchRoles()->with('role')->first()?->role;
            $this->guardConcurrent($user, $role, $force);
            $branchId = $user->branchRoles()->value('branch_id');
            $idleMinutes = (int) Setting::getValue('idle_timeout', config('makerly.idle_timeout'));
            $absoluteHours = (int) Setting::getValue('absolute_session_hours', config('makerly.absolute_session_hours'));
            $id = (string) Str::uuid();
            $payload = [
                'id' => $id,
                'tenant_id' => $tenant->id,
                'device_session_id' => $device['id'],
                'identity_id' => $identity->id,
                'membership_id' => $membership->id,
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'role_slug' => $role?->slug,
                'cart' => [],
                'locked' => false,
                'lock_reason' => null,
                'last_activity' => time(),
                'absolute_expires_at' => time() + ($absoluteHours * 3600),
                'idle_minutes' => max(5, min(480, $idleMinutes)),
                'permission_version' => (int) $user->permission_version,
                'impersonation_log_id' => $flags['impersonation_log_id'] ?? null,
                'impersonator_id' => $flags['impersonator_id'] ?? null,
                'impersonation_expires_at' => $flags['impersonation_expires_at'] ?? null,
                'reauth_at' => null,
            ];
            $this->store($payload);
            WorkspaceSessionRow::query()->create([
                'id' => $id,
                'device_session_id' => $device['id'],
                'tenant_id' => $tenant->id,
                'identity_id' => $identity->id,
                'membership_id' => $membership->id,
                'user_id' => $user->id,
                'status' => 'active',
                'impersonated_by' => $flags['impersonator_id'] ?? null,
                'last_active_at' => now(),
                'expires_at' => now()->addHours($absoluteHours),
            ]);
            $membership->forceFill(['last_used_at' => now()])->save();
            Log::info('workspace_session.opened', [
                'tenant_id' => $tenant->id,
                'identity_id' => $identity->id,
            ]);

            return $payload;
        } finally {
            if ($previous) {
                $this->tenancy->initialize($previous);
            } else {
                $this->tenancy->end();
            }
        }
    }

    public function touch(array $payload): array
    {
        $payload['last_activity'] = time();
        $payload['locked'] = false;
        $payload['lock_reason'] = null;
        $this->store($payload);
        WorkspaceSessionRow::query()->where('id', $payload['id'])->update([
            'last_active_at' => now(),
            'status' => 'active',
        ]);

        return $payload;
    }

    public function lock(array $payload, ?string $reason = null): array
    {
        $payload['locked'] = true;
        $payload['lock_reason'] = $reason;
        $this->store($payload);
        WorkspaceSessionRow::query()->where('id', $payload['id'])->update(['status' => 'locked']);

        return $payload;
    }

    public function merge(array $device, string $tenantId, array $values): ?array
    {
        $payload = $this->current($device, $tenantId);
        if (! $payload) {
            return null;
        }
        foreach ($values as $key => $value) {
            if ($key === 'pos_cart') {
                $payload['cart'] = $value;
            } else {
                $payload[$key] = $value;
            }
        }
        $this->store($payload);

        return $payload;
    }

    public function endForTenantUser(string $tenantId, string $userId): void
    {
        $rows = WorkspaceSessionRow::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get();
        foreach ($rows as $row) {
            $this->vault->forget($this->key($tenantId, $row->device_session_id));
            $row->update(['revoked_at' => now(), 'status' => 'revoked']);
        }
        Log::info('workspace_session.ended', ['tenant_id' => $tenantId, 'user_id' => $userId]);
    }

    public function endForTenant(string $tenantId): void
    {
        $rows = WorkspaceSessionRow::query()->where('tenant_id', $tenantId)->whereNull('revoked_at')->get();
        foreach ($rows as $row) {
            $this->vault->forget($this->key($tenantId, $row->device_session_id));
            $row->update(['revoked_at' => now(), 'status' => 'revoked']);
        }
    }

    public function endOne(array $device, string $tenantId): void
    {
        $payload = $this->vault->get($this->key($tenantId, $device['id']));
        if ($payload) {
            $this->endPayload($payload);
        }
    }

    public function regenerateForUser(string $tenantId, string $userId, int $permissionVersion): void
    {
        $rows = WorkspaceSessionRow::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get();
        foreach ($rows as $row) {
            $payload = $this->vault->get($this->key($tenantId, $row->device_session_id));
            if (! $payload) {
                continue;
            }
            $payload['permission_version'] = $permissionVersion;
            $payload['id'] = (string) Str::uuid();
            $this->store($payload);
            $row->update(['id' => $payload['id'], 'status' => 'active']);
        }
    }

    public function policy(string $tenantId): array
    {
        $previous = $this->tenancy->tenant();
        $tenant = \App\Models\Tenant::query()->find($tenantId);
        if (! $tenant) {
            return ['unlock' => 'password', 'idle' => 30];
        }
        $this->tenancy->initialize($tenant);
        try {
            return [
                'unlock' => (string) Setting::getValue('unlock_method', 'password'),
                'idle' => (int) Setting::getValue('idle_timeout', 30),
                'pos_idle' => (int) Setting::getValue('pos_idle_timeout', 15),
                'absolute_hours' => (int) Setting::getValue('absolute_session_hours', 12),
                'require_2fa_roles' => Setting::getValue('require_2fa_roles', []),
                'offline_max_hours' => (int) Setting::getValue('offline_max_hours', 12),
                'pwa_display' => (string) Setting::getValue('pwa_display', 'standalone'),
            ];
        } finally {
            if ($previous) {
                $this->tenancy->initialize($previous);
            } else {
                $this->tenancy->end();
            }
        }
    }

    public function roleSlug(string $tenantId, string $userId): ?string
    {
        $previous = $this->tenancy->tenant();
        $tenant = \App\Models\Tenant::query()->findOrFail($tenantId);
        $this->tenancy->initialize($tenant);
        try {
            return UserBranchRole::query()->where('user_id', $userId)->with('role')->first()?->role?->slug;
        } finally {
            if ($previous) {
                $this->tenancy->initialize($previous);
            } else {
                $this->tenancy->end();
            }
        }
    }

    private function guardConcurrent(User $user, ?Role $role, bool $force): void
    {
        $limits = Setting::getValue('max_sessions', []);
        $max = is_array($limits) ? ($limits[$role?->slug] ?? null) : null;
        if (! $max) {
            return;
        }
        $count = WorkspaceSessionRow::query()
            ->where('tenant_id', $this->tenancy->id())
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('status', 'active')
            ->count();
        if ($count < (int) $max) {
            return;
        }
        if (! $force) {
            throw new ConcurrentSessionException('Already signed in on another device.');
        }
        $this->endForTenantUser((string) $this->tenancy->id(), $user->id);
    }

    private function store(array $payload): void
    {
        $ttl = max(60, ($payload['absolute_expires_at'] ?? time()) - time());
        $this->vault->put($this->key($payload['tenant_id'], $payload['device_session_id']), $payload, $ttl);
    }

    private function endPayload(array $payload): void
    {
        $this->vault->forget($this->key($payload['tenant_id'], $payload['device_session_id']));
        WorkspaceSessionRow::query()->where('id', $payload['id'] ?? null)->update([
            'revoked_at' => now(),
            'status' => 'revoked',
        ]);
    }

    private function key(string $tenantId, string $deviceId): string
    {
        return 'ws:'.$tenantId.':'.$deviceId;
    }
}
