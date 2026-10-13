<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
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

    public function test_theme_cookie_stays_readable_by_the_browser(): void
    {
        $this->withUnencryptedCookie('theme', 'dark')
            ->get('http://localhost/')
            ->assertPlainCookie('theme', 'dark');
    }

    public function test_unreadable_theme_cookie_falls_back_to_system(): void
    {
        $this->withUnencryptedCookie('theme', 'not-a-theme')
            ->get('http://localhost/')
            ->assertPlainCookie('theme', 'system');
    }

    public function test_chosen_appearance_is_still_dark_on_the_next_visit(): void
    {
        [$slug, $email] = $this->workspace('theme', 'theme@example.test');
        $this->carry($this->post('/login', [
            'email' => $email,
            'password' => 'Makerly#2026',
        ]));

        $saved = $this->carry($this->from('/w/'.$slug.'/dashboard')->post('/w/'.$slug.'/theme', [
            'theme' => 'dark',
        ]));

        $saved->assertRedirect('/w/'.$slug.'/dashboard');
        $saved->assertPlainCookie('theme', 'dark');

        $this->get('/w/'.$slug.'/dashboard')
            ->assertOk()
            ->assertPlainCookie('theme', 'dark')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('theme', 'dark'));

        $this->assertSame('dark', Identity::query()->where('email', $email)->value('theme'));
    }

    private function carry(Response|TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string}
     */
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
