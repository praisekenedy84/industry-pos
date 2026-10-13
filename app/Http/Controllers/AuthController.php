<?php

namespace App\Http\Controllers;

use App\Auth\ConcurrentSessionException;
use App\Auth\DeviceSessionManager;
use App\Auth\IdentityAuthenticator;
use App\Auth\WorkspaceSessionManager;
use App\Models\DeviceSession;
use App\Models\Identity;
use App\Models\ImpersonationLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Terminal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSession;
use App\Services\AuditLogger;
use App\Support\Paths;
use App\Support\Totp;
use App\Tenancy\TenancyManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    private const GENERIC = 'Email or password is incorrect.';

    public function __construct(
        private AuditLogger $audit,
        private DeviceSessionManager $devices,
        private WorkspaceSessionManager $sessions,
        private IdentityAuthenticator $identities,
    ) {}

    public function loginForm(): Response
    {
        return Inertia::render('Auth/Login', [
            'action' => '/login',
            'workspace' => null,
        ]);
    }

    public function workspaceLoginForm(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Auth/Login', [
            'action' => Paths::to('/login'),
            'workspace' => $workspace ? [
                'name' => $workspace->display_name,
                'slug' => $workspace->slug,
                'logo' => $workspace->logo_path,
            ] : null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        return $this->authenticate($request, null);
    }

    public function workspaceLogin(Request $request): RedirectResponse
    {
        return $this->authenticate($request, $request->attributes->get('workspace')?->slug);
    }

    public function twoFactorForm(Request $request): Response
    {
        abort_unless($request->session()->get('pending_2fa_identity'), 404);

        return Inertia::render('Auth/TwoFactor', [
            'action' => $request->attributes->get('workspace') ? Paths::to('/two-factor') : '/two-factor',
        ]);
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $identity = Identity::query()->find($request->session()->get('pending_2fa_identity'));
        abort_unless($identity, 404);
        $recovery = collect($identity->two_factor_recovery_codes ?: []);
        $usedRecovery = $recovery->contains(fn ($hash) => Hash::check($data['code'], $hash));
        if (! Totp::verify((string) $identity->two_factor_secret, $data['code']) && ! $usedRecovery) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        if ($usedRecovery) {
            $identity->two_factor_recovery_codes = $recovery->reject(fn ($hash) => Hash::check($data['code'], $hash))->values()->all();
            $identity->save();
        }
        $remember = (bool) $request->session()->pull('pending_2fa_remember');
        $slug = $request->session()->pull('pending_2fa_workspace');
        $force = (bool) $request->session()->pull('pending_2fa_force');
        $next = $request->session()->pull('pending_2fa_next');
        $request->session()->forget('pending_2fa_identity');
        $device = $this->devices->issue($request, $identity, $remember, true);
        $this->audit->central('auth.2fa', 'identity', $identity->id, null, ['identity_id' => $identity->id]);

        return $this->finishLogin($request, $identity, $device, $slug, $force, $next);
    }

    public function twoFactorSetupForm(Request $request): Response
    {
        $secret = $request->session()->get('pending_2fa_secret');
        $identity = Identity::query()->find($request->session()->get('pending_2fa_identity'));
        abort_unless(is_string($secret) && $secret !== '' && $identity, 404);
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Auth/TwoFactorSetup', [
            'secret' => $secret,
            'uri' => Totp::provisioningUri($identity->email ?: ($identity->phone ?: 'user'), $secret),
            'action' => $workspace ? Paths::to('/two-factor/setup') : '/two-factor/setup',
        ]);
    }

    public function twoFactorSetup(Request $request): Response
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $identity = Identity::query()->find($request->session()->get('pending_2fa_identity'));
        $secret = (string) $request->session()->get('pending_2fa_secret');
        abort_unless($identity && $secret !== '', 404);
        if (! Totp::verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $codes = Totp::recoveryCodes();
        $identity->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => collect($codes)->map(fn ($code) => Hash::make($code))->all(),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('pending_2fa_secret');
        $request->session()->put('pending_2fa_ready', true);
        $workspace = $request->attributes->get('workspace');
        $this->audit->central('auth.2fa_enrolled', 'identity', $identity->id, null, ['identity_id' => $identity->id]);

        return Inertia::render('Auth/RecoveryCodes', [
            'codes' => $codes,
            'action' => $workspace ? Paths::to('/two-factor/continue') : '/two-factor/continue',
        ]);
    }

    public function twoFactorContinue(Request $request): RedirectResponse
    {
        abort_unless($request->session()->pull('pending_2fa_ready'), 404);
        $identity = Identity::query()->find($request->session()->pull('pending_2fa_identity'));
        abort_unless($identity && $identity->two_factor_confirmed_at, 404);
        $remember = (bool) $request->session()->pull('pending_2fa_remember');
        $slug = $request->session()->pull('pending_2fa_workspace');
        $force = (bool) $request->session()->pull('pending_2fa_force');
        $next = $request->session()->pull('pending_2fa_next');
        $device = $this->devices->issue($request, $identity, $remember, true);

        return $this->finishLogin($request, $identity, $device, $slug, $force, $next);
    }

    public function picker(Request $request): Response
    {
        $identity = $this->requireIdentity($request);
        $choices = $this->choices($identity);

        return Inertia::render('Auth/PickWorkspace', ['workspaces' => $choices]);
    }

    public function pick(Request $request): RedirectResponse
    {
        $identity = $this->requireIdentity($request);
        $device = $request->attributes->get('device');
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'remember' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);
        $membership = Membership::query()
            ->where('identity_id', $identity->id)
            ->where('tenant_id', $data['tenant_id'])
            ->where('status', 'active')
            ->firstOrFail();
        $workspace = Workspace::query()->where('tenant_id', $membership->tenant_id)->firstOrFail();
        if ($request->boolean('remember')) {
            $identity->forceFill(['preferred_tenant_id' => $workspace->tenant_id])->save();
        }
        $device = $this->devices->regenerate($request, $device);

        return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, $request->boolean('force'), null);
    }

    public function home(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $device = $request->attributes->get('device');
        $identity = $request->attributes->get('identity');
        if (! $workspace || ! $device || ! $identity) {
            return redirect()->to(Paths::login($workspace?->slug));
        }
        $membership = Membership::query()
            ->where('identity_id', $identity->id)
            ->where('tenant_id', $workspace->tenant_id)
            ->where('status', 'active')
            ->first();
        if (! $membership) {
            return redirect()->route('workspace.login', ['workspace' => $workspace->slug])
                ->withErrors(['email' => self::GENERIC]);
        }
        $existing = $this->sessions->current($device, $workspace->tenant_id);
        if ($existing && empty($existing['locked'])) {
            return redirect()->route($this->homeRoute($existing['role_slug'] ?? null), ['workspace' => $workspace->slug]);
        }
        if ($existing && ! empty($existing['locked'])) {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($workspace->slug))->with('status', __('auth.session_ended'));
        }
        if ($this->identities->requiresTwoFactor($identity, $membership) && empty($device['two_factor_ok'])) {
            $request->session()->put('pending_2fa_identity', $identity->id);
            $request->session()->put('pending_2fa_workspace', $workspace->slug);

            return redirect()->route('workspace.two-factor', ['workspace' => $workspace->slug]);
        }
        $device = $this->devices->regenerate($request, $device);

        return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, $request->boolean('force'), null);
    }

    public function unlockForm(Request $request): RedirectResponse
    {
        return $this->endWorkspaceAndLogin($request);
    }

    public function unlock(Request $request): RedirectResponse
    {
        return $this->endWorkspaceAndLogin($request);
    }

    private function endWorkspaceAndLogin(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $device = $request->attributes->get('device');
        if ($device && $workspace) {
            $this->sessions->endOne($device, $workspace->tenant_id);
        }

        return redirect()->to(Paths::login($workspace?->slug))->with('status', __('auth.session_ended'));
    }

    public function pin(Request $request): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $terminal = Terminal::query()->where('registered', true)->find($request->cookie('pos_terminal'));
        if (! $terminal) {
            throw ValidationException::withMessages(['pin' => 'PIN sign-in only works on a registered terminal.']);
        }
        $key = 'pin:'.$request->ip().'|'.$terminal->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'PIN entry is locked for a few minutes.']);
        }
        $match = null;
        foreach (User::query()->where('status', 'active')->whereNotNull('pin_hash')->get() as $user) {
            if (Hash::check($data['pin'], $user->pin_hash)) {
                $match = $user;
                break;
            }
        }
        if (! $match) {
            RateLimiter::hit($key, 900);
            $this->audit->tenant('auth.pin_failed', null, null, null, ['ip' => $request->ip(), 'tenant_id' => $request->attributes->get('workspace')?->tenant_id]);
            throw ValidationException::withMessages(['pin' => 'That PIN is not recognised on this terminal.']);
        }
        RateLimiter::clear($key);
        $device = $request->attributes->get('device');
        $payload = $request->attributes->get('workspaceSession');
        if ($device && $payload) {
            $this->sessions->merge($device, $payload['tenant_id'], [
                'user_id' => $match->id,
                'branch_id' => $match->branchRoles()->value('branch_id') ?: $payload['branch_id'],
            ]);
            Auth::guard('web')->setUser($match);
        }
        $this->audit->tenant('auth.pin', 'user', $match->id, null, ['identity_id' => $request->attributes->get('identity')?->id]);

        return redirect()->route('pos');
    }

    public function logout(Request $request): RedirectResponse
    {
        $scope = $request->input('scope', 'device');
        $device = $request->attributes->get('device');
        $identity = $request->attributes->get('identity');
        $workspace = $request->attributes->get('workspace');
        if ($identity) {
            $this->audit->central('auth.logout', 'identity', $identity->id, null, [
                'identity_id' => $identity->id,
                'tenant_id' => $workspace?->tenant_id,
                'scope' => $scope,
            ]);
        }
        if ($scope === 'workspace' && $device && $workspace) {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->route('workspace.login', ['workspace' => $workspace->slug]);
        }
        if ($scope === 'everywhere' && $identity) {
            $this->devices->revokeIdentity($identity->id);
        } elseif ($device) {
            $row = DeviceSession::query()->find($device['id']);
            if ($row) {
                $this->devices->revoke($row);
            }
        }
        cookie()->queue(cookie()->forget($this->devices->cookieName($request)));

        return redirect()->route('login');
    }

    public function inviteForm(string $token): Response
    {
        $invite = $this->invitation($token);
        $identity = $invite->email ? Identity::query()->where('email', strtolower($invite->email))->first() : null;

        return Inertia::render('Auth/Invite', [
            'email' => $invite->email,
            'name' => $invite->name,
            'token' => $token,
            'existing' => (bool) $identity,
        ]);
    }

    public function invite(Request $request, string $token): RedirectResponse
    {
        $invite = $this->invitation($token);
        $identity = $invite->email ? Identity::query()->where('email', strtolower($invite->email))->first() : null;
        if ($identity) {
            $current = $request->attributes->get('identity');
            if (! $current || $current->id !== $identity->id) {
                $request->session()->put('url.intended', '/invite/'.$token);

                return redirect()->route('login')->with('status', 'Sign in to accept this invitation.');
            }
        } else {
            $data = $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);
            $this->assertPassword($data['password']);
            $identity = Identity::query()->create([
                'email' => $invite->email ? strtolower($invite->email) : null,
                'phone' => $invite->phone,
                'password' => $data['password'],
                'status' => 'active',
            ]);
        }
        $workspace = Workspace::query()->where('tenant_id', $invite->tenant_id)->firstOrFail();
        $this->tenancy()->initialize($workspace->tenant);
        $user = User::query()->find($invite->tenant_user_id);
        if ($user) {
            $user->forceFill(['status' => 'active', 'joined_at' => now(), 'invite_token' => null])->save();
        }
        $membership = Membership::query()->updateOrCreate(
            ['identity_id' => $identity->id, 'tenant_id' => $invite->tenant_id],
            ['tenant_user_id' => $invite->tenant_user_id, 'status' => 'active'],
        );
        if ($user) {
            $user->forceFill(['membership_id' => $membership->id])->save();
        }
        $invite->forceFill(['accepted_at' => now()])->save();
        $this->tenancy()->end();
        $device = $request->attributes->get('device') ?: $this->devices->issue($request, $identity, false, false);
        $device = $this->devices->regenerate($request, $device);

        return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, false, null);
    }

    public function forgotForm(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string']]);
        [$column, $value] = $this->identities->normalize($data['email']);
        $identity = Identity::query()->where($column, $value)->first();
        if ($identity && $identity->email) {
            $plain = Str::random(48);
            DB::connection('central')->table('identity_password_resets')->updateOrInsert(
                ['identifier' => $identity->email],
                ['token_hash' => hash('sha256', $plain), 'created_at' => now()],
            );
            $link = url('/reset-password/'.$plain);
            try {
                Mail::raw('Reset your Makerly password: '.$link, fn ($message) => $message->to($identity->email)->subject('Reset your Makerly password'));
            } catch (\Throwable) {
                Log::info('auth.password_reset_link', ['identity_id' => $identity->id]);
            }
        }

        return back()->with('status', 'If an account exists, a reset link is on its way.');
    }

    public function resetForm(string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token]);
    }

    public function reset(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);
        $this->assertPassword($data['password']);
        $row = DB::connection('central')->table('identity_password_resets')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $identity = $row ? Identity::query()->where('email', $row->identifier)->first() : null;
        if (! $identity || ! $row->created_at || now()->parse($row->created_at)->lt(now()->subHour())) {
            throw ValidationException::withMessages(['password' => 'This reset link is not valid.']);
        }
        $identity->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $this->devices->revokeIdentity($identity->id);
        DB::connection('central')->table('identity_password_resets')->where('identifier', $identity->email)->delete();
        $this->audit->central('auth.password_reset', 'identity', $identity->id, null, ['identity_id' => $identity->id]);

        return redirect()->route('login')->with('status', 'Password updated. Sign in again.');
    }

    public function passwordForm(): Response
    {
        return Inertia::render('Auth/Password', ['action' => Paths::to('/password')]);
    }

    public function password(Request $request): RedirectResponse
    {
        $identity = $this->requireIdentity($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:10', 'confirmed']]);
        $this->assertPassword($data['password']);
        $identity->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        $this->devices->revokeIdentity($identity->id);
        cookie()->queue(cookie()->forget($this->devices->cookieName($request)));
        $this->audit->central('auth.password_changed', 'identity', $identity->id, null, ['identity_id' => $identity->id]);

        return redirect()->route('login')->with('status', 'Password updated. Sign in again.');
    }

    public function sessions(Request $request): Response
    {
        $identity = $this->requireIdentity($request);
        $rows = DeviceSession::query()
            ->where('identity_id', $identity->id)
            ->whereNull('revoked_at')
            ->latest('last_active_at')
            ->get()
            ->map(function (DeviceSession $session) {
                return [
                    'id' => $session->id,
                    'device' => $session->device,
                    'location' => $session->location,
                    'ip' => $session->ip,
                    'last_active_at' => $session->last_active_at,
                    'current' => $session->id === request()->attributes->get('device')['id'],
                    'workspaces' => WorkspaceSession::query()
                        ->where('device_session_id', $session->id)
                        ->whereNull('revoked_at')
                        ->get()
                        ->map(fn ($row) => Workspace::query()->where('tenant_id', $row->tenant_id)->value('display_name'))
                        ->filter()
                        ->values(),
                ];
            });

        return Inertia::render('Settings/Sessions', ['sessions' => $rows]);
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $identity = $this->requireIdentity($request);
        $row = DeviceSession::query()->where('identity_id', $identity->id)->findOrFail($session);
        $this->devices->revoke($row);

        return back()->with('success', 'Session revoked.');
    }

    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,sw,pseudo']]);
        if ($data['locale'] === 'pseudo' && ! config('app.debug')) {
            $data['locale'] = 'en';
        }
        $request->session()->put('locale', $data['locale']);
        $identity = $request->attributes->get('identity');
        if ($identity && $data['locale'] !== 'pseudo') {
            $identity->update(['locale' => $data['locale']]);
        }
        if ($request->user() && $data['locale'] !== 'pseudo') {
            $request->user()->update(['locale' => $data['locale']]);
        }

        return back();
    }

    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['required', 'in:light,dark,system']]);
        cookie()->queue(cookie('theme', $data['theme'], 60 * 24 * 365, '/', null, false, false));
        $identity = $request->attributes->get('identity');
        if ($identity) {
            $identity->update(['theme' => $data['theme']]);
        }
        if ($request->user()) {
            $request->user()->update(['theme' => $data['theme']]);
        }

        return back();
    }

    public function branch(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'uuid']]);
        $allowed = $request->user()->branchRoles()->pluck('branch_id');
        abort_unless($allowed->contains($data['branch_id']), 403);
        $device = $request->attributes->get('device');
        $workspace = $request->attributes->get('workspace');
        if ($device && $workspace) {
            $this->sessions->merge($device, $workspace->tenant_id, ['branch_id' => $data['branch_id']]);
        }
        $request->session()->put('branch_id', $data['branch_id']);

        return back();
    }

    public function reauth(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $identity = $request->attributes->get('identity');
        $hash = $identity?->password ?: (string) $request->user()->password;
        if (! Hash::check($data['password'], $hash)) {
            throw ValidationException::withMessages(['password' => 'Password does not match.']);
        }
        $device = $request->attributes->get('device');
        $workspace = $request->attributes->get('workspace');
        if ($device && $workspace) {
            $this->sessions->merge($device, $workspace->tenant_id, ['reauth_at' => time()]);
        }
        $request->session()->put('reauth_at', time());

        return back();
    }

    public function leaveImpersonation(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $logId = $request->session()->get('impersonation_log_id');
        if ($logId) {
            ImpersonationLog::query()->where('id', $logId)->update(['ended_at' => now()]);
            $this->audit->tenant('impersonation.ended', 'impersonation', $logId);
        }
        $device = $request->attributes->get('device');
        if ($device) {
            $row = DeviceSession::query()->find($device['id']);
            if ($row) {
                $this->devices->revoke($row);
            }
        }
        cookie()->queue(cookie()->forget($this->devices->cookieName($request)));
        $request->session()->forget([
            'impersonation_log_id',
            'impersonator_id',
            'impersonation_expires_at',
        ]);

        return Inertia::location($this->platformReturnUrl($request));
    }

    private function platformReturnUrl(Request $request): string
    {
        $return = $request->session()->pull('impersonation_return');
        $path = is_string($return) ? parse_url($return, PHP_URL_PATH) : null;
        if (is_string($path) && preg_match('#^/(platform|admin)(/|$)#', $path) === 1) {
            return $return;
        }

        return route('platform.tenants');
    }

    private function authenticate(Request $request, ?string $workspaceSlug): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required_without:username', 'nullable', 'string', 'max:190'],
            'username' => ['nullable', 'string', 'max:64'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'remember_device' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'workspace_code' => ['nullable', 'string', 'max:32'],
            'next' => ['nullable', 'string'],
        ]);
        $aliasSlug = $workspaceSlug;
        $identifier = $data['email'] ?? '';
        if (! empty($data['username'])) {
            $aliasSlug = $workspaceSlug ?: ($data['workspace_code'] ?? null);
            $identifier = $data['username'];
            if (! $aliasSlug) {
                throw ValidationException::withMessages(['email' => self::GENERIC]);
            }
        }
        $throttleKey = 'login:'.strtolower($identifier).'|'.$request->ip();
        $sprayKey = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($sprayKey, 30)) {
            if (RateLimiter::tooManyAttempts($sprayKey, 30)) {
                Log::warning('auth.login_spray', ['ip' => $request->ip()]);
            }
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $identity = $this->identities->verify(
            $identifier,
            $data['password'],
            ! empty($data['username']) ? $aliasSlug : null,
        );
        $scoped = null;
        if ($identity && $workspaceSlug) {
            $scoped = Membership::query()
                ->where('identity_id', $identity->id)
                ->where('status', 'active')
                ->whereIn('tenant_id', Workspace::query()->where('slug', $workspaceSlug)->pluck('tenant_id'))
                ->first();
            if (! $scoped) {
                $identity = null;
            }
        }
        if (! $identity) {
            RateLimiter::hit($throttleKey, 900);
            RateLimiter::hit($sprayKey, 900);
            $this->identities->noteFailure($request->ip());
            $this->audit->central('auth.failed', 'identity', null, null, ['identifier' => $identifier]);
            throw ValidationException::withMessages(['email' => self::GENERIC]);
        }
        RateLimiter::clear($throttleKey);
        if ($identity->must_change_password && empty($data['username']) === false) {
            // Temporary passwords are replaced after the workspace session opens.
        }
        if ($this->identities->requiresTwoFactor($identity, $scoped) && ! $identity->two_factor_confirmed_at) {
            $request->session()->put('pending_2fa_identity', $identity->id);
            $request->session()->put('pending_2fa_secret', Totp::secret());
            $request->session()->put('pending_2fa_remember', $request->boolean('remember') || $request->boolean('remember_device'));
            $request->session()->put('pending_2fa_workspace', $workspaceSlug);
            $request->session()->put('pending_2fa_force', $request->boolean('force'));
            $request->session()->put('pending_2fa_next', $data['next'] ?? null);

            return redirect()->route($workspaceSlug ? 'workspace.two-factor-setup' : 'two-factor.setup', array_filter([
                'workspace' => $workspaceSlug,
            ]))->with('status', 'Set up an authenticator to continue.');
        }
        if ($this->identities->requiresTwoFactor($identity, $scoped)) {
            $request->session()->put('pending_2fa_identity', $identity->id);
            $request->session()->put('pending_2fa_remember', $request->boolean('remember') || $request->boolean('remember_device'));
            $request->session()->put('pending_2fa_workspace', $workspaceSlug);
            $request->session()->put('pending_2fa_force', $request->boolean('force'));
            $request->session()->put('pending_2fa_next', $data['next'] ?? null);

            return redirect()->route($workspaceSlug ? 'workspace.two-factor' : 'two-factor', array_filter([
                'workspace' => $workspaceSlug,
            ]));
        }

        $device = $this->devices->issue(
            $request,
            $identity,
            $request->boolean('remember') || $request->boolean('remember_device'),
            false,
        );
        $request->attributes->set('device', $device);
        $request->attributes->set('identity', $identity);

        return $this->finishLogin($request, $identity, $device, $workspaceSlug, $request->boolean('force'), $data['next'] ?? null);
    }

    private function finishLogin(Request $request, Identity $identity, array $device, ?string $workspaceSlug, bool $force, ?string $next): RedirectResponse
    {
        $memberships = Membership::query()->where('identity_id', $identity->id)->where('status', 'active')->get();
        if ($workspaceSlug) {
            $workspace = Workspace::query()->where('slug', $workspaceSlug)->first();
            $membership = $memberships->firstWhere('tenant_id', $workspace?->tenant_id);
            if (! $workspace || ! $membership) {
                throw ValidationException::withMessages(['email' => self::GENERIC]);
            }
            $device = $this->devices->regenerate($request, $device);

            return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, $force, $next);
        }
        if ($memberships->isEmpty()) {
            $this->devices->revokeIdentity($identity->id);

            return back()->withErrors(['email' => 'This account has no active workspace. Contact your administrator.']);
        }
        if ($identity->preferred_tenant_id && $memberships->contains('tenant_id', $identity->preferred_tenant_id)) {
            $membership = $memberships->firstWhere('tenant_id', $identity->preferred_tenant_id);
            $workspace = Workspace::query()->where('tenant_id', $membership->tenant_id)->first();
            if ($workspace && $workspace->status === 'active') {
                $device = $this->devices->regenerate($request, $device);

                return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, $force, $next);
            }
        }
        if ($memberships->count() === 1) {
            $membership = $memberships->first();
            $workspace = Workspace::query()->where('tenant_id', $membership->tenant_id)->firstOrFail();
            $device = $this->devices->regenerate($request, $device);

            return $this->enterWorkspace($request, $identity, $device, $workspace, $membership, $force, $next);
        }

        return redirect()->route('workspaces');
    }

    private function enterWorkspace(Request $request, Identity $identity, array $device, Workspace $workspace, Membership $membership, bool $force, ?string $next): RedirectResponse
    {
        try {
            $payload = $this->sessions->open($request, $device, $identity, $workspace, $membership, $force);
        } catch (ConcurrentSessionException) {
            throw ValidationException::withMessages([
                'email' => 'Already signed in on another device.',
                'concurrent' => 'Sign out the other device and continue.',
            ]);
        }
        $this->audit->central('auth.login', 'identity', $identity->id, null, [
            'identity_id' => $identity->id,
            'tenant_id' => $workspace->tenant_id,
        ]);
        $safe = Paths::safeNext($next, $workspace->slug);
        if ($safe) {
            return redirect()->to($safe);
        }
        if ($identity->must_change_password) {
            return redirect()->route('workspace.password', ['workspace' => $workspace->slug]);
        }

        return redirect()->route($this->homeRoute($payload['role_slug'] ?? null), ['workspace' => $workspace->slug]);
    }

    private function homeRoute(?string $role): string
    {
        return $role === 'cashier' ? 'pos' : 'dashboard';
    }

    private function choices(Identity $identity): array
    {
        return collect($this->identities->activeMemberships($identity))->map(fn ($row) => [
            'tenant_id' => $row['workspace']->tenant_id,
            'slug' => $row['workspace']->slug,
            'name' => $row['workspace']->display_name,
            'logo' => $row['workspace']->logo_path,
            'role' => $row['role'],
            'last_used_at' => $row['membership']->last_used_at,
        ])->all();
    }

    private function requireIdentity(Request $request): Identity
    {
        $identity = $request->attributes->get('identity');
        abort_unless($identity instanceof Identity, 403);

        return $identity;
    }

    private function invitation(string $token): Invitation
    {
        return Invitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }

    private function assertPassword(string $password): void
    {
        $common = ['password123', '1234567890', 'qwertyuiop', 'makerly1234', 'changeme123'];
        if (in_array(strtolower($password), $common, true)) {
            throw ValidationException::withMessages(['password' => 'Choose a password that is not on the common-password list.']);
        }
    }

    private function tenancy(): TenancyManager
    {
        return app(TenancyManager::class);
    }
}
