<?php

namespace Tests\Feature;

use App\Models\IndustryTemplate;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Support\IndustryCatalog;
use App\Support\IndustryTerms;
use App\Support\IndustryVocabulary;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IndustryTerminologyTest extends TestCase
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

    public function test_workspace_uses_the_industry_template_attached_to_it(): void
    {
        $slug = 'construction-words';
        app(TenantProvisioner::class)->provision([
            'name' => 'Construction words',
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => 'construction-words@example.test',
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'construction',
            'sample' => false,
        ]);
        $this->carry($this->post('/login', [
            'email' => 'construction-words@example.test',
            'password' => 'Makerly#2026',
        ]));

        $phrases = collect($this->get('/w/'.$slug.'/dashboard')->assertOk()->inertiaProps('terminology.phrases'));

        $this->assertSame('construction', $this->get('/w/'.$slug.'/dashboard')->inertiaProps('terminology.industry'));
        $this->assertSame('Raw material', $phrases->firstWhere('from', 'ingredient')['to']);
        $this->assertSame('Bill of quantities', $phrases->firstWhere('from', 'recipe')['to']);
    }

    public function test_switching_the_attached_template_changes_the_wording(): void
    {
        $slug = 'switch-words';
        app(TenantProvisioner::class)->provision([
            'name' => 'Switch words',
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => 'switch-words@example.test',
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'construction',
            'sample' => false,
        ]);
        $this->carry($this->post('/login', [
            'email' => 'switch-words@example.test',
            'password' => 'Makerly#2026',
        ]));

        $this->put('/w/'.$slug.'/settings', ['industry_key' => 'bakery'])->assertRedirect();

        $this->assertSame('bakery', Tenant::query()->where('slug', $slug)->value('industry_key'));
        $phrases = collect($this->get('/w/'.$slug.'/dashboard')->assertOk()->inertiaProps('terminology.phrases'));
        $this->assertSame('Formula', $phrases->firstWhere('from', 'recipe')['to']);
        $this->assertNull($phrases->firstWhere('from', 'ingredient'));
    }

    public function test_unknown_industry_is_rejected_and_the_attached_template_stays(): void
    {
        $slug = 'keep-words';
        app(TenantProvisioner::class)->provision([
            'name' => 'Keep words',
            'slug' => $slug,
            'owner_name' => 'Owner',
            'owner_email' => 'keep-words@example.test',
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'construction',
            'sample' => false,
        ]);
        $this->carry($this->post('/login', [
            'email' => 'keep-words@example.test',
            'password' => 'Makerly#2026',
        ]));

        $this->from('/w/'.$slug.'/settings')->put('/w/'.$slug.'/settings', ['industry_key' => 'not-an-industry'])
            ->assertRedirect('/w/'.$slug.'/settings')
            ->assertSessionHasErrors('industry_key');

        $this->assertSame('construction', Tenant::query()->where('slug', $slug)->value('industry_key'));
    }

    public function test_saved_template_labels_are_what_that_industry_shows(): void
    {
        $admin = PlatformAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => 'templates@example.test',
            'password' => 'Makerly#2026',
        ]);
        $vocabulary = IndustryTerms::normalize(IndustryCatalog::find('construction')['vocabulary']);
        $vocabulary['en']['ingredient'] = 'Input';
        $vocabulary['en']['ingredients'] = 'Inputs';

        $this->actingAs($admin, 'platform')->put('/platform/templates/construction', [
            'name' => 'Construction',
            'vocabulary' => $vocabulary,
        ])->assertRedirect();

        $this->assertTrue((bool) IndustryTemplate::query()->where('key', 'construction')->value('payload')['vocabulary_customized']);
        $labels = app(IndustryVocabulary::class)->forIndustry('construction');
        $this->assertSame('Choose input', app(IndustryVocabulary::class)->apply('Choose ingredient', $labels, 'en'));
    }

    private function carry(Response|TestResponse $response): TestResponse
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        return $response;
    }
}
