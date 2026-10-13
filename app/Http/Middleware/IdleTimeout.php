<?php

namespace App\Http\Middleware;

use App\Models\ActiveSession;
use App\Models\PosDraft;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        $known = ActiveSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $request->session()->getId())
            ->exists();
        if (! $known && ! $request->session()->get('impersonation_log_id')) {
            Auth::logout();

            return redirect()->route('login')->with('error', __('auth.session_ended'));
        }

        $loginAt = (int) $request->session()->get('login_at_ts', 0);
        $absoluteHours = (int) Setting::getValue('absolute_session_hours', config('makerly.absolute_session_hours'));
        if ($loginAt && (time() - $loginAt) > ($absoluteHours * 3600)) {
            return $this->expire($request);
        }

        $impersonationExpiry = $request->session()->get('impersonation_expires_at');
        if ($impersonationExpiry && now()->greaterThan($impersonationExpiry)) {
            return $this->expire($request);
        }

        $last = (int) $request->session()->get('last_activity_ts', 0);
        $onPos = $request->is('pos*');
        $idle = (int) ($onPos
            ? Setting::getValue('pos_idle_timeout', config('makerly.pos_idle_timeout'))
            : Setting::getValue('idle_timeout', config('makerly.idle_timeout')));
        if ($request->session()->get('remember_device')) {
            $idle = max($idle, $absoluteHours * 60);
        }
        if ($last && (time() - $last) >= ($idle * 60)) {
            return $this->expire($request);
        }

        $request->session()->put('last_activity_ts', time());
        if ($known) {
            ActiveSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $request->session()->getId())
                ->update(['last_active_at' => now()]);
        }

        $version = $request->session()->get('permission_version');
        if ($version !== null && (int) $version !== (int) $user->permission_version) {
            $previous = $request->session()->getId();
            $request->session()->regenerate();
            $request->session()->put('permission_version', $user->permission_version);
            ActiveSession::query()->where('user_id', $user->id)->where('session_id', $previous)->delete();
            ActiveSession::query()->create([
                'user_id' => $user->id,
                'session_id' => $request->session()->getId(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_active_at' => now(),
            ]);
        }

        return $next($request);
    }

    private function expire(Request $request): Response
    {
        $cart = $request->session()->get('pos_cart');
        if ($cart) {
            PosDraft::query()->updateOrCreate(
                ['terminal_key' => $request->cookie('pos_terminal'), 'name' => 'idle'],
                ['branch_id' => $request->session()->get('branch_id'), 'user_id' => $request->user()?->id, 'cart' => $cart],
            );
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('auth.session_ended'));
    }
}
