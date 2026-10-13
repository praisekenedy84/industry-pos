<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    /**
     * @return array<string, array{0: string}>
     */
    public static function consoleHomes(): array
    {
        return [
            'platform' => ['/platform'],
            'admin' => ['/admin'],
        ];
    }

    #[DataProvider('consoleHomes')]
    public function test_guest_is_redirected_to_platform_sign_in(string $path): void
    {
        $this->get($path)->assertRedirect(route('platform.login'));
    }

    #[DataProvider('consoleHomes')]
    public function test_signed_in_admin_sees_console_navigation(string $path): void
    {
        $admin = $this->admin($path);

        $this->actingAs($admin, 'platform')
            ->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Tenants')
                ->where('nav.0.href', $path)
                ->where('nav.0.label', 'nav.workspaces')
                ->where('nav.1.href', $path.'/features')
                ->where('nav.1.label', 'nav.features')
                ->where('nav.2.href', $path.'/templates')
                ->where('nav.2.label', 'nav.templates')
                ->where('auth.admin.email', $admin->email)
                ->has('tenants'));
    }

    public function test_workspace_list_renders_when_a_tenant_schema_has_no_branches_table(): void
    {
        $admin = $this->admin('missing-branches');
        $ready = $this->tenant('ready-shop', 't_ready_branches');
        $empty = $this->tenant('empty-shop', 't_empty_schema');
        DB::connection('central')->statement('CREATE SCHEMA t_ready_branches');
        DB::connection('central')->statement('CREATE TABLE t_ready_branches.branches (id uuid primary key)');
        DB::connection('central')->statement("INSERT INTO t_ready_branches.branches (id) VALUES ('11111111-1111-1111-1111-111111111111')");
        DB::connection('central')->statement('CREATE SCHEMA t_empty_schema');

        try {
            $tenants = collect($this->actingAs($admin, 'platform')->get('/platform')->assertOk()->inertiaProps('tenants'))->keyBy('slug');

            $this->assertSame(1, $tenants['ready-shop']['branches']);
            $this->assertNull($tenants['empty-shop']['branches']);
        } finally {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_ready_branches CASCADE');
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_empty_schema CASCADE');
            $ready->delete();
            $empty->delete();
        }
    }

    public function test_workspace_page_renders_when_the_tenant_schema_has_no_users_table(): void
    {
        $admin = $this->admin('missing-users');
        $empty = $this->tenant('bare-shop', 't_bare_schema');
        DB::connection('central')->statement('CREATE SCHEMA t_bare_schema');

        try {
            $this->actingAs($admin, 'platform')
                ->get('/platform/tenants/'.$empty->id)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Platform/TenantShow')
                    ->where('users', []));
        } finally {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_bare_schema CASCADE');
            $empty->delete();
        }
    }

    public function test_guest_root_dashboard_returns_to_the_welcome_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/');
    }

    public function test_platform_admin_root_dashboard_returns_to_the_console(): void
    {
        $admin = $this->admin('dashboard');

        $this->actingAs($admin, 'platform')
            ->get('/dashboard')
            ->assertRedirect('/platform');
    }

    public function test_feature_matrix_renders_for_a_platform_admin(): void
    {
        $admin = $this->admin('features');

        $this->actingAs($admin, 'platform')
            ->get('/platform/features')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Features')
                ->where('nav.1.href', '/platform/features')
                ->has('features')
                ->has('tenants')
                ->has('overrides'));
    }

    public function test_industry_templates_render_for_a_platform_admin(): void
    {
        $admin = $this->admin('templates');

        $this->actingAs($admin, 'platform')
            ->get('/platform/templates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Templates')
                ->where('nav.2.href', '/platform/templates')
                ->has('templates'));
    }

    private function tenant(string $slug, string $schema): Tenant
    {
        return Tenant::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'schema_name' => $schema,
            'industry_key' => 'bakery',
        ]);
    }

    private function admin(string $key): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-'.trim($key, '/').'@example.test',
            'password' => 'Makerly#2026',
        ]);
    }
}
