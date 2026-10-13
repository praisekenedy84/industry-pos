<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Identity;
use App\Models\UserNotification;
use App\Models\Workspace;
use App\Reports\ReportRegistry;
use App\Services\AccessManager;
use App\Services\FeatureGate;
use App\Support\IndustryVocabulary;
use App\Support\Paths;
use App\Tenancy\TenancyManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => fn () => $request->attributes->get('locale', App::getLocale()),
            'translations' => fn () => $this->translations($request),
            'fallbackTranslations' => fn () => $this->loadLang('en'),
            'theme' => fn () => $request->attributes->get('identity')?->theme ?: $request->cookie('theme', 'system'),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'status' => $request->session()->get('status'),
                'created_unit_id' => $request->session()->get('created_unit_id'),
                'open_receipt' => (bool) $request->session()->get('open_receipt'),
            ],
            'host' => fn () => app()->bound('makerly.host') ? app('makerly.host') : 'central',
            'base' => fn () => Paths::base(),
            'manifest' => fn () => ($base = Paths::base()) ? $base.'/manifest.webmanifest' : null,
            'tenant' => fn () => $this->tenantPayload(),
            'auth' => fn () => $this->authPayload($request),
            'features' => fn () => app(TenancyManager::class)->initialized() ? app(FeatureGate::class)->map() : [],
            'nav' => fn () => $this->nav($request),
            'limits' => fn () => $this->limits(),
            'csrf' => fn () => csrf_token(),
            'impersonation' => fn () => $request->session()->get('impersonation_log_id') ? [
                'expires_at' => $request->session()->get('impersonation_expires_at'),
            ] : null,
            'notifications' => fn () => $this->notifications($request),
            'terminology' => fn () => $this->terminology($request),
        ];
    }

    private function tenantPayload(): ?array
    {
        $tenant = app(TenancyManager::class)->tenant();
        if (! $tenant) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'locale' => $tenant->default_locale,
            'receipt_language' => $tenant->receipt_language,
            'accent' => $tenant->accent_color,
            'timezone' => $tenant->timezone,
        ];
    }

    private function authPayload(Request $request): array
    {
        $tenancy = app(TenancyManager::class);
        $user = $tenancy->initialized() ? $request->user() : null;
        $admin = $request->user('platform');
        $identity = $request->attributes->get('identity');
        $branchId = $request->session()->get('branch_id');
        $branches = [];
        if ($user) {
            $assigned = $user->branchRoles()->pluck('branch_id');
            $branches = Branch::query()->whereIn('id', $assigned)->get(['id', 'name', 'code', 'status']);
            if (! $branchId || ! $branches->contains('id', $branchId)) {
                $branchId = $branches->first()?->id;
            }
        }

        return [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'locale' => $identity->locale ?? $user->locale,
                'theme' => $identity->theme ?? $user->theme,
                'status' => $user->status,
            ] : null,
            'admin' => $admin ? ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email] : null,
            'branch_id' => $branchId,
            'branches' => $branches,
            'workspaces' => $identity ? $this->otherWorkspaces($identity, $tenancy->id()) : [],
        ];
    }

    private function nav(Request $request): array
    {
        $user = app(TenancyManager::class)->initialized() ? $request->user() : null;
        if (! $user && $request->user('platform')) {
            return $this->platformNav($request);
        }
        if (! $user) {
            return [];
        }
        $access = app(AccessManager::class);
        $features = app(FeatureGate::class);
        $items = [
            ['href' => $this->href('/dashboard'), 'label' => 'nav.dashboard', 'show' => true],
            ['href' => $this->href('/production/batches'), 'label' => 'nav.production', 'show' => $features->active('module.production') && $access->allows($user, 'production.orders.view')],
            ['href' => $this->href('/catalog/items'), 'label' => 'nav.catalog', 'show' => $access->allows($user, 'catalog.items.view')],
            ['href' => $this->href('/inventory'), 'label' => 'nav.inventory', 'show' => $access->allows($user, 'inventory.stock.view')],
            ['href' => $this->href('/purchasing/orders'), 'label' => 'nav.purchasing', 'show' => $features->active('module.purchasing') && $access->allows($user, 'purchasing.orders.view')],
            ['href' => $this->href('/pos'), 'label' => 'nav.pos', 'show' => $features->active('module.pos') && $access->allows($user, 'pos.sale.create')],
            ['href' => $this->href('/pos/sales'), 'label' => 'nav.sales', 'show' => $features->active('module.pos') && ($access->allows($user, 'pos.sale.create') || $access->allows($user, 'reports.sales.view'))],
            ['href' => $this->href('/customers'), 'label' => 'nav.customers', 'show' => $features->active('module.customers') && $access->allows($user, 'customers.view')],
            ['href' => $this->href('/expenses'), 'label' => 'nav.expenses', 'show' => $access->allows($user, 'expenses.view')],
            ['href' => $this->href('/reports'), 'label' => 'nav.reports', 'show' => $access->allows($user, 'reports.sales.view') || $access->allows($user, 'reports.inventory.view') || $access->allows($user, 'reports.margin.view')],
            ['href' => $this->href('/settings'), 'label' => 'nav.settings', 'show' => $access->allows($user, 'settings.manage') || $access->allows($user, 'users.view') || $access->allows($user, 'audit.view')],
        ];

        $sections = [
            'nav.expenses' => [
                ['/expenses', 'Expense register', 'expenses.view'],
            ],
            'nav.catalog' => [
                ['/catalog/items', 'Products', 'catalog.items.view'],
                ['/catalog/categories', 'Categories', 'catalog.items.view'],
                ['/catalog/units', 'Units', 'catalog.items.view'],
                ['/catalog/price-lists', 'Price lists', 'catalog.items.view', 'pricing.multi_channel'],
            ],
            'nav.inventory' => [
                ['/inventory/direct-receipts', 'Direct receipts', 'inventory.stock.view'],
                ['/inventory', 'Stock', 'inventory.stock.view'],
                ['/inventory/movements', 'Stock history', 'inventory.stock.view'],
                ['/inventory/counts', 'Stock counts', 'inventory.stock.view'],
                ['/inventory/lots', 'Lots & expiry', 'inventory.stock.view', 'inventory.lots_expiry'],
                ['/inventory/transfers', 'Transfers', 'inventory.transfer', 'branches.multi_branch'],
            ],
            'nav.production' => [
                ['/production/batches', 'Batches', 'production.orders.view', 'module.production'],
            ],
            'nav.purchasing' => [
                ['/purchasing/orders', 'Purchase orders', 'purchasing.orders.view', 'module.purchasing'],
                ['/purchasing/suppliers', 'Suppliers', 'purchasing.orders.view', 'module.purchasing'],
            ],
            'nav.pos' => [
                ['/pos', 'Sales terminal', 'pos.sale.create', 'module.pos'],
            ],
            'nav.settings' => [
                ['/settings', 'General', 'settings.manage'],
                ['/settings/users', 'People', 'users.view'],
                ['/settings/roles', 'Roles', 'roles.manage'],
                ['/settings/branches', 'Branches', 'users.view'],
                ['/settings/audit', 'Audit log', 'audit.view'],
                ['/settings/system-users', 'Integrations', 'system_users.manage'],
                ['/settings/approvals', 'Approvals', 'approvals.resolve'],
            ],
        ];
        $sections['nav.reports'] = [['/reports', 'Overview', null]];
        foreach (app(ReportRegistry::class)->catalog() as $report) {
            $sections['nav.reports'][] = ['/reports/'.$report['key'], $report['name'], $report['permission']];
        }

        foreach ($items as &$item) {
            if (! isset($sections[$item['label']])) {
                continue;
            }
            $children = [];
            foreach ($sections[$item['label']] as $entry) {
                [$path, $label, $permission] = $entry;
                if (($permission && ! $access->allows($user, $permission)) || (isset($entry[3]) && ! $features->active($entry[3]))) {
                    continue;
                }
                $children[] = ['href' => $this->href($path), 'label' => $label];
            }
            if ($item['label'] === 'nav.reports' && count($children) === 1) {
                $children = [];
            }
            $item['children'] = $children;
            $item['show'] = count($children) > 0;
        }
        unset($item);

        $primary = ['nav.dashboard', 'nav.catalog', 'nav.production', 'nav.pos', 'nav.sales'];
        $items = array_values(array_filter($items, fn ($item) => $item['show']));
        usort($items, fn (array $a, array $b): int => (array_search($a['label'], $primary, true) === false ? count($primary) : array_search($a['label'], $primary, true)) <=> (array_search($b['label'], $primary, true) === false ? count($primary) : array_search($b['label'], $primary, true)));

        return $items;
    }

    /**
     * @return array<int, array{href: string, label: string, show: bool, children: array<int, never>}>
     */
    private function platformNav(Request $request): array
    {
        $prefix = str_starts_with(trim($request->path(), '/'), 'admin') ? '/admin' : '/platform';

        return [
            ['href' => $prefix, 'label' => 'nav.workspaces', 'show' => true, 'children' => []],
            ['href' => $prefix.'/features', 'label' => 'nav.features', 'show' => true, 'children' => []],
            ['href' => $prefix.'/templates', 'label' => 'nav.templates', 'show' => true, 'children' => []],
        ];
    }

    private function notifications(Request $request): array
    {
        $user = app(TenancyManager::class)->initialized() ? $request->user() : null;
        if (! $user) {
            return [];
        }

        return UserNotification::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->toArray();
    }

    private function limits(): array
    {
        if (! app(TenancyManager::class)->initialized()) {
            return [];
        }
        $gate = app(FeatureGate::class);
        $warnings = [];
        foreach (['max_branches', 'max_staff_users', 'max_items', 'max_system_users', 'max_monthly_transactions'] as $key) {
            $max = $gate->limit($key);
            if (! $max) {
                continue;
            }
            $warnings[] = ['key' => $key, 'max' => $max];
        }

        return $warnings;
    }

    private function translations(Request $request): array
    {
        $locale = $request->attributes->get('locale', 'en');
        $base = $locale === 'pseudo' ? 'en' : $locale;
        $pack = $this->loadLang($base);
        if ($locale === 'pseudo') {
            $pack = array_map(fn ($value) => is_string($value) ? '['.$value.' '.$value.']' : $value, $pack);
        }
        if (app(TenancyManager::class)->initialized()) {
            $lang = $base === 'sw' ? 'sw' : 'en';
            foreach (app(IndustryVocabulary::class)->forCurrentTenant()[$lang] ?? [] as $key => $value) {
                $pack['vocab.'.$key] = $value;
            }
        }

        return $pack;
    }

    /**
     * @return array{industry: string|null, phrases: list<array{from: string, to: string}>}|null
     */
    private function terminology(Request $request): ?array
    {
        $tenancy = app(TenancyManager::class);
        if (! $tenancy->initialized()) {
            return null;
        }
        $locale = $request->attributes->get('locale', 'en');
        $lang = $locale === 'sw' ? 'sw' : 'en';
        $vocabulary = app(IndustryVocabulary::class);
        $labels = $vocabulary->forCurrentTenant();

        return [
            'industry' => $tenancy->tenant()?->industry_key,
            'phrases' => $vocabulary->pairs($labels, $lang),
        ];
    }

    private function href(string $path): string
    {
        return Paths::to($path);
    }

    private function otherWorkspaces(Identity $identity, ?string $currentTenantId): array
    {
        return $identity->memberships()
            ->where('status', 'active')
            ->get()
            ->map(function ($membership) use ($currentTenantId) {
                $workspace = Workspace::query()->where('tenant_id', $membership->tenant_id)->first();
                if (! $workspace || $workspace->status !== 'active' || $workspace->tenant_id === $currentTenantId) {
                    return null;
                }

                return [
                    'slug' => $workspace->slug,
                    'name' => $workspace->display_name,
                    'href' => '/w/'.$workspace->slug.'/',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function loadLang(string $locale): array
    {
        $path = lang_path($locale.'.json');
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }
}
