<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Models\TenantLimit;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SeedDemoTenantsTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetCentralSearchPath();
        $this->forgetWorkspaces(['demo-brick', 'demo-furniture', 'demo-general', 'demo-construction', 'seed-demo-keeper']);
    }

    protected function tearDown(): void
    {
        $this->resetCentralSearchPath();
        app(TenancyManager::class)->end();
        if (config('database.connections.central.database') === 'makerly_test') {
            $this->forgetWorkspaces(['demo-brick', 'demo-furniture', 'demo-general', 'demo-construction', 'seed-demo-keeper']);
        }
        parent::tearDown();
    }

    public function test_creates_four_approved_demo_workspaces_and_leaves_an_existing_workspace_in_place(): void
    {
        $keeper = app(TenantProvisioner::class)->provision([
            'name' => 'Keeper',
            'slug' => 'seed-demo-keeper',
            'industry_key' => 'bakery',
            'owner_name' => 'Keeper Owner',
            'owner_email' => 'keeper-demo@example.test',
            'owner_password' => 'Password123!',
            'sample' => false,
        ]);

        [$exitCode, $output] = $this->runDemoCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/w/demo-brick/login', $output);
        $this->assertStringContainsString('/w/demo-furniture/login', $output);
        $this->assertStringContainsString('/w/demo-general/login', $output);
        $this->assertStringContainsString('/w/demo-construction/login', $output);
        $this->assertStringContainsString('Owner password for the new workspaces: Makerly#2026', $output);

        $this->assertSame($keeper->id, app(TenancyManager::class)->id());
        $expected = [
            'demo-brick' => ['brick', 'BRK-OUT', 'owner@demo-brick.test'],
            'demo-furniture' => ['furniture', 'CHR-001', 'owner@demo-furniture.test'],
            'demo-general' => ['general', 'GEN-OUT', 'owner@demo-general.test'],
            'demo-construction' => ['construction', 'CON-OUT', 'owner@demo-construction.test'],
        ];
        $this->assertSame(4, Tenant::query()->whereIn('slug', array_keys($expected))->count());
        foreach ($expected as $slug => [$industry, $sku, $email]) {
            $tenant = Tenant::query()->where('slug', $slug)->sole();
            $this->assertSame($industry, $tenant->industry_key);
            $this->assertSame('active', $tenant->status);
            $this->assertNotNull($tenant->approved_at);
            $this->assertNull($tenant->trial_ends_at);
            $this->assertTrue($tenant->canUseSystem());
            app(TenancyManager::class)->initialize($tenant);
            $this->assertTrue(Item::query()->where('sku', $sku)->exists());
            $this->assertTrue(User::query()->where('email', $email)->exists());
        }

        app(TenancyManager::class)->initialize($keeper);
        [$exitCode, $output] = $this->runDemoCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringNotContainsString('Owner password for the new workspaces: Makerly#2026', $output);

        $this->assertSame(4, Tenant::query()->whereIn('slug', array_keys($expected))->count());
        $this->assertSame($keeper->id, app(TenancyManager::class)->id());
    }

    public function test_refuses_a_slug_owned_by_another_industry_and_creates_no_demo_workspace(): void
    {
        app(TenantProvisioner::class)->provision([
            'name' => 'Taken',
            'slug' => 'demo-furniture',
            'industry_key' => 'bakery',
            'owner_name' => 'Taken Owner',
            'owner_email' => 'taken-demo@example.test',
            'owner_password' => 'Password123!',
            'sample' => false,
        ]);

        $this->artisan('makerly:seed-demo-tenants')
            ->expectsOutputToContain('demo-furniture already belongs to bakery.')
            ->assertFailed();

        $this->assertSame('bakery', Tenant::query()->where('slug', 'demo-furniture')->value('industry_key'));
        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-brick']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-general']);
        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-construction']);
    }

    public function test_refuses_a_short_owner_password_and_creates_no_demo_workspace(): void
    {
        $this->artisan('makerly:seed-demo-tenants', ['--password' => 'short'])
            ->expectsOutputToContain('The owner password must be at least 10 characters.')
            ->assertFailed();

        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-brick']);
    }

    public function test_refuses_to_seed_in_production_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('makerly:seed-demo-tenants')
            ->expectsOutputToContain('Pass --force to create demo workspaces in production.')
            ->assertFailed();

        $this->assertDatabaseMissing('tenants', ['slug' => 'demo-brick']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function runDemoCommand(array $parameters = []): array
    {
        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('makerly:seed-demo-tenants', $parameters, $output);

        return [$exitCode, $output->fetch()];
    }

    private function resetCentralSearchPath(): void
    {
        config(['database.connections.central.search_path' => 'public']);
        DB::purge('central');
        DB::reconnect('central');
        DB::connection('central')->statement('SET search_path TO public');
    }

    /**
     * @param  list<string>  $slugs
     */
    private function forgetWorkspaces(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $schema = 't_'.str_replace('-', '_', $slug);
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
        }
        if (! Schema::connection('central')->hasTable('tenants')) {
            return;
        }
        $ids = Tenant::query()->whereIn('slug', $slugs)->pluck('id');
        if ($ids->isNotEmpty()) {
            $identityIds = Membership::query()->whereIn('tenant_id', $ids)->pluck('identity_id');
            Membership::query()->whereIn('tenant_id', $ids)->delete();
            Identity::query()->whereIn('id', $identityIds)->delete();
            Workspace::query()->whereIn('tenant_id', $ids)->delete();
            TenantFeature::query()->whereIn('tenant_id', $ids)->delete();
            TenantLimit::query()->whereIn('tenant_id', $ids)->delete();
            Invitation::query()->whereIn('tenant_id', $ids)->delete();
            Tenant::query()->whereIn('id', $ids)->delete();
        }
    }
}
