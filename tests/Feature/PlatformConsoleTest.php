<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function admin(string $key): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-'.trim($key, '/').'@example.test',
            'password' => 'Makerly#2026',
        ]);
    }
}
