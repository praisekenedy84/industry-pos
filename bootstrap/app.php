<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureCentralHost;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureTenantHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyHost;
use App\Http\Middleware\IdleTimeout;
use App\Http\Middleware\InitializeWorkspaceFromPath;
use App\Http\Middleware\RequireWorkspaceSession;
use App\Http\Middleware\ResolveApiTenant;
use App\Http\Middleware\ResolveDeviceSession;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Paths;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'ds',
            '__Host-ds',
            'theme',
        ]);
        $middleware->web(prepend: [
            ResolveDeviceSession::class,
            InitializeWorkspaceFromPath::class,
            IdentifyHost::class,
        ]);
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        $middleware->web(remove: [
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->api(prepend: [
            ResolveApiTenant::class,
        ]);
        $middleware->alias([
            'central' => EnsureCentralHost::class,
            'tenant' => EnsureTenantHost::class,
            'feature' => EnsureFeature::class,
            'idle' => IdleTimeout::class,
            'workspace.auth' => RequireWorkspaceSession::class,
            'api.token' => AuthenticateApiToken::class,
        ]);
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('platform') || $request->is('platform/*') || $request->is('admin') || $request->is('admin/*')) {
                return route('platform.login');
            }
            $workspace = $request->route('workspace');
            if (is_string($workspace) && $workspace !== '') {
                return route('workspace.login', ['workspace' => $workspace]);
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->header('X-Inertia') && ! $request->acceptsHtml()) {
                return $response;
            }
            $status = $response->getStatusCode();
            if (in_array($status, [403, 404, 419, 500, 503], true) && ! $request->is('api/*')) {
                $home = '/';
                if (Paths::base() !== '') {
                    $home = Paths::to('/dashboard');
                } elseif ($request->is('admin') || $request->is('admin/*')) {
                    $home = '/admin';
                } elseif ($request->is('platform') || $request->is('platform/*') || Auth::guard('platform')->check()) {
                    $home = '/platform';
                }

                return Inertia::render('Errors/Http', [
                    'status' => $status,
                    'home' => $home,
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
