<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Services\AccessManager;
use App\Services\SaleService;
use App\Services\StockLedger;
use App\Services\StockReceipt;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SalesRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
    }

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

    public function test_day_and_seller_filters_limit_the_sales_list(): void
    {
        $this->workspace('saleslist');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 10, 'unit_cost' => 40]);
        $mine = $this->sell($item, 2, 100, 'cash');
        $colleague = User::query()->create(['name' => 'Asha', 'email' => 'asha@example.test', 'status' => 'active']);
        $theirs = $this->sell($item, 1, 50, 'cash');
        $theirs->update(['user_id' => $colleague->id]);
        $yesterday = $this->sell($item, 1, 80, 'cash');
        $yesterday->update(['created_at' => '2026-10-12 18:00:00']);

        $this->get('/w/saleslist/pos/sales')->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $page->component('Pos/Sales')->has('sales.data', 2)->where('total', '250.0000')->where('filters.date', '2026-10-13');
            $labels = collect($page->toArray()['props']['nav'])->pluck('label')->all();
            $this->assertContains('nav.sales', $labels);
        });
        $this->get('/w/saleslist/pos/sales?seller='.$colleague->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('sales.data', 1)->where('sales.data.0.number', $theirs->number)->where('total', '50.0000'));
        $this->get('/w/saleslist/pos/sales?date=2026-10-12')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('sales.data', 1)->where('sales.data.0.number', $yesterday->number)->where('total', '80.0000'));
        $this->assertNotSame($mine->number, $theirs->number);
    }

    public function test_completed_sale_opens_the_receipt_preview(): void
    {
        $this->workspace('salesreceipt');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 4, 'unit_cost' => 40]);

        $this->followingRedirects()
            ->post('/w/salesreceipt/pos/sales', [
                'idempotency_key' => (string) Str::uuid(),
                'lines' => [['item_id' => $item->id, 'qty' => 1, 'unit_price' => 100]],
                'payments' => [['method' => 'cash', 'amount' => 100]],
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Pos/SaleShow')
                ->where('flash.open_receipt', true)
                ->where('footer', 'Asante / Thank you')
                ->has('sale.receipt.number')
                ->has('sale.lines', 1)
                ->has('sale.payments', 1));
    }

    public function test_receipt_preview_and_download_are_the_same_pdf(): void
    {
        $this->workspace('salespdf');
        $item = $this->stockedItem();
        $item->update(['name' => 'Water (1\\2)']);
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 4, 'unit_cost' => 40]);
        $sale = $this->sell($item, 1, 100, 'cash');
        $url = '/w/salespdf/pos/sales/'.$sale->id.'/receipt.pdf';

        $preview = $this->get($url);
        $preview->assertOk();
        $preview->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $preview->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', (string) $preview->headers->get('content-disposition'));
        $body = $preview->getContent();
        $this->assertStringStartsWith('%PDF-', $body);
        $this->assertStringContainsString('%%EOF', $body);
        $this->assertStringContainsString($sale->number, $body);
        $this->assertStringContainsString('Water \\(1\\\\2\\)', $body);
        $this->assertStringNotContainsString('Water (1\\2)', $body);
        $this->assertStringContainsString('100.00 TZS', $body);
        $this->assertStringContainsString('Asante / Thank you', $body);

        $download = $this->get($url.'?download=1');
        $download->assertOk();
        $this->assertSame($body, $download->getContent());
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertStringContainsString('-receipt.pdf', (string) $download->headers->get('content-disposition'));

        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'production_lead')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->get($url)->assertForbidden();

        $this->workspace('salespdfother');
        $this->get('/w/salespdfother/pos/sales/'.$sale->id.'/receipt.pdf')->assertNotFound();
    }

    public function test_notes_can_be_saved_without_changing_the_sale(): void
    {
        $this->workspace('salesnotes');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 4, 'unit_cost' => 40]);
        $sale = $this->sell($item, 1, 100, 'cash');

        $this->put('/w/salesnotes/pos/sales/'.$sale->id, ['notes' => 'Deliver after lunch', 'total' => '1', 'status' => 'void'])->assertSessionHasNoErrors()->assertRedirect();

        $sale->refresh();
        $this->assertSame('Deliver after lunch', $sale->notes);
        $this->assertSame('100.0000', $sale->total);
        $this->assertSame('completed', $sale->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'pos.sale.updated')->count());
        $this->put('/w/salesnotes/pos/sales/'.$sale->id, ['notes' => str_repeat('a', 2001)])->assertSessionHasErrors('notes');
    }

    public function test_void_returns_stock_reverses_credit_and_keeps_the_record(): void
    {
        $this->workspace('salesvoid');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 5, 'unit_cost' => 40]);
        $customer = Customer::query()->create(['name' => 'Cafe', 'credit_limit' => 1000]);
        $sale = $this->sell($item, 2, 100, 'account', $customer->id);
        $ledger = app(StockLedger::class);
        $this->assertSame('3.0000', $ledger->onHand($item->id, $this->branch()));
        $this->assertSame('200.0000', $customer->fresh()->balance);

        $url = '/w/salesvoid/pos/sales/'.$sale->id;
        $this->delete($url, [])->assertSessionHasErrors('reason');
        $this->delete($url, ['reason' => 'Wrong item'])->assertSessionHasNoErrors();
        $this->delete($url, ['reason' => 'Another reason'])->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame('void', $sale->status);
        $this->assertSame('Wrong item', $sale->void_reason);
        $this->assertSame('5.0000', $ledger->onHand($item->id, $this->branch()));
        $this->assertSame('0.0000', $customer->fresh()->balance);
        $this->assertSame(1, AuditLog::query()->where('action', 'pos.sale.voided')->count());
        $this->assertDatabaseHas('stock_movements', ['reference_id' => $sale->id, 'type' => 'sale_void', 'quantity' => 2], 'tenant');
        $this->assertSame(1, DB::connection('tenant')->table('stock_movements')->where('type', 'sale_void')->count());
        $this->get('/w/salesvoid/pos/sales')->assertInertia(fn (AssertableInertia $page) => $page->has('sales.data', 1)->where('sales.data.0.status', 'void')->where('total', '0.0000'));
        $this->get('/w/salesvoid/reports/sales.summary?from=2026-10-13&to=2026-10-13')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.rows.0.orders', 0)->where('result.rows.0.gross', '0'));
    }

    public function test_void_is_refused_after_a_return_or_started_production(): void
    {
        $this->workspace('salesguard');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 8, 'unit_cost' => 40]);
        $returned = $this->sell($item, 2, 100, 'cash');
        app(SaleService::class)->returnSale($returned, ['reason' => 'Broken', 'lines' => [['sale_line_id' => $returned->lines->first()->id, 'qty' => 1]]]);
        $this->delete('/w/salesguard/pos/sales/'.$returned->id, ['reason' => 'Too late'])->assertSessionHasErrors(['sale' => 'This sale has a return and cannot be voided.']);
        $this->assertSame('completed', $returned->fresh()->status);
        $this->assertSame('7.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));

        $making = $this->sell($item, 1, 100, 'cash');
        $order = ProductionOrder::query()->create(['number' => 'PR-1', 'branch_id' => $this->branch(), 'status' => 'in_progress', 'sale_id' => $making->id, 'planned_qty' => 1]);
        $making->lines->first()->update(['production_order_id' => $order->id]);
        $this->delete('/w/salesguard/pos/sales/'.$making->id, ['reason' => 'Started'])->assertSessionHasErrors(['sale' => 'Cancel the production batch before voiding this sale.']);
        $this->assertSame('completed', $making->fresh()->status);
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame('6.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
        $order->update(['status' => 'planned']);
        $this->delete('/w/salesguard/pos/sales/'.$making->id, ['reason' => 'Not started'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('void', $making->fresh()->status);
        $this->assertSame('7.0000', app(StockLedger::class)->onHand($item->id, $this->branch()));
    }

    public function test_cashier_sees_only_their_sales_and_needs_approval_to_void(): void
    {
        $this->workspace('salescashier');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 6, 'unit_cost' => 40]);
        $own = $this->sell($item, 1, 100, 'cash');
        $otherSeller = User::query()->create(['name' => 'Asha', 'email' => 'asha-cashier@example.test', 'status' => 'active']);
        $other = $this->sell($item, 1, 100, 'cash');
        $other->update(['user_id' => $otherSeller->id]);
        $cashier = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $cashier->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($cashier);

        $this->get('/w/salescashier/pos/sales?seller='.$otherSeller->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('sales.data', 1)->where('sales.data.0.number', $own->number)->where('ownOnly', true));
        $this->get('/w/salescashier/pos/sales/'.$other->id)->assertForbidden();
        $this->delete('/w/salescashier/pos/sales/'.$own->id, ['reason' => 'Mistake'])->assertSessionHasErrors(['approval' => 'A manager must approve this void.']);
        $this->assertSame('completed', $own->fresh()->status);
        $manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@example.test', 'status' => 'active']);
        UserBranchRole::query()->create(['user_id' => $manager->id, 'branch_id' => $this->branch(), 'role_id' => Role::query()->where('slug', 'branch_manager')->value('id')]);
        $this->delete('/w/salescashier/pos/sales/'.$own->id, ['reason' => 'Mistake', 'approved_by' => $cashier->id])->assertSessionHasErrors(['approval' => 'A manager must approve this void.']);
        $this->delete('/w/salescashier/pos/sales/'.$own->id, ['reason' => 'Mistake', 'approved_by' => $manager->id])->assertSessionHasNoErrors();
        $this->assertSame('void', $own->fresh()->status);
        UserBranchRole::query()->where('user_id', $cashier->id)->update(['role_id' => Role::query()->where('slug', 'production_lead')->value('id')]);
        app(AccessManager::class)->forget($cashier);
        $this->get('/w/salescashier/pos/sales')->assertForbidden();
    }

    public function test_other_workspaces_and_suspended_branches_cannot_void(): void
    {
        $this->workspace('salesfirst');
        $item = $this->stockedItem();
        app(StockReceipt::class)->receive($item, $this->branch(), ['request_id' => (string) Str::uuid(), 'qty' => 3, 'unit_cost' => 40]);
        $saleId = $this->sell($item, 1, 100, 'cash')->id;
        $this->workspace('salessecond');
        $this->delete('/w/salessecond/pos/sales/'.$saleId, ['reason' => 'Wrong workspace'])->assertNotFound();
        Branch::query()->firstOrFail()->update(['status' => 'suspended']);
        $local = Item::query()->create(['name' => 'Local', 'sku' => 'LOCAL', 'type' => 'service', 'default_price' => 100, 'track_stock' => false, 'tax_rate_id' => TaxRate::query()->where('rate', 0)->value('id')]);
        $localSale = app(SaleService::class)->complete(['branch_id' => $this->branch(), 'lines' => [['item_id' => $local->id, 'qty' => 1, 'unit_price' => 100]], 'payments' => [['method' => 'cash', 'amount' => 100]]]);
        $this->delete('/w/salessecond/pos/sales/'.$localSale->id, ['reason' => 'Closed'])->assertForbidden();
        $this->assertSame('completed', $localSale->fresh()->status);
    }

    private function workspace(string $slug): void
    {
        $this->slug = $slug;
        app(TenantProvisioner::class)->provision(['name' => ucfirst($slug), 'slug' => $slug, 'owner_name' => 'Owner', 'owner_email' => $slug.'@example.test', 'owner_password' => 'Password123!', 'industry_key' => 'bakery', 'sample' => false]);
        $response = $this->post('/login', ['email' => $slug.'@example.test', 'password' => 'Password123!'])->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->actingAs(User::query()->where('email', $slug.'@example.test')->firstOrFail());
    }

    private function branch(): string
    {
        return (string) Branch::query()->oldest()->value('id');
    }

    private function stockedItem(): Item
    {
        $unit = Unit::query()->firstOrCreate(['symbol' => 'pc'], ['name' => 'Piece']);

        return Item::query()->create([
            'name' => 'Bottled water',
            'sku' => 'WATER-'.Str::lower(Str::random(4)),
            'type' => 'trading_good',
            'base_unit_id' => $unit->id,
            'default_price' => 100,
            'track_stock' => true,
            'tax_rate_id' => TaxRate::query()->where('rate', 0)->value('id'),
        ]);
    }

    private function sell(Item $item, int $qty, int $price, string $method, ?string $customerId = null): Sale
    {
        return app(SaleService::class)->complete([
            'branch_id' => $this->branch(),
            'customer_id' => $customerId,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $item->id, 'qty' => $qty, 'unit_price' => $price]],
            'payments' => [['method' => $method, 'amount' => $qty * $price]],
        ]);
    }
}
