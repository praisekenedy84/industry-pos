<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Item;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $slug;

    protected function tearDown(): void
    {
        if (config('database.connections.central.database') === 'makerly_test') {
            foreach (DB::connection('central')->select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 't_%'") as $schema) {
                DB::connection('central')->statement('DROP SCHEMA IF EXISTS '.$schema->schema_name.' CASCADE');
            }
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_stock_filter_separates_produced_goods_from_purchased_and_added_items(): void
    {
        $this->workspace();

        $rows = $this->stockRows();
        $all = $rows->pluck('name')->all();
        $this->assertContains('White bread', $all);
        $this->assertContains('Wheat flour', $all);
        $this->assertContains('Soda 300ml', $all);
        $this->assertSame('produced', $rows->firstWhere('name', 'White bread')['source']);
        $this->assertSame('stocked', $rows->firstWhere('name', 'Wheat flour')['source']);
        $this->assertSame('stocked', $rows->firstWhere('name', 'Soda 300ml')['source']);

        $produced = $this->stockNames('source=produced');
        $this->assertContains('White bread', $produced);
        $this->assertNotContains('Wheat flour', $produced);
        $this->assertNotContains('Soda 300ml', $produced);

        $stocked = $this->stockNames('source=stocked');
        $this->assertContains('Wheat flour', $stocked);
        $this->assertContains('Soda 300ml', $stocked);
        $this->assertNotContains('White bread', $stocked);

        $yeast = Item::query()->where('sku', 'YST-001')->sole();
        $sugar = Item::query()->where('sku', 'SGR-001')->sole();
        $this->post($this->base().'/production/recipes', [
            'name' => 'Cultured yeast',
            'item_id' => $yeast->id,
            'yield_qty' => 1,
            'lines' => [['item_id' => $sugar->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertContains('Yeast', $this->stockNames('source=produced'));
        $this->assertNotContains('Yeast', $this->stockNames('source=stocked'));
        $this->assertSame(['Soda 300ml'], $this->stockNames('source=stocked&q=Soda'));
    }

    public function test_unknown_stock_source_keeps_produced_and_purchased_items(): void
    {
        $this->workspace();

        $response = $this->get($this->base().'/inventory?source='.urlencode("stocked' AND 1=0 --"), $this->headers())->assertOk();
        $names = collect($response->json('props.rows'))->pluck('name')->all();

        $this->assertSame('', $response->json('props.filters.source'));
        $this->assertContains('White bread', $names);
        $this->assertContains('Wheat flour', $names);
    }

    private function stockNames(string $query = ''): array
    {
        return $this->stockRows($query)->pluck('name')->all();
    }

    private function stockRows(string $query = ''): Collection
    {
        $url = $this->base().'/inventory'.($query === '' ? '' : '?'.$query);

        return collect($this->get($url, $this->headers())->assertOk()->json('props.rows'));
    }

    private function workspace(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'stock'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision([
            'name' => 'Stock filter',
            'slug' => $this->slug,
            'owner_name' => 'Owner',
            'owner_email' => $this->slug.'@example.test',
            'owner_password' => 'Makerly#2026',
            'industry_key' => 'bakery',
            'sample' => true,
        ]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Makerly#2026'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function base(): string
    {
        return '/w/'.$this->slug;
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/'))];
    }
}
