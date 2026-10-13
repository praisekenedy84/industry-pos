<?php

namespace App\Auth;

use App\Models\DeviceSession;
use App\Models\Identity;
use App\Models\WorkspaceSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceSessionManager
{
    public function __construct(private SessionVault $vault) {}

    public function fromRequest(Request $request): ?array
    {
        $token = $request->cookie($this->cookieName($request));
        if (! is_string($token) || $token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $payload = $this->vault->get($this->key($hash));
        if (! $payload || ! empty($payload['revoked_at'])) {
            return null;
        }
        if (($payload['expires_at'] ?? 0) < time()) {
            $this->forgetPayload($payload);

            return null;
        }

        return $payload;
    }

    public function issue(Request $request, Identity $identity, bool $remember, bool $twoFactorOk = false): array
    {
        $this->rotateCookie($request, [
            'identity_id' => $identity->id,
            'remember' => $remember,
            'two_factor_ok' => $twoFactorOk,
            'created_at' => time(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'device' => $this->deviceLabel($request),
            'location' => $request->ip(),
        ]);
        $identity->forceFill(['last_login_at' => now()])->save();
        Log::info('device_session.issued', ['identity_id' => $identity->id]);

        return $this->fromRequest($request) ?? [];
    }

    /**
     * FX-7: a new opaque id is issued and every workspace session is re-keyed.
     */
    public function regenerate(Request $request, array $current): array
    {
        $previousId = $current['id'] ?? null;
        $previousHash = $current['token_hash'] ?? null;
        $payload = $current;
        unset($payload['id'], $payload['token_hash']);
        $payload['created_at'] = time();
        $this->rotateCookie($request, $payload);
        $fresh = $this->fromRequest($request);
        if ($previousId && $fresh) {
            $this->rekeyWorkspaces($previousId, $fresh['id']);
        }
        if ($previousHash) {
            $this->vault->forget($this->key($previousHash));
            DeviceSession::query()->where('token_hash', $previousHash)->update(['revoked_at' => now()]);
        }
        Log::info('device_session.regenerated', ['identity_id' => $fresh['identity_id'] ?? null]);

        return $fresh ?? [];
    }

    public function touch(array $payload): void
    {
        $payload['last_active_at'] = time();
        $ttl = max(60, ($payload['expires_at'] ?? time()) - time());
        $this->vault->put($this->key($payload['token_hash']), $payload, $ttl);
        DeviceSession::query()->where('id', $payload['id'])->update(['last_active_at' => now()]);
    }

    public function markTwoFactor(array $payload): array
    {
        $payload['two_factor_ok'] = true;
        $this->touch($payload);

        return $payload;
    }

    public function revoke(DeviceSession $session): void
    {
        $this->vault->forget($this->key($session->token_hash));
        $session->update(['revoked_at' => now()]);
        $workspaces = WorkspaceSession::query()->where('device_session_id', $session->id)->whereNull('revoked_at')->get();
        foreach ($workspaces as $workspace) {
            $this->vault->forget('ws:'.$workspace->tenant_id.':'.$session->id);
            $workspace->update(['revoked_at' => now(), 'status' => 'revoked']);
        }
        Log::info('device_session.revoked', ['identity_id' => $session->identity_id, 'device_session_id' => $session->id]);
    }

    public function revokeIdentity(string $identityId): void
    {
        DeviceSession::query()->where('identity_id', $identityId)->whereNull('revoked_at')->get()
            ->each(fn (DeviceSession $session) => $this->revoke($session));
        Log::info('device_session.revoked_all', ['identity_id' => $identityId]);
    }

    public function cookieName(Request $request): string
    {
        return $request->isSecure() ? '__Host-ds' : 'ds';
    }

    private function rotateCookie(Request $request, array $attributes): void
    {
        $token = Str::random(64);
        $hash = hash('sha256', $token);
        $remember = (bool) ($attributes['remember'] ?? false);
        $ttl = $remember ? 60 * 60 * 24 * 30 : 60 * 60 * 12;
        $id = (string) Str::uuid();
        $payload = array_merge($attributes, [
            'id' => $id,
            'token_hash' => $hash,
            'expires_at' => time() + $ttl,
            'last_active_at' => time(),
        ]);
        $this->vault->put($this->key($hash), $payload, $ttl);
        DeviceSession::query()->forceCreate([
            'id' => $id,
            'token_hash' => $hash,
            'identity_id' => $payload['identity_id'],
            'remember' => $remember,
            'two_factor_ok' => (bool) ($payload['two_factor_ok'] ?? false),
            'ip' => $payload['ip'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
            'device' => $payload['device'] ?? null,
            'location' => $payload['location'] ?? null,
            'last_active_at' => now(),
            'expires_at' => now()->addSeconds($ttl),
        ]);
        cookie()->queue(cookie(
            $this->cookieName($request),
            $token,
            (int) ceil($ttl / 60),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
        $request->cookies->set($this->cookieName($request), $token);
    }

    private function rekeyWorkspaces(string $fromId, string $toId): void
    {
        $rows = WorkspaceSession::query()->where('device_session_id', $fromId)->whereNull('revoked_at')->get();
        foreach ($rows as $row) {
            $oldKey = 'ws:'.$row->tenant_id.':'.$fromId;
            $payload = $this->vault->get($oldKey);
            if ($payload) {
                $payload['device_session_id'] = $toId;
                $ttl = max(60, ($payload['absolute_expires_at'] ?? time()) - time());
                $this->vault->put('ws:'.$row->tenant_id.':'.$toId, $payload, $ttl);
                $this->vault->forget($oldKey);
            }
            $row->update(['device_session_id' => $toId]);
        }
    }

    private function forgetPayload(array $payload): void
    {
        if (! empty($payload['token_hash'])) {
            $this->vault->forget($this->key($payload['token_hash']));
        }
        if (! empty($payload['id'])) {
            DeviceSession::query()->where('id', $payload['id'])->update(['revoked_at' => now()]);
        }
    }

    private function key(string $hash): string
    {
        return 'ds:'.$hash;
    }

    private function deviceLabel(Request $request): string
    {
        $agent = (string) $request->userAgent();
        $browser = str_contains($agent, 'Edg') ? 'Edge' : (str_contains($agent, 'Chrome') ? 'Chrome' : (str_contains($agent, 'Firefox') ? 'Firefox' : 'Browser'));
        $os = str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Mac') ? 'macOS' : (str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'iPhone') ? 'iOS' : 'device')));

        return $browser.' on '.$os;
    }
}
