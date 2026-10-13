<?php

namespace App\Http\Middleware;

use App\Tenancy\TenancyManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private TenancyManager $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $supported = ['en', 'sw'];
        if (config('app.debug')) {
            $supported[] = 'pseudo';
        }
        $userLocale = $this->tenancy->initialized() ? $request->user()?->locale : null;
        $tenantLocale = $this->tenancy->tenant()?->default_locale;
        $sessionLocale = $request->session()->get('locale');
        $browser = $request->getPreferredLanguage(['en', 'sw']);
        $locale = $sessionLocale ?: $userLocale ?: $tenantLocale ?: $browser ?: 'en';
        if (! in_array($locale, $supported, true)) {
            $locale = 'en';
        }
        App::setLocale($locale === 'pseudo' ? 'en' : $locale);
        $request->attributes->set('locale', $locale);

        $theme = $this->themeChoice($this->tenancy->initialized() ? $request->user()?->theme : null)
            ?? $this->themeChoice($request->cookie('theme'))
            ?? 'system';
        cookie()->queue(cookie('theme', $theme, 60 * 24 * 365, '/', null, false, false));

        return $next($request);
    }

    private function themeChoice(mixed $theme): ?string
    {
        return in_array($theme, ['light', 'dark', 'system'], true) ? $theme : null;
    }
}
