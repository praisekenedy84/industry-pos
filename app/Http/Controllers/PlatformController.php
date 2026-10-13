<?php

namespace App\Http\Controllers;

use App\Auth\DeviceSessionManager;
use App\Auth\WorkspaceSessionManager;
use App\Mail\WorkspaceApprovalNotice;
use App\Models\Branch;
use App\Models\Feature;
use App\Models\Identity;
use App\Models\ImpersonationLog;
use App\Models\IndustryTemplate;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantLimit;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Support\IndustryCatalog;
use App\Support\IndustryTerms;
use App\Support\IndustryVocabulary;
use App\Support\WorkspaceApproval;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private TenantProvisioner $provisioner,
        private TenancyManager $tenancy,
        private FeatureGate $features,
    ) {}

    public function loginForm(): Response
    {
        return Inertia::render('Platform/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! Auth::guard('platform')->attempt(['email' => $data['email'], 'password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return redirect()->route('platform.tenants');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }

    public function signupForm(): Response
    {
        return Inertia::render('Auth/Signup', [
            'templates' => IndustryCatalog::options(),
        ]);
    }

    public function signup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:40'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email'],
            'owner_password' => ['required', 'string', 'min:10'],
            'industry_key' => ['required', 'string', Rule::in(array_keys(IndustryCatalog::all()))],
        ]);
        $tenant = $this->provisioner->provision([
            ...$data,
            'sample' => true,
            'trial' => true,
        ]);
        $this->audit->central('tenant.created', 'tenant', $tenant->id, null, ['slug' => $tenant->slug]);
        $this->notifyTrial($tenant);
        $membership = Membership::query()->where('tenant_id', $tenant->id)->where('status', 'active')->first();
        $identity = Identity::query()->findOrFail($membership->identity_id);
        $devices = app(DeviceSessionManager::class);
        $device = $devices->issue($request, $identity, true, false);
        $device = $devices->regenerate($request, $device);
        app(WorkspaceSessionManager::class)->open($request, $device, $identity, $tenant->workspace, $membership, false);

        return redirect()->route('dashboard', ['workspace' => $tenant->slug]);
    }

    public function tenants(): Response
    {
        $tenants = Tenant::query()->with('workspace')->latest()->get()
            ->sortBy(fn (Tenant $tenant): int => $tenant->status === 'trial' ? 0 : 1)
            ->values()
            ->map(function (Tenant $tenant) {
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                    'awaiting_approval' => $tenant->trialHasExpired(),
                    'trial_ends_at' => $tenant->trial_ends_at,
                    'domain' => '/w/'.$tenant->slug.'/',
                    'industry_key' => $tenant->industry_key,
                    'industry_name' => IndustryCatalog::name((string) $tenant->industry_key),
                    'branches' => $this->countInside($tenant, 'branches'),
                    'branch_limit' => TenantLimit::query()->where('tenant_id', $tenant->id)->where('limit_key', 'max_branches')->value('value'),
                    'updated_at' => $tenant->updated_at,
                ];
            });

        return Inertia::render('Platform/Tenants', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant): Response
    {
        $features = Feature::query()->orderBy('key')->get();
        $overrides = TenantFeature::query()->where('tenant_id', $tenant->id)->get()->keyBy('feature_key');
        $limits = TenantLimit::query()->where('tenant_id', $tenant->id)->get();
        $directory = $this->tenantHasTable($tenant, 'users')
            ? $this->inside($tenant, fn (): array => [
                'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'status', 'job_title']),
                'roles' => Role::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            ])
            : ['users' => [], 'roles' => [], 'branches' => []];
        $tenant->setAttribute('awaiting_approval', $tenant->trialHasExpired());

        return Inertia::render('Platform/TenantShow', [
            'tenant' => $tenant->load('workspace'),
            'features' => $features->map(fn ($feature) => [
                'key' => $feature->key,
                'description' => $feature->description,
                'module' => $feature->module,
                'globally_enabled' => $feature->globally_enabled,
                'enabled' => (bool) ($overrides[$feature->key]->value ?? $feature->default_value),
                'expires_at' => $overrides[$feature->key]->expires_at ?? null,
            ]),
            'limits' => $limits,
            'users' => $directory['users'],
            'roles' => $directory['roles'],
            'branches' => $directory['branches'],
            'impersonations' => ImpersonationLog::query()->where('tenant_id', $tenant->id)->latest()->limit(20)->get(),
            'templates' => IndustryCatalog::options(),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'default_locale' => ['required', 'in:en,sw'],
            'receipt_language' => ['required', 'in:en,sw,bilingual'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'industry_key' => ['sometimes', 'required', 'string', Rule::in(array_keys(IndustryCatalog::all()))],
        ]);
        $before = $tenant->only(array_keys($data));
        $tenant->update($data);
        if (isset($data['industry_key'])) {
            $this->storeVocabulary($tenant, $data['industry_key']);
        }
        $this->audit->central('tenant.updated', 'tenant', $tenant->id, $before, $data);

        return back()->with('success', 'Tenant updated.');
    }

    public function suspend(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $tenant->update(['status' => 'suspended', 'suspended_reason' => $data['reason']]);
        Workspace::query()->where('tenant_id', $tenant->id)->update(['status' => 'suspended']);
        app(WorkspaceSessionManager::class)->endForTenant($tenant->id);
        $this->audit->central('tenant.suspended', 'tenant', $tenant->id, null, $data);

        return back()->with('success', 'Tenant suspended. Data is retained.');
    }

    public function activate(Tenant $tenant): RedirectResponse
    {
        $wasTrial = $tenant->status === 'trial';
        $tenant->update([
            'status' => 'active',
            'suspended_reason' => null,
            'retention_until' => null,
            'approved_at' => $tenant->approved_at ?? now(),
        ]);
        Workspace::query()->where('tenant_id', $tenant->id)->update(['status' => 'active']);
        if ($wasTrial) {
            $this->notifyApproved($tenant);
        }
        $this->audit->central('tenant.activated', 'tenant', $tenant->id);

        return back()->with('success', $wasTrial ? 'Workspace approved. People can keep using it.' : 'Tenant activated.');
    }

    public function storeUser(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:'.(int) config('makerly.password_min')],
            'role_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
        ]);
        if (! $this->tenantHasTable($tenant, 'users')) {
            throw ValidationException::withMessages(['email' => 'This workspace is not ready for people yet.']);
        }

        $email = strtolower($data['email']);
        $this->inside($tenant, function () use ($data, $tenant, $email): void {
            $max = $this->features->limit('max_staff_users');
            if ($max && User::query()->where('status', '!=', 'deactivated')->count() >= $max) {
                throw ValidationException::withMessages(['email' => "This workspace allows {$max} people. Raise the limit to add another."]);
            }
            if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'That email is already used in this workspace.']);
            }
            if (! Role::query()->whereKey($data['role_id'])->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['role_id' => 'Choose a role from this workspace.']);
            }
            if (! Branch::query()->whereKey($data['branch_id'])->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'Choose a branch from this workspace.']);
            }
            $user = $this->provisioner->inviteUser([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ], $data['role_id'], $data['branch_id']);
            UserNotification::query()->create([
                'user_id' => $user->id,
                'type' => 'workspace_member',
                'title' => 'You can sign in',
                'body' => WorkspaceApproval::memberMessage($tenant->name),
            ]);
            $this->audit->central('tenant.user_created', 'user', $user->id, null, [
                'tenant_id' => $tenant->id,
                'email' => $email,
            ]);
        });
        $this->sendNotice($email, 'You can sign in to '.$tenant->name, WorkspaceApproval::memberMessage($tenant->name));

        return back()->with('success', 'Person added. They can sign in with that email and the password you set.');
    }

    public function features(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*.key' => ['required', 'string'],
            'features.*.enabled' => ['required', 'boolean'],
            'features.*.expires_at' => ['nullable', 'date'],
        ]);
        foreach ($data['features'] as $row) {
            TenantFeature::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'feature_key' => $row['key']],
                ['value' => $row['enabled'], 'set_by' => Auth::guard('platform')->id(), 'expires_at' => $row['expires_at'] ?? null],
            );
        }
        $this->audit->central('features.updated', 'tenant', $tenant->id, null, $data['features']);

        return back()->with('success', 'Features updated.');
    }

    public function limits(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['limits' => ['required', 'array']]);
        foreach ($data['limits'] as $key => $value) {
            TenantLimit::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'limit_key' => $key],
                ['value' => (int) $value],
            );
        }
        $this->audit->central('limits.updated', 'tenant', $tenant->id, null, $data['limits']);

        return back()->with('success', 'Limits updated.');
    }

    public function impersonate(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:8'],
            'password' => ['required', 'string'],
        ]);
        $admin = Auth::guard('platform')->user();
        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['password' => 'Re-enter your password to impersonate.']);
        }
        $log = ImpersonationLog::query()->create([
            'tenant_id' => $tenant->id,
            'platform_admin_id' => $admin->id,
            'user_id' => $data['user_id'],
            'reason' => $data['reason'],
            'started_at' => now(),
            'expires_at' => now()->addMinutes((int) config('makerly.impersonation_minutes')),
        ]);
        $this->audit->central('impersonation.started', 'impersonation', $log->id, null, ['user_id' => $data['user_id'], 'reason' => $data['reason']]);
        $this->inside($tenant, function () use ($tenant, $data) {
            $owner = User::query()->whereHas('branchRoles.role', fn ($q) => $q->where('slug', 'owner'))->first();
            if ($owner?->email) {
                Mail::raw(
                    'A platform admin started an impersonation session for '.$tenant->name.'. Reason: '.$data['reason'],
                    fn ($message) => $message->to($owner->email)->subject('Makerly impersonation notice'),
                );
            }
        });
        $membership = Membership::query()->where('tenant_id', $tenant->id)->where('tenant_user_id', $data['user_id'])->firstOrFail();
        $identity = Identity::query()->findOrFail($membership->identity_id);
        $devices = app(DeviceSessionManager::class);
        $device = $devices->issue($request, $identity, false, true);
        $device = $devices->regenerate($request, $device);
        app(WorkspaceSessionManager::class)->open($request, $device, $identity, $tenant->workspace, $membership, true, [
            'impersonation_log_id' => $log->id,
            'impersonator_id' => $admin->id,
            'impersonation_expires_at' => $log->expires_at->toIso8601String(),
        ]);
        $console = str_starts_with($request->path(), 'admin/') ? 'admin' : 'platform';
        $request->session()->put('impersonation_return', route($console.'.tenants.show', $tenant));

        return redirect()->route('dashboard', ['workspace' => $tenant->slug]);
    }

    public function export(Tenant $tenant): StreamedResponse
    {
        $this->audit->central('tenant.export', 'tenant', $tenant->id);
        $payload = $this->inside($tenant, function () {
            $tables = ['branches', 'users', 'items', 'sales', 'sale_lines', 'stock_movements', 'customers', 'recipes'];
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = DB::connection('tenant')->table($table)->get();
            }

            return $out;
        });

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT);
        }, $tenant->slug.'-export.json', ['Content-Type' => 'application/json']);
    }

    public function scheduleDeletion(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $tenant->update([
            'status' => 'pending_deletion',
            'suspended_reason' => $data['reason'],
            'retention_until' => now()->addDays(30),
        ]);
        Workspace::query()->where('tenant_id', $tenant->id)->update(['status' => 'suspended']);
        app(WorkspaceSessionManager::class)->endForTenant($tenant->id);
        $this->audit->central('tenant.deletion_scheduled', 'tenant', $tenant->id, null, $data);

        return back()->with('success', 'Deletion scheduled. Data is retained for 30 days.');
    }

    public function matrix(): Response
    {
        return Inertia::render('Platform/Features', [
            'features' => Feature::query()->orderBy('key')->get(),
            'tenants' => Tenant::query()->get(['id', 'name', 'slug', 'status']),
            'overrides' => TenantFeature::query()->get(),
        ]);
    }

    public function templates(): Response
    {
        $stored = IndustryTemplate::query()->get()->keyBy('key');

        return Inertia::render('Platform/Templates', [
            'terms' => IndustryTerms::rows(),
            'templates' => collect(IndustryCatalog::all())->map(function (array $payload, string $key) use ($stored) {
                $row = $stored->get($key);
                $custom = is_array($row?->payload) && ($row->payload['vocabulary_customized'] ?? false) === true;

                return [
                    'key' => $key,
                    'name' => $custom ? $row->name : $payload['name'],
                    'vocabulary' => IndustryTerms::normalize($custom ? ($row->payload['vocabulary'] ?? []) : ($payload['vocabulary'] ?? [])),
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ]);
    }

    public function updateTemplate(Request $request, string $template): RedirectResponse
    {
        abort_unless(isset(IndustryCatalog::all()[$template]), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'vocabulary' => ['required', 'array'],
            'vocabulary.en' => ['required', 'array'],
            'vocabulary.sw' => ['required', 'array'],
        ]);
        foreach (['en', 'sw'] as $lang) {
            foreach ($data['vocabulary'][$lang] as $term => $label) {
                if (! in_array($term, IndustryTerms::KEYS, true) || ! is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > 80) {
                    throw ValidationException::withMessages([
                        "vocabulary.{$lang}.{$term}" => 'Enter a label of 80 characters or fewer for this word.',
                    ]);
                }
            }
        }
        $payload = IndustryCatalog::find($template);
        $payload['name'] = $data['name'];
        $payload['vocabulary'] = IndustryTerms::normalize($data['vocabulary']);
        $payload['vocabulary_customized'] = true;
        $saved = IndustryTemplate::query()->updateOrCreate(
            ['key' => $template],
            ['name' => $data['name'], 'payload' => $payload],
        );
        app(IndustryVocabulary::class)->forget($template);
        $this->audit->central('template.updated', 'industry_template', $saved->id);

        return back()->with('success', 'Template saved.');
    }

    private function notifyTrial(Tenant $tenant): void
    {
        $owner = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $tenant->owner_email)])->first();
        if ($owner) {
            UserNotification::query()->create([
                'user_id' => $owner->id,
                'type' => 'workspace_trial',
                'title' => 'Three-day trial',
                'body' => WorkspaceApproval::trialMessage($tenant),
            ]);
        }
        $this->sendNotice(
            $tenant->owner_email,
            'Your workspace is ready for '.WorkspaceApproval::trialDays().' days',
            WorkspaceApproval::trialMessage($tenant),
        );
        $this->sendNotice(
            WorkspaceApproval::email(),
            'New workspace signup: '.$tenant->name,
            WorkspaceApproval::signupAlert($tenant),
        );
    }

    private function notifyApproved(Tenant $tenant): void
    {
        $this->inside($tenant, function () use ($tenant): void {
            $owner = User::query()->whereRaw('lower(email) = ?', [strtolower((string) $tenant->owner_email)])->first()
                ?? User::query()->orderBy('created_at')->first();
            if (! $owner) {
                return;
            }
            UserNotification::query()->create([
                'user_id' => $owner->id,
                'type' => 'workspace_approved',
                'title' => 'Workspace approved',
                'body' => WorkspaceApproval::approvedMessage($tenant->name),
            ]);
        });
        $this->sendNotice(
            $tenant->owner_email,
            $tenant->name.' is approved',
            WorkspaceApproval::approvedMessage($tenant->name),
        );
    }

    private function sendNotice(?string $to, string $subject, string $notice): void
    {
        if (! is_string($to) || $to === '') {
            return;
        }

        try {
            Mail::to($to)->send(new WorkspaceApprovalNotice($subject, $notice));
        } catch (\Throwable $exception) {
            Log::warning('Workspace notice could not be sent.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function storeVocabulary(Tenant $tenant, string $key): void
    {
        $vocabulary = app(IndustryVocabulary::class);
        $vocabulary->forget($key);
        $this->inside($tenant, fn () => Setting::putValue('vocabulary', $vocabulary->forIndustry($key)));
    }

    /**
     * Count rows in a tenant schema. A missing schema or table yields null so
     * one incomplete workspace cannot take down the platform list.
     */
    private function countInside(Tenant $tenant, string $table): ?int
    {
        if (! $this->tenantHasTable($tenant, $table)) {
            return null;
        }

        return (int) DB::connection('central')->scalar('select count(*) from '.$tenant->schema_name.'.'.$table);
    }

    private function tenantHasTable(Tenant $tenant, string $table): bool
    {
        $schema = (string) $tenant->schema_name;
        if (! preg_match('/^[a-z][a-z0-9_]+$/', $schema) || ! preg_match('/^[a-z][a-z0-9_]+$/', $table)) {
            Log::warning('Workspace schema name cannot be queried.', [
                'tenant_id' => $tenant->id,
                'schema' => $schema,
                'table' => $table,
            ]);

            return false;
        }

        $present = (int) DB::connection('central')->scalar(
            'select count(*) from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, $table],
        );

        if ($present !== 1) {
            Log::warning('Workspace schema is missing a table.', [
                'tenant_id' => $tenant->id,
                'schema' => $schema,
                'table' => $table,
            ]);

            return false;
        }

        return true;
    }

    private function inside(Tenant $tenant, \Closure $callback): mixed
    {
        $this->tenancy->initialize($tenant);
        try {
            return $callback();
        } finally {
            $this->tenancy->end();
        }
    }
}
