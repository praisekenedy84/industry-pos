<?php

namespace Tests\Feature;

use App\Models\DeviceSession;
use App\Models\ImpersonationLog;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            $schemas = DB::connection('central')->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 't_%'");
            foreach ($schemas as $schema) {
                DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema->schema_name.' CASCADE');
            }
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function leaveRequests(): array
    {
        return [
            'browser post' => ['post', [], 'post'],
            'browser get' => ['get', [], 'get'],
            'inertia post' => ['post', ['X-Inertia' => 'true'], 'inertia'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('leaveRequests')]
    public function test_leaving_impersonation_returns_the_platform_admin_to_the_workspace(string $method, array $headers, string $suffix): void
    {
        [$slug, $tenantId, $ownerId] = $this->workspace($suffix);
        $admin = PlatformAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-'.$suffix.'@example.test',
            'password' => 'Makerly#2026',
        ]);

        $this->carry($this->post('/platform/login', [
            'email' => $admin->email,
            'password' => 'Makerly#2026',
        ]))->assertRedirect('/platform');

        $this->carry($this->post('/platform/tenants/'.$tenantId.'/impersonate', [
            'user_id' => $ownerId,
            'reason' => 'Customer asked for help',
            'password' => 'Makerly#2026',
        ]))->assertRedirect('/w/'.$slug.'/dashboard');

        $left = $this->carry($this->withHeaders($headers)->{$method}('/w/'.$slug.'/impersonation/leave'));
        $platformTenant = '/platform/tenants/'.$tenantId;

        if (($headers['X-Inertia'] ?? null) === 'true') {
            $left->assertStatus(409);
            $location = $left->headers->get('X-Inertia-Location');
            $this->assertIsString($location);
            $this->assertStringContainsString($platformTenant, $location);
            $this->assertStringNotContainsString('/impersonation/leave', $location);
        } else {
            $left->assertRedirect($platformTenant);
        }
        $this->assertNotNull(ImpersonationLog::query()->where('tenant_id', $tenantId)->value('ended_at'));
        $this->assertSame(0, DeviceSession::query()->whereNull('revoked_at')->count());
        app(TenancyManager::class)->end();
        app()->forgetInstance('makerly.host');
        app()->forgetInstance('makerly.base');
        $this->flushHeaders();

        $this->get('/platform/tenants/'.$tenantId)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/TenantShow')
                ->whereNull('impersonation')
                ->where('auth.admin.email', $admin->email)
                ->whereType('csrf', 'string'));
    }

    private function carry(Response|TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (! is_string($cookie->getValue())) {
                continue;
            }
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function workspace(string $suffix): array
    {
        $tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Impersonation Bakery',
            'slug' => 'imperson-'.$suffix,
            'owner_name' => 'Owner',
            'owner_email' => 'owner-'.$suffix.'@example.test',
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
            'sample' => false,
        ]);
        $ownerId = User::query()->value('id');
        app(TenancyManager::class)->end();

        return [$tenant->slug, $tenant->id, $ownerId];
    }
}
