<?php

namespace App\Http\Controllers;

use App\Auth\WorkspaceSessionManager;
use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\ApiToken;
use App\Models\ApiTokenTenant;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RoleVersion;
use App\Models\Setting;
use App\Models\SystemUser;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Models\UserPermissionOverride;
use App\Models\WorkspaceSession;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Support\IndustryCatalog;
use App\Support\IndustryVocabulary;
use App\Support\PermissionCatalog;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use ResolvesBranch;

    public function __construct(
        private AuditLogger $audit,
        private AccessManager $access,
        private FeatureGate $features,
        private TenantProvisioner $provisioner,
    ) {}

    public function index(): Response
    {
        $this->authorize('settings.manage');

        return Inertia::render('Settings/Index', [
            'settings' => Setting::query()->get()->pluck('value', 'key'),
            'taxes' => TaxRate::query()->get(),
            'methods' => PaymentMethod::query()->get(),
            'tenant' => app(TenancyManager::class)->tenant(),
            'templates' => IndustryCatalog::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $this->blockImpersonation();
        $data = $request->validate([
            'prices_include_tax' => ['nullable', 'boolean'],
            'negative_stock' => ['nullable', 'in:block,warn,allow'],
            'valuation' => ['nullable', 'in:weighted_average,fifo'],
            'production_track_materials' => ['sometimes', 'boolean'],
            'production_waste_treatment' => ['sometimes', 'required', 'in:absorbed,expensed'],
            'production_account_finished_inventory' => ['sometimes', 'required', 'string', 'max:64'],
            'production_account_raw_material_inventory' => ['sometimes', 'required', 'string', 'max:64'],
            'production_account_production_loss' => ['sometimes', 'required', 'string', 'max:64'],
            'production_account_production_clearing' => ['sometimes', 'required', 'string', 'max:64'],
            'idle_timeout' => ['nullable', 'integer', 'min:5', 'max:240'],
            'pos_idle_timeout' => ['nullable', 'integer', 'min:1', 'max:60'],
            'receipt_footer' => ['nullable', 'string'],
            'require_2fa_roles' => ['nullable', 'array'],
            'branch_modules' => ['nullable', 'array'],
            'industry_key' => ['nullable', 'string', Rule::in(array_keys(IndustryCatalog::all()))],
        ]);
        foreach ($data as $key => $value) {
            if ($key === 'industry_key') {
                continue;
            }
            Setting::putValue($key, $value);
        }
        $tenant = app(TenancyManager::class)->tenant();
        if ($tenant && $request->filled('default_locale')) {
            $tenant->update([
                'default_locale' => $request->string('default_locale'),
                'receipt_language' => $request->string('receipt_language', 'bilingual'),
            ]);
        }
        if ($tenant && $request->filled('industry_key')) {
            $key = $request->string('industry_key')->toString();
            $tenant->update(['industry_key' => $key]);
            $vocabulary = app(IndustryVocabulary::class);
            $vocabulary->forget($key);
            Setting::putValue('vocabulary', $vocabulary->forIndustry($key));
        }
        $this->audit->tenant('settings.updated', 'settings', null, null, $data);

        return back()->with('success', 'Settings saved.');
    }

    public function branches(): Response
    {
        $this->authorize('users.view');

        return Inertia::render('Settings/Branches', [
            'branches' => Branch::query()->orderBy('name')->get(),
            'canManage' => auth()->user()->can('settings.manage'),
            'multi' => $this->features->active('branches.multi_branch'),
            'limit' => $this->features->limit('max_branches'),
        ]);
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless($this->features->active('branches.multi_branch') || Branch::query()->count() === 0, 403);
        $max = $this->features->limit('max_branches');
        if ($max && Branch::query()->count() >= $max) {
            throw ValidationException::withMessages(['name' => "Your plan allows {$max} branches. Contact support to increase."]);
        }
        $data = $request->validate(['name' => ['required', 'string'], 'code' => ['required', 'string', 'max:8'], 'address' => ['nullable', 'string'], 'phone' => ['nullable', 'string']]);
        $branch = Branch::query()->create([...$data, 'status' => 'active']);
        $owner = Role::query()->where('slug', 'owner')->first();
        UserBranchRole::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'branch_id' => $branch->id,
        ], ['role_id' => $owner->id]);
        $this->audit->tenant('branch.created', 'branch', $branch->id);

        return back()->with('success', 'Branch created.');
    }

    public function updateBranch(Request $request, string $workspace, Branch $branch): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'], 'phone' => ['nullable', 'string', 'max:255']]);
        DB::connection('tenant')->transaction(function () use ($branch, $data): void {
            $before = $branch->toArray();
            $branch->update($data);
            $this->audit->tenant('branch.updated', 'branch', $branch->id, $before, $branch->fresh()->toArray());
        });

        return back()->with('success', 'Branch updated.');
    }

    public function suspendBranch(Request $request, string $workspace, Branch $branch): RedirectResponse
    {
        $this->authorize('settings.manage');
        $data = $request->validate(['reason' => ['required', 'string'], 'status' => ['required', 'in:active,suspended']]);
        $branch->update(['status' => $data['status'], 'suspended_reason' => $data['status'] === 'suspended' ? $data['reason'] : null]);
        $this->audit->tenant('branch.'.$data['status'], 'branch', $branch->id, null, $data);

        return back()->with('success', 'Branch updated.');
    }

    public function users(): Response
    {
        $this->authorize('users.view');

        return Inertia::render('Settings/Users', [
            'users' => User::query()->orderBy('name')->get(),
            'roles' => Role::query()->whereNull('archived_at')->get(),
            'branches' => Branch::query()->get(),
            'assignments' => UserBranchRole::query()->get(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorize('users.manage');
        $max = $this->features->limit('max_staff_users');
        if ($max && User::query()->where('status', '!=', 'deactivated')->count() >= $max) {
            throw ValidationException::withMessages(['email' => "Your plan allows {$max} staff. Contact support to increase."]);
        }
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'role_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
            'job_title' => ['nullable', 'string'],
        ]);
        $user = $this->provisioner->inviteUser($data, $data['role_id'], $data['branch_id']);
        $this->audit->tenant('user.invited', 'user', $user->id);
        $link = url('/invite/'.$user->plain_invite_token);

        return back()->with('success', 'Invite created. Link: '.$link);
    }

    public function importUsers(Request $request): RedirectResponse
    {
        $this->authorize('users.manage');
        $data = $request->validate(['csv' => ['required', 'string'], 'role_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid']]);
        $lines = preg_split("/\r\n|\n|\r/", trim($data['csv'])) ?: [];
        array_shift($lines);
        $count = 0;
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }
            $this->provisioner->inviteUser(['name' => $cols[0], 'email' => $cols[1], 'phone' => $cols[2] ?? null], $data['role_id'], $data['branch_id']);
            $count++;
        }

        return back()->with('success', $count.' people invited.');
    }

    public function showUser(User $user): Response
    {
        $this->authorize('users.view');

        return Inertia::render('Settings/UserShow', [
            'person' => $user->makeVisible([]),
            'assignments' => UserBranchRole::query()->where('user_id', $user->id)->get(),
            'sessions' => WorkspaceSession::query()
                ->where('tenant_id', app(TenancyManager::class)->id())
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->get(),
            'activity' => AuditLog::query()->where('actor_id', $user->id)->latest('created_at')->limit(30)->get(),
            'overrides' => UserPermissionOverride::query()->where('user_id', $user->id)->get(),
            'roles' => Role::query()->get(),
            'branches' => Branch::query()->get(),
            'effective' => $this->effective($user),
        ]);
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->blockImpersonation();
        $data = $request->validate([
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'job_title' => ['nullable', 'string'],
            'employee_code' => ['nullable', 'string'],
            'role_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'pin' => ['nullable', 'digits_between:4,6'],
            'override_key' => ['nullable', 'string'],
            'override_effect' => ['nullable', 'in:allow,deny'],
            'override_reason' => ['nullable', 'string'],
        ]);
        if (! empty($data['role_id']) && $this->isLastOwner($user, $data['role_id'])) {
            throw ValidationException::withMessages(['role_id' => 'The last owner cannot be demoted.']);
        }
        $user->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'employee_code' => $data['employee_code'] ?? null,
            'pin_hash' => ! empty($data['pin']) ? Hash::make($data['pin']) : $user->pin_hash,
        ]);
        if (! empty($data['role_id']) && ! empty($data['branch_id'])) {
            UserBranchRole::query()->updateOrCreate(
                ['user_id' => $user->id, 'branch_id' => $data['branch_id']],
                ['role_id' => $data['role_id']],
            );
            $user->increment('permission_version');
            $this->access->forget($user);
            app(WorkspaceSessionManager::class)->regenerateForUser(
                (string) app(TenancyManager::class)->id(),
                $user->id,
                (int) $user->fresh()->permission_version,
            );
        }
        if (! empty($data['override_key'])) {
            UserPermissionOverride::query()->create([
                'user_id' => $user->id,
                'permission_key' => $data['override_key'],
                'effect' => $data['override_effect'] ?? 'allow',
                'scope' => 'branch',
                'reason' => $data['override_reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $user->increment('permission_version');
        }
        $this->audit->tenant('user.updated', 'user', $user->id);

        return back()->with('success', 'Person updated.');
    }

    public function suspendUser(User $user): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->guardLastOwner($user);
        $user->update(['status' => $user->status === 'suspended' ? 'active' : 'suspended']);
        Membership::query()->where('id', $user->membership_id)->update([
            'status' => $user->status === 'active' ? 'active' : 'suspended',
        ]);
        $this->killSessions($user);
        $this->audit->tenant('user.suspended', 'user', $user->id, null, ['status' => $user->status]);

        return back()->with('success', 'Access updated.');
    }

    public function deactivateUser(User $user): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->guardLastOwner($user);
        $user->update(['status' => 'deactivated']);
        Membership::query()->where('id', $user->membership_id)->update(['status' => 'removed']);
        $this->killSessions($user);
        $this->audit->tenant('user.deactivated', 'user', $user->id);

        return back()->with('success', 'Person deactivated. History is kept.');
    }

    public function revokeSessions(User $user): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->killSessions($user);

        return back()->with('success', 'Sessions signed out.');
    }

    public function roles(): Response
    {
        $this->authorize('roles.manage');

        return Inertia::render('Settings/Roles', ['roles' => Role::query()->orderBy('name')->get()]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorize('roles.manage');
        $data = $request->validate(['name' => ['required', 'string'], 'clone_from' => ['nullable', 'uuid']]);
        $role = Role::query()->create(['name' => $data['name'], 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)), 'is_system' => false]);
        if (! empty($data['clone_from'])) {
            foreach (RolePermission::query()->where('role_id', $data['clone_from'])->get() as $grant) {
                RolePermission::query()->create(['role_id' => $role->id, 'permission_key' => $grant->permission_key, 'scope' => $grant->scope, 'constraints' => $grant->constraints]);
            }
        }

        return redirect()->route('settings.roles.edit', $role);
    }

    public function editRole(Role $role): Response
    {
        $this->authorize('roles.manage');
        $permissions = Permission::query()->orderBy('module')->orderBy('key')->get()->filter(function ($permission) {
            return ! $permission->feature || $this->features->active($permission->feature);
        })->values();

        return Inertia::render('Settings/RoleEditor', [
            'role' => $role,
            'grants' => RolePermission::query()->where('role_id', $role->id)->get(),
            'permissions' => $permissions,
            'versions' => RoleVersion::query()->where('role_id', $role->id)->orderByDesc('version')->get(),
        ]);
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        $this->blockImpersonation();
        if ($role->is_locked && ! $this->access->isOwner($request->user())) {
            abort(403);
        }
        $data = $request->validate([
            'name' => ['required', 'string'],
            'grants' => ['required', 'array'],
            'grants.*.permission_key' => ['required', 'string'],
            'grants.*.scope' => ['required', 'in:none,own,branch,all'],
            'grants.*.constraints' => ['nullable', 'array'],
        ]);
        if ($role->slug === 'owner') {
            throw ValidationException::withMessages(['grants' => 'The owner role stays at full access.']);
        }
        RoleVersion::query()->create([
            'role_id' => $role->id,
            'version' => $role->version,
            'snapshot' => RolePermission::query()->where('role_id', $role->id)->get(),
            'created_by' => $request->user()->id,
        ]);
        RolePermission::query()->where('role_id', $role->id)->delete();
        foreach ($data['grants'] as $grant) {
            if ($grant['scope'] === 'none') {
                continue;
            }
            if (! $this->access->allows($request->user(), $grant['permission_key']) && ! $this->access->isOwner($request->user())) {
                throw ValidationException::withMessages(['grants' => 'You cannot grant '.$grant['permission_key'].'.']);
            }
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission_key' => $grant['permission_key'],
                'scope' => $grant['scope'],
                'constraints' => $grant['constraints'] ?? null,
            ]);
        }
        $role->update(['name' => $data['name'], 'version' => $role->version + 1]);
        User::query()->whereIn('id', UserBranchRole::query()->where('role_id', $role->id)->pluck('user_id'))->increment('permission_version');
        $this->audit->tenant('role.updated', 'role', $role->id);

        return back()->with('success', 'Role saved. People get the new access on their next request.');
    }

    public function cloneRole(Role $role): RedirectResponse
    {
        $this->authorize('roles.manage');
        $copy = Role::query()->create(['name' => $role->name.' copy', 'slug' => $role->slug.'-copy-'.Str::lower(Str::random(3)), 'is_system' => false]);
        foreach (RolePermission::query()->where('role_id', $role->id)->get() as $grant) {
            RolePermission::query()->create(['role_id' => $copy->id, 'permission_key' => $grant->permission_key, 'scope' => $grant->scope, 'constraints' => $grant->constraints]);
        }

        return redirect()->route('settings.roles.edit', $copy);
    }

    public function audit(): Response
    {
        $this->authorize('audit.view');

        return Inertia::render('Settings/Audit', [
            'logs' => AuditLog::query()->latest('created_at')->paginate(40),
        ]);
    }

    public function systemUsers(): Response
    {
        $this->authorize('system_users.manage');

        return Inertia::render('Settings/SystemUsers', [
            'systemUsers' => SystemUser::query()->latest()->get(),
            'tokens' => ApiToken::query()->latest()->get(),
            'permissions' => PermissionCatalog::all(),
        ]);
    }

    public function storeSystemUser(Request $request): RedirectResponse
    {
        $this->authorize('system_users.manage');
        $this->blockImpersonation();
        $max = $this->features->limit('max_system_users');
        if ($max && SystemUser::query()->count() >= $max) {
            throw ValidationException::withMessages(['name' => "Your plan allows {$max} system users. Contact support to increase."]);
        }
        $data = $request->validate(['name' => ['required', 'string'], 'purpose' => ['nullable', 'string']]);
        SystemUser::query()->create([...$data, 'owner_user_id' => $request->user()->id]);

        return back()->with('success', 'System user created.');
    }

    public function createToken(Request $request, SystemUser $systemUser): RedirectResponse
    {
        $this->authorize('system_users.manage');
        $this->blockImpersonation();
        $data = $request->validate([
            'name' => ['required', 'string'],
            'scopes' => ['required', 'array', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'ip_allowlist' => ['nullable', 'string'],
        ]);
        $plain = 'mk_live_'.Str::random(40);
        $hash = hash('sha256', $plain);
        ApiToken::query()->create([
            'system_user_id' => $systemUser->id,
            'name' => $data['name'],
            'token_hash' => $hash,
            'token_prefix' => substr($plain, 0, 12),
            'scopes' => $data['scopes'],
            'expires_at' => $data['expires_at'] ?? null,
            'ip_allowlist' => $data['ip_allowlist'] ? array_map('trim', explode(',', $data['ip_allowlist'])) : null,
        ]);
        ApiTokenTenant::query()->create([
            'token_hash' => $hash,
            'tenant_id' => app(TenancyManager::class)->id(),
        ]);
        $this->audit->tenant('token.created', 'system_user', $systemUser->id);

        return back()->with('success', 'Copy this token now. It will not be shown again: '.$plain);
    }

    public function revokeToken(ApiToken $token): RedirectResponse
    {
        $this->authorize('system_users.manage');
        $token->update(['revoked_at' => now()]);
        ApiTokenTenant::query()->where('token_hash', $token->token_hash)->delete();

        return back()->with('success', 'Token revoked.');
    }

    public function rotateToken(ApiToken $token): RedirectResponse
    {
        $this->authorize('system_users.manage');
        $this->blockImpersonation();
        $token->update(['revoked_at' => now()]);
        ApiTokenTenant::query()->where('token_hash', $token->token_hash)->delete();
        $plain = 'mk_live_'.Str::random(40);
        $hash = hash('sha256', $plain);
        ApiToken::query()->create([
            'system_user_id' => $token->system_user_id,
            'name' => $token->name.' rotated',
            'token_hash' => $hash,
            'token_prefix' => substr($plain, 0, 12),
            'scopes' => $token->scopes,
            'branch_ids' => $token->branch_ids,
            'expires_at' => $token->expires_at,
            'ip_allowlist' => $token->ip_allowlist,
        ]);
        ApiTokenTenant::query()->create([
            'token_hash' => $hash,
            'tenant_id' => app(TenancyManager::class)->id(),
        ]);

        return back()->with('success', 'New token: '.$plain);
    }

    public function approvals(): Response
    {
        $this->authorize('approvals.resolve');

        return Inertia::render('Settings/Approvals', [
            'approvals' => ApprovalRequest::query()->latest()->paginate(20),
        ]);
    }

    public function resolveApproval(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('approvals.resolve');
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'reason' => ['nullable', 'string']]);
        $approval->update(['status' => $data['status'], 'resolved_by' => $request->user()->id, 'reason' => $data['reason'] ?? null]);

        return back()->with('success', 'Approval updated.');
    }

    private function killSessions(User $user): void
    {
        app(WorkspaceSessionManager::class)->endForTenantUser(
            (string) app(TenancyManager::class)->id(),
            $user->id,
        );
    }

    private function guardLastOwner(User $user): void
    {
        if ($this->isLastOwner($user, null)) {
            throw ValidationException::withMessages(['user' => 'The last owner cannot be removed.']);
        }
    }

    private function isLastOwner(User $user, ?string $newRoleId): bool
    {
        $owner = Role::query()->where('slug', 'owner')->first();
        if (! $owner) {
            return false;
        }
        $holds = UserBranchRole::query()->where('user_id', $user->id)->where('role_id', $owner->id)->exists();
        if (! $holds) {
            return false;
        }
        if ($newRoleId === $owner->id) {
            return false;
        }
        $others = UserBranchRole::query()->where('role_id', $owner->id)->where('user_id', '!=', $user->id)->count();

        return $others === 0;
    }

    private function blockImpersonation(): void
    {
        abort_if(session('impersonation_log_id'), 403, 'This action is blocked while impersonating.');
    }

    private function effective(User $user): array
    {
        $rows = [];
        foreach (PermissionCatalog::all() as $permission) {
            $rows[] = [
                'key' => $permission['key'],
                'allowed' => $this->access->allows($user, $permission['key']),
                'constraints' => $this->access->constraintsFor($user, $permission['key']),
            ];
        }

        return $rows;
    }
}
