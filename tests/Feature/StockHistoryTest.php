<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Item;
use App\Models\Unit;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $slug = '';

    protected function tearDown(): void
    {
        if ($this->slug !== '' && config('database.connections.central.database') === 'makerly_test') {
            DB::connection('central')->statement('DROP SCHEMA IF EXISTS t_'.$this->slug.' CASCADE');
        }
        app(TenancyManager::class)->end();
        parent::tearDown();
    }

    public function test_stock_history_records_volume_price_and_who_changed_stock(): void
    {
        $this->workspace();
        $this->travelTo(now()->setTime(9, 0));
        $item = Item::query()->create([
            'name' => 'Bottled water',
            'sku' => 'WATER',
            'type' => 'trading_good',
            'base_unit_id' => Unit::query()->where('symbol', 'pc')->value('id'),
            'default_price' => 300,
            'track_stock' => true,
        ]);
        $this->post($this->base().'/catalog/items/'.$item->id.'/stock', [
            'request_id' => (string) Str::uuid(),
            'qty' => 10,
            'unit_cost' => 250,
        ])->assertSessionHasNoErrors();
        $this->travelTo(now()->addMinute());
        $this->post($this->base().'/catalog/items/'.$item->id.'/stock', [
            'request_id' => (string) Str::uuid(),
            'qty' => 4,
            'unit_cost' => 400,
        ])->assertSessionHasNoErrors();
        $this->travelTo(now()->addMinute());
        $this->post($this->base().'/inventory/adjustments', [
            'request_id' => (string) Str::uuid(),
            'item_id' => $item->id,
            'signed_qty' => -3,
            'reason' => 'Broken bottles',
        ])->assertSessionHasNoErrors();

        $rows = collect($this->get($this->base().'/inventory/movements?item_id='.$item->id, $this->headers())->assertOk()->json('props.movements.data'));

        $this->assertSame([
            ['wastage', '-3.0000', '11.0000', '292.8571', 'Owner', 'Broken bottles'],
            ['purchase_receipt', '4.0000', '14.0000', '400.0000', 'Owner'],
            ['purchase_receipt', '10.0000', '10.0000', '250.0000', 'Owner'],
        ], $rows->map(fn (array $row) => [
            $row['type'],
            $row['signed_qty'],
            $row['qty_after'],
            $row['unit_cost'],
            $row['changed_by'],
            ...($row['type'] === 'wastage' ? [$row['reason']] : []),
        ])->all());
        $this->assertSame($item->id, $rows->first()['item_id']);
        $this->assertSame('Bottled water', $rows->first()['item_name']);

        $other = Item::query()->where('sku', 'SOD-001')->sole();
        $filtered = collect($this->get($this->base().'/inventory/movements?item_id='.$other->id, $this->headers())->assertOk()->json('props.movements.data'));
        $this->assertTrue($filtered->isNotEmpty());
        $this->assertFalse($filtered->contains(fn (array $row) => $row['item_id'] === $item->id));
        $this->get($this->base().'/inventory/movements?item_id=not-a-uuid', $this->headers())->assertOk();
    }

    private function workspace(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'history'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision([
            'name' => 'Stock history',
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
