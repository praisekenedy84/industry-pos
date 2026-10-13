<?php

namespace App\Auth;

use App\Models\Identity;
use App\Models\Membership;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class IdentityAuthenticator
{
    private string $dummy;

    public function __construct(private TenancyManager $tenancy)
    {
        $this->dummy = Hash::make('placeholder-not-a-password');
    }

    public function verify(string $identifier, string $password, ?string $workspaceSlug = null): ?Identity
    {
        [$column, $value] = $this->normalize($identifier, $workspaceSlug);
        $identity = $value ? Identity::query()->where($column, $value)->first() : null;
        $hash = $identity?->password ?: $this->dummy;
        $matches = Hash::check($password, $hash);
        if (! $identity || ! $matches || ! $identity->isActive()) {
            return null;
        }

        return $identity;
    }

    public function activeMemberships(Identity $identity): array
    {
        $rows = [];
        $previous = $this->tenancy->tenant();
        $memberships = $identity->memberships()->where('status', 'active')->orderByDesc('last_used_at')->get();
        foreach ($memberships as $membership) {
            $workspace = Workspace::query()->where('tenant_id', $membership->tenant_id)->first();
            $tenant = $membership->tenant;
            if (! $workspace || ! $tenant || $workspace->status !== 'active' || $tenant->isSuspended()) {
                continue;
            }
            $this->tenancy->initialize($tenant);
            $user = User::query()->find($membership->tenant_user_id);
            $role = $user?->branchRoles()->with('role')->first()?->role;
            $rows[] = [
                'membership' => $membership,
                'workspace' => $workspace,
                'tenant' => $tenant,
                'user' => $user,
                'role' => $role?->name,
                'role_slug' => $role?->slug,
            ];
        }
        $this->restore($previous);

        return $rows;
    }

    private function restore(?\App\Models\Tenant $previous): void
    {
        if ($previous) {
            $this->tenancy->initialize($previous);
        } else {
            $this->tenancy->end();
        }
    }

    public function requiresTwoFactor(Identity $identity, ?Membership $only = null): bool
    {
        if ($identity->two_factor_confirmed_at) {
            return true;
        }
        $memberships = $only ? collect([$only]) : $identity->memberships()->where('status', 'active')->get();
        $previous = $this->tenancy->tenant();
        foreach ($memberships as $membership) {
            $tenant = $membership->tenant;
            if (! $tenant) {
                continue;
            }
            $this->tenancy->initialize($tenant);
            $required = Setting::getValue('require_2fa_roles', []);
            $slug = User::query()->find($membership->tenant_user_id)?->branchRoles()->with('role')->first()?->role?->slug;
            if (is_array($required) && $slug && in_array($slug, $required, true)) {
                $this->restore($previous);

                return true;
            }
        }
        $this->restore($previous);

        return false;
    }

    public function normalize(string $identifier, ?string $workspaceSlug = null): array
    {
        $identifier = trim($identifier);
        if ($workspaceSlug) {
            return ['login_alias', strtolower($workspaceSlug).':'.strtolower($identifier)];
        }
        if (str_contains($identifier, '@')) {
            return ['email', strtolower($identifier)];
        }
        $compact = preg_replace('/[^\d+]/', '', $identifier) ?? '';
        if (preg_match('/^\+[1-9]\d{7,14}$/', $compact)) {
            return ['phone', $compact];
        }
        if (preg_match('/^\d{8,15}$/', $compact)) {
            return ['phone', $compact];
        }

        return ['login_alias', strtolower($identifier)];
    }

    public function noteFailure(string $ip): void
    {
        Log::warning('auth.login_failed', ['ip' => $ip, 'identity_id' => null]);
    }
}
