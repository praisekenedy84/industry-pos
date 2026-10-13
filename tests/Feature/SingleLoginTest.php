<?php

namespace Tests\Feature;

use App\Auth\IdentityAuthenticator;
use App\Models\DeviceSession;
use App\Models\Identity;
use App\Models\Membership;
use App\Models\Tenant;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SingleLoginTest extends TestCase
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

    public function test_one_membership_skips_the_picker(): void
    {
        [$slug, $email] = $this->workspace('solo', 'owner@example.test');

        $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]))->assertRedirect('/w/'.$slug.'/dashboard');
    }

    public function test_two_memberships_can_open_a_second_workspace_without_a_password(): void
    {
        $email = 'shared@example.test';
        [$first] = $this->workspace('paira', $email);
        [$second] = $this->workspace('pairb', $email);

        $login = $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]));
        $login->assertRedirect('/workspaces');

        $choice = Tenant::query()->where('slug', $first)->value('id');
        $this->carry($this->post('/workspaces', ['tenant_id' => $choice]))
            ->assertRedirect('/w/'.$first.'/dashboard');

        $this->carry($this->get('/w/'.$second.'/'))
            ->assertRedirect('/w/'.$second.'/dashboard');
    }

    public function test_unknown_and_wrong_password_share_one_message(): void
    {
        [, $email] = $this->workspace('gamma', 'known@example.test');
        app(IdentityAuthenticator::class);

        $unknownStarted = hrtime(true);
        $unknown = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'Makerly#2026',
        ]);
        $unknownTook = hrtime(true) - $unknownStarted;

        $wrongStarted = hrtime(true);
        $wrong = $this->from('/login')->post('/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ]);
        $wrongTook = hrtime(true) - $wrongStarted;

        $unknown->assertSessionHasErrors(['email' => 'Email or password is incorrect.']);
        $wrong->assertSessionHasErrors(['email' => 'Email or password is incorrect.']);
        $this->assertLessThan(1_500_000_000, abs($unknownTook - $wrongTook));
    }

    public function test_suspended_membership_locks_only_that_workspace(): void
    {
        $email = 'both@example.test';
        [$first] = $this->workspace('north', $email);
        [$second] = $this->workspace('south', $email);
        $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]));
        $this->carry($this->post('/workspaces', [
            'tenant_id' => Tenant::query()->where('slug', $first)->value('id'),
        ]));
        $this->carry($this->get('/w/'.$second.'/'))->assertRedirect();

        $membership = Membership::query()
            ->where('tenant_id', Tenant::query()->where('slug', $first)->value('id'))
            ->firstOrFail();
        $membership->update(['status' => 'suspended']);

        $this->get('/w/'.$first.'/dashboard')
            ->assertRedirect('/w/'.$first.'/login')
            ->assertSessionHas('error', 'Your access to this workspace has ended. Contact your administrator.');
        $this->get('/w/'.$second.'/dashboard')->assertOk();
    }

    public function test_password_reset_revokes_every_session(): void
    {
        [$slug, $email] = $this->workspace('reset', 'reset@example.test');
        $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]));
        $this->get('/w/'.$slug.'/dashboard')->assertOk();

        $token = Str::random(48);
        DB::table('identity_password_resets')->insert([
            'identifier' => $email,
            'token_hash' => hash('sha256', $token),
            'created_at' => now(),
        ]);
        $this->post('/reset-password/'.$token, [
            'password' => 'Makerly#2027',
            'password_confirmation' => 'Makerly#2027',
        ])->assertRedirect('/login');

        $this->assertSame(0, DeviceSession::query()->where('identity_id', Identity::query()->where('email', $email)->value('id'))->whereNull('revoked_at')->count());
        $this->get('/w/'.$slug.'/dashboard')->assertRedirect('/w/'.$slug.'/login');
    }

    public function test_manifest_stays_inside_the_workspace(): void
    {
        [$slug] = $this->workspace('bakery', 'bakery@example.test');

        $this->get('/w/'.$slug.'/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('id', '/w/'.$slug.'/')
            ->assertJsonPath('scope', '/w/'.$slug.'/')
            ->assertJsonPath('start_url', '/w/'.$slug.'/?source=pwa')
            ->assertJsonPath('name', 'Bakery');

        $icon = $this->get('/w/'.$slug.'/icon/192');
        $icon->assertOk();
        $icon->assertHeader('content-type', 'image/png');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $icon->getContent());

        $this->get('http://'.$slug.'.localhost/dashboard')->assertNotFound();
    }

    public function test_pin_is_refused_on_an_unregistered_device(): void
    {
        [$slug, $email] = $this->workspace('counter', 'counter@example.test');
        $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]));

        $this->post('/w/'.$slug.'/pin', ['pin' => '1234'])
            ->assertSessionHasErrors('pin');
    }

    private function carry(Response|TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    private function workspace(string $slug, string $email): array
    {
        app(TenantProvisioner::class)->provision([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => $email,
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
            'sample' => false,
        ]);

        return [$slug, $email];
    }
}
