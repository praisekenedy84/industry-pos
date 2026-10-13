<?php

namespace App\Http\Middleware;

use App\Auth\WorkspaceSessionManager;
use App\Models\Membership;
use App\Models\PosDraft;
use App\Models\Setting;
use App\Models\User;
use App\Support\Paths;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceSession
{
    public function __construct(private WorkspaceSessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->attributes->get('workspace');
        $device = $request->attributes->get('device');
        $identity = $request->attributes->get('identity');
        $slug = $workspace?->slug;
        if (! $device || ! $identity || ! $workspace) {
            return redirect()->to(Paths::login($slug));
        }

        $membership = Membership::query()
            ->where('identity_id', $identity->id)
            ->where('tenant_id', $workspace->tenant_id)
            ->first();
        if (! $membership || ! $membership->isActive()) {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug))
                ->with('error', 'Your access to this workspace has ended. Contact your administrator.');
        }

        $payload = $this->sessions->current($device, $workspace->tenant_id);
        if (! $payload) {
            return redirect()->to(Paths::login($slug));
        }

        if ($identity->must_change_password && ! $request->routeIs('workspace.password', 'workspace.password.update')) {
            return redirect()->route('workspace.password', ['workspace' => $slug]);
        }

        $user = User::query()->find($payload['user_id']);
        if (! $user || $user->status !== 'active') {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug))
                ->with('error', 'Your access to this workspace has ended. Contact your administrator.');
        }

        if ((int) ($payload['permission_version'] ?? 0) !== (int) $user->permission_version) {
            $this->sessions->regenerateForUser($workspace->tenant_id, $user->id, (int) $user->permission_version);
            $payload = $this->sessions->current($device, $workspace->tenant_id) ?? $payload;
            $payload['permission_version'] = (int) $user->permission_version;
        }

        $impersonationExpiry = $payload['impersonation_expires_at'] ?? null;
        if ($impersonationExpiry && now()->greaterThan($impersonationExpiry)) {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug));
        }

        if (($payload['absolute_expires_at'] ?? 0) < time()) {
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug));
        }

        $onPos = $request->is('w/'.$slug.'/pos*');
        $idleMinutes = (int) ($onPos
            ? Setting::getValue('pos_idle_timeout', config('makerly.pos_idle_timeout'))
            : ($payload['idle_minutes'] ?? Setting::getValue('idle_timeout', config('makerly.idle_timeout'))));
        $idleMinutes = max(5, min(480, $idleMinutes));
        $last = (int) ($payload['last_activity'] ?? 0);
        if ($last && (time() - $last) >= ($idleMinutes * 60)) {
            $this->holdCart($request, $payload);
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug))->with('status', __('auth.session_ended'));
        }

        if (! empty($payload['locked'])) {
            $this->holdCart($request, $payload);
            $this->sessions->endOne($device, $workspace->tenant_id);

            return redirect()->to(Paths::login($slug))->with('status', __('auth.session_ended'));
        }

        $payload = $this->sessions->touch($payload);
        $this->hydrate($request, $payload);
        Auth::guard('web')->setUser($user);
        $request->attributes->set('workspaceSession', $payload);

        return $next($request);
    }

    private function hydrate(Request $request, array $payload): void
    {
        $request->session()->put('branch_id', $payload['branch_id'] ?? null);
        $request->session()->put('pos_cart', $payload['cart'] ?? null);
        $request->session()->put('impersonation_log_id', $payload['impersonation_log_id'] ?? null);
        $request->session()->put('impersonator_id', $payload['impersonator_id'] ?? null);
        $request->session()->put('impersonation_expires_at', $payload['impersonation_expires_at'] ?? null);
        $request->session()->put('permission_version', $payload['permission_version'] ?? null);
        $request->session()->put('reauth_at', $payload['reauth_at'] ?? null);
    }

    private function holdCart(Request $request, array $payload): void
    {
        $cart = $payload['cart'] ?? null;
        if (! $cart) {
            return;
        }
        PosDraft::query()->updateOrCreate(
            ['terminal_key' => $request->cookie('pos_terminal'), 'name' => 'idle'],
            ['branch_id' => $payload['branch_id'] ?? null, 'user_id' => $payload['user_id'] ?? null, 'cart' => $cart],
        );
    }
}
