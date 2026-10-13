<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SessionVault
{
    public function get(string $key): ?array
    {
        $raw = $this->redisGet($key);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        $cached = Cache::get($key);

        return is_array($cached) ? $cached : null;
    }

    public function put(string $key, array $value, int $seconds): void
    {
        $seconds = max(1, $seconds);
        if ($this->redisPut($key, json_encode($value), $seconds)) {
            return;
        }
        Cache::put($key, $value, $seconds);
    }

    public function forget(string $key): void
    {
        $this->redisForget($key);
        Cache::forget($key);
    }

    private function redisGet(string $key): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }
        try {
            $value = Redis::connection('sessions')->get($key);

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function redisPut(string $key, string $value, int $seconds): bool
    {
        if (app()->environment('testing')) {
            return false;
        }
        try {
            Redis::connection('sessions')->setex($key, $seconds, $value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisForget(string $key): void
    {
        if (app()->environment('testing')) {
            return;
        }
        try {
            Redis::connection('sessions')->del($key);
        } catch (Throwable) {
            // The cache mirror is removed by the caller as well.
        }
    }
}
