<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Models\UserPermissionOverride;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Cache;

class AccessManager
{
    public function __construct(private FeatureGate $features) {}

    public function allows(User $user, string $key, mixed $record = null): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $permission = PermissionCatalog::find($key);
        if ($permission && $permission['feature'] && ! $this->features->active($permission['feature'])) {
            return false;
        }

        if ($this->isOwner($user)) {
            return $this->recordVisible($user, 'all', $record);
        }

        $grant = $this->grant($user, $key);
        if (! $grant) {
            return false;
        }
        if ($grant['effect'] === 'deny') {
            return false;
        }

        return $this->recordVisible($user, $grant['scope'], $record);
    }

    public function constraintsFor(User $user, string $key): array
    {
        if ($this->isOwner($user)) {
            return [];
        }
        $grant = $this->grant($user, $key);

        return $grant['constraints'] ?? [];
    }

    public function isOwner(User $user): bool
    {
        return collect($this->grants($user)['roles'])->contains('owner');
    }

    public function visibleBranchIds(User $user, string $key): array
    {
        $all = Branch::query()->pluck('id')->all();
        if ($this->isOwner($user) || ($this->grant($user, $key)['scope'] ?? null) === 'all') {
            return $all;
        }
        $grant = $this->grant($user, $key);
        if (! $grant || $grant['effect'] === 'deny' || $grant['scope'] === 'none') {
            return [];
        }

        return $grant['branches'] ?: [];
    }

    public function ownOnly(User $user, string $key): bool
    {
        if ($this->isOwner($user)) {
            return false;
        }

        return ($this->grant($user, $key)['scope'] ?? null) === 'own';
    }

    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    private function grant(User $user, string $key): ?array
    {
        return $this->grants($user)['permissions'][$key] ?? null;
    }

    private function grants(User $user): array
    {
        return Cache::remember($this->cacheKey($user), 300, function () use ($user) {
            $roles = [];
            $permissions = [];
            $assignments = UserBranchRole::query()->where('user_id', $user->id)->get();
            $roleModels = Role::query()->whereIn('id', $assignments->pluck('role_id'))->get()->keyBy('id');

            foreach ($assignments as $assignment) {
                $role = $roleModels[$assignment->role_id] ?? null;
                if (! $role || $role->archived_at) {
                    continue;
                }
                $roles[] = $role->slug;
                if ($role->slug === 'owner') {
                    foreach (PermissionCatalog::all() as $permission) {
                        $permissions[$permission['key']] = [
                            'effect' => 'allow',
                            'scope' => 'all',
                            'constraints' => [],
                            'branches' => [],
                        ];
                    }

                    continue;
                }
                foreach ($this->rolePermissions($role) as $row) {
                    $current = $permissions[$row->permission_key] ?? null;
                    $rank = ['none' => 0, 'own' => 1, 'branch' => 2, 'all' => 3];
                    if (! $current || ($rank[$row->scope] ?? 0) > ($rank[$current['scope']] ?? 0)) {
                        $permissions[$row->permission_key] = [
                            'effect' => 'allow',
                            'scope' => $row->scope,
                            'constraints' => $row->constraints ?? [],
                            'branches' => [$assignment->branch_id],
                        ];
                    } else {
                        $permissions[$row->permission_key]['branches'][] = $assignment->branch_id;
                    }
                }
            }

            $overrides = UserPermissionOverride::query()
                ->where('user_id', $user->id)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->get();

            foreach ($overrides as $override) {
                if ($override->effect === 'deny') {
                    $permissions[$override->permission_key] = [
                        'effect' => 'deny',
                        'scope' => 'none',
                        'constraints' => [],
                        'branches' => [],
                    ];

                    continue;
                }
                $permissions[$override->permission_key] = [
                    'effect' => 'allow',
                    'scope' => $override->scope,
                    'constraints' => $override->constraints ?? [],
                    'branches' => $permissions[$override->permission_key]['branches'] ?? Branch::query()->pluck('id')->all(),
                ];
            }

            return ['roles' => array_values(array_unique($roles)), 'permissions' => $permissions];
        });
    }

    private function rolePermissions(Role $role)
    {
        return \App\Models\RolePermission::query()->where('role_id', $role->id)->get();
    }

    private function recordVisible(User $user, string $scope, mixed $record): bool
    {
        if ($scope === 'none') {
            return false;
        }
        if ($record === null || $scope === 'all') {
            return $scope !== 'none';
        }
        $branchId = is_object($record) ? ($record->branch_id ?? (property_exists($record, 'id') && $record instanceof Branch ? $record->id : null)) : null;
        $ownerId = is_object($record) ? ($record->user_id ?? $record->created_by ?? null) : null;
        if ($scope === 'own') {
            return $ownerId === null || $ownerId === $user->id;
        }
        if ($scope === 'branch') {
            if ($branchId === null) {
                return true;
            }
            $assigned = UserBranchRole::query()->where('user_id', $user->id)->pluck('branch_id')->all();

            return in_array($branchId, $assigned, true);
        }

        return false;
    }

    private function cacheKey(User $user): string
    {
        return 'perm:'.app(\App\Tenancy\TenancyManager::class)->id().':'.$user->id.':'.$user->permission_version;
    }
}
