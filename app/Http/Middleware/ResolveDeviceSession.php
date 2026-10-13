<?php

namespace App\Http\Middleware;

use App\Auth\DeviceSessionManager;
use App\Models\Identity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveDeviceSession
{
    public function __construct(private DeviceSessionManager $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->devices->fromRequest($request);
        if ($device) {
            $request->attributes->set('device', $device);
            $identity = Identity::query()->find($device['identity_id']);
            if ($identity && $identity->isActive()) {
                $request->attributes->set('identity', $identity);
                $this->devices->touch($device);
            }
        }

        return $next($request);
    }
}
