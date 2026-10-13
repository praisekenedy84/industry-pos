<?php

namespace Tests\Feature;

use App\Mail\WorkspaceApprovalNotice;
use App\Models\Branch;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TenantApprovalTest extends TestCase
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
        $this->travelBack();
        app(TenancyManager::class)->end();
        config(['database.connections.central.search_path' => 'public']);
        DB::purge('central');
        DB::reconnect('central');
        DB::connection('central')->statement('SET search_path TO public');
        if (config('database.connections.central.database') === 'makerly_test') {
            $schemas = DB::connection('central')->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 't_%'");
            foreach ($schemas as $schema) {
                DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema->schema_name.' CASCADE');
            }
        }
        parent::tearDown();
    }

    public function test_signup_starts_a_three_day_trial_and_notifies_the_owner(): void
    {
        $this->freezeTime();
        Mail::fake();

        $signup = $this->post('/signup', $this->signup('trial-shop', 'owner@trial.test'));

        $this->carry($signup)->assertRedirect('/w/trial-shop/dashboard');
        $tenant = Tenant::query()->where('slug', 'trial-shop')->firstOrFail();
        $this->assertSame('trial', $tenant->status);
        $this->assertNull($tenant->approved_at);
        $this->assertSame(now()->addDays(3)->toDateTimeString(), $tenant->trial_ends_at?->toDateTimeString());
        $dashboard = $this->get('/w/trial-shop/dashboard')->assertOk();
        $notes = $dashboard->inertiaProps('notifications');
        $this->assertSame('workspace_trial', $notes[0]['type']);
        $this->assertStringContainsString('+255743463710', $notes[0]['body']);
        $this->assertStringContainsString('+255715148503', $notes[0]['body']);
        $this->assertStringContainsString('info@mernettechnologies.com', $notes[0]['body']);
        $dashboard->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.trial.email', 'info@mernettechnologies.com')
            ->where('tenant.trial.phones.0', '+255743463710')
            ->where('tenant.trial.phones.1', '+255715148503'));
        Mail::assertSent(WorkspaceApprovalNotice::class, function (WorkspaceApprovalNotice $mail): bool {
            $notice = $mail->render();

            return $mail->hasTo('owner@trial.test')
                && str_contains($notice, '+255743463710')
                && str_contains($notice, 'info@mernettechnologies.com')
                && ! str_contains($notice, 'Makerly#2026');
        });
        Mail::assertSent(WorkspaceApprovalNotice::class, function (WorkspaceApprovalNotice $mail): bool {
            return $mail->hasTo('info@mernettechnologies.com') && str_contains($mail->notice, 'trial-shop');
        });
        app(TenancyManager::class)->end();
        app()->forgetInstance('makerly.host');
        $this->actingAs($this->admin('signup'), 'platform')
            ->get('/platform')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tenants.0.slug', 'trial-shop')
                ->where('tenants.0.status', 'trial')
                ->where('tenants.0.awaiting_approval', false));
    }

    public function test_expired_trial_tells_the_workspace_to_get_approved(): void
    {
        $this->freezeTime();
        $this->workspace('expired-shop', 'owner@expired.test', trial: true);
        $this->get('/w/expired-shop/login')->assertOk();

        $this->travel(4)->days();

        $blocked = $this->get('/w/expired-shop/dashboard')->assertForbidden();
        $blocked->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Errors/ApprovalRequired')
            ->where('email', 'info@mernettechnologies.com')
            ->where('phones.0', '+255743463710')
            ->where('phones.1', '+255715148503'));
        $reason = $blocked->inertiaProps('reason');
        $this->assertStringContainsString('+255743463710', $reason);
        $this->assertStringContainsString('+255715148503', $reason);
        $this->assertStringContainsString('info@mernettechnologies.com', $reason);
        $this->assertStringContainsString('Siku 3 zimeisha', $reason);
    }

    public function test_platform_admin_approves_a_workspace_and_it_can_be_used(): void
    {
        $this->freezeTime();
        Mail::fake();
        $tenant = $this->workspace('approved-shop', 'owner@approved.test', trial: true);
        $this->travel(4)->days();

        $this->actingAs($this->admin('approve'), 'platform')
            ->post('/platform/tenants/'.$tenant->id.'/activate')
            ->assertRedirect()
            ->assertSessionHas('success', 'Workspace approved. People can keep using it.');

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertNotNull($tenant->approved_at);
        Auth::guard('platform')->logout();
        Auth::shouldUse('web');
        $login = $this->post('/login', [
            'email' => 'owner@approved.test',
            'password' => 'Makerly#2026',
        ]);
        $this->carry($login)->assertRedirect('/w/approved-shop/dashboard');
        $this->get('/w/approved-shop/dashboard')->assertOk();
        Mail::assertSent(WorkspaceApprovalNotice::class, function (WorkspaceApprovalNotice $mail): bool {
            return $mail->hasTo('owner@approved.test') && str_contains($mail->notice, 'is approved');
        });
    }

    public function test_platform_admin_creates_a_person_who_can_sign_in(): void
    {
        Mail::fake();
        $tenant = $this->workspace('staff-shop', 'owner@staff.test');
        $other = $this->workspace('other-shop', 'owner@other.test');
        [$roleId, $branchId] = $this->directory($tenant);
        $this->assertSame('active', $tenant->status);

        $this->actingAs($this->admin('people'), 'platform')
            ->post('/platform/tenants/'.$tenant->id.'/users', [
                'name' => 'New Cashier',
                'email' => 'cashier@staff.test',
                'phone' => '+255700000001',
                'password' => 'Makerly#2026',
                'role_id' => $roleId,
                'branch_id' => $branchId,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        app(TenancyManager::class)->initialize($tenant);
        $this->assertSame(2, User::query()->count());
        app(TenancyManager::class)->initialize($other);
        $this->assertSame(1, User::query()->count());
        app(TenancyManager::class)->end();
        Auth::guard('platform')->logout();
        Auth::shouldUse('web');
        $login = $this->post('/login', [
            'email' => 'cashier@staff.test',
            'password' => 'Makerly#2026',
        ]);
        $this->carry($login)->assertRedirect('/w/staff-shop/pos');
        $this->get('/w/staff-shop/pos')->assertOk();
        Mail::assertSent(WorkspaceApprovalNotice::class, function (WorkspaceApprovalNotice $mail): bool {
            $notice = $mail->render();

            return $mail->hasTo('cashier@staff.test')
                && str_contains($notice, 'Sign in')
                && ! str_contains($notice, 'Makerly#2026');
        });
    }

    public function test_guest_cannot_create_a_workspace_person(): void
    {
        $tenant = $this->workspace('locked-shop', 'owner@locked.test');

        $this->post('/platform/tenants/'.$tenant->id.'/users', [])
            ->assertRedirect(route('platform.login'));
    }

    public function test_person_form_rejects_an_empty_submission(): void
    {
        $tenant = $this->workspace('blank-shop', 'owner@blank.test');

        $this->actingAs($this->admin('blank'), 'platform')
            ->from('/platform/tenants/'.$tenant->id)
            ->post('/platform/tenants/'.$tenant->id.'/users', [])
            ->assertRedirect('/platform/tenants/'.$tenant->id)
            ->assertSessionHasErrors([
                'name' => 'The name field is required.',
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
                'role_id' => 'The role id field is required.',
                'branch_id' => 'The branch id field is required.',
            ]);
    }

    public function test_person_form_rejects_a_short_password_and_a_duplicate_email(): void
    {
        $tenant = $this->workspace('duplicate-shop', 'owner@duplicate.test');
        [$roleId, $branchId] = $this->directory($tenant);

        $this->actingAs($this->admin('duplicate'), 'platform')
            ->from('/platform/tenants/'.$tenant->id)
            ->post('/platform/tenants/'.$tenant->id.'/users', [
                'name' => 'New Cashier',
                'email' => 'cashier@duplicate.test',
                'password' => 'short',
                'role_id' => $roleId,
                'branch_id' => $branchId,
            ])
            ->assertSessionHasErrors(['password' => 'The password field must be at least 10 characters.']);

        $this->post('/platform/tenants/'.$tenant->id.'/users', [
            'name' => 'Owner Again',
            'email' => 'owner@duplicate.test',
            'password' => 'Makerly#2026',
            'role_id' => $roleId,
            'branch_id' => $branchId,
        ])->assertSessionHasErrors(['email' => 'That email is already used in this workspace.']);
    }

    /**
     * @return array{name: string, slug: string, owner_name: string, owner_email: string, owner_password: string, industry_key: string}
     */
    private function signup(string $slug, string $email): array
    {
        return [
            'name' => 'Trial Shop',
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => $email,
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
        ];
    }

    private function workspace(string $slug, string $email, bool $trial = false): Tenant
    {
        return app(TenantProvisioner::class)->provision([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => $email,
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
            'sample' => false,
            'trial' => $trial,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function directory(Tenant $tenant): array
    {
        app(TenancyManager::class)->initialize($tenant);
        $roleId = (string) Role::query()->where('slug', 'cashier')->value('id');
        $branchId = (string) Branch::query()->value('id');
        app(TenancyManager::class)->end();

        return [$roleId, $branchId];
    }

    private function admin(string $key): PlatformAdmin
    {
        return PlatformAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => 'platform-'.$key.'@example.test',
            'password' => 'Makerly#2026',
        ]);
    }

    private function carry(Response|TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }
}
