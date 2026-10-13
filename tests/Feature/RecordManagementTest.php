<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Services\AccessManager;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordManagementTest extends TestCase
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

    public function test_ingredients_can_be_edited_archived_listed_and_restored_without_losing_stock_history(): void
    {
        $this->workspace();
        $item = Item::query()->where('sku', 'FLR-001')->sole();
        $movements = StockMovement::query()->where('item_id', $item->id)->count();
        $url = $this->base().'/catalog/items/'.$item->id;
        $this->put($url, ['name' => 'Baking flour', 'type' => $item->type, 'base_unit_id' => $item->base_unit_id])->assertSessionHasNoErrors();
        $this->assertSame('Baking flour', $item->fresh()->name);
        $this->delete($url)->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->trashed());
        $this->get($this->base().'/catalog/items?archived=1', $this->headers())->assertOk()->assertJsonPath('props.items.data.0.id', $item->id);
        $this->post($url.'/restore')->assertSessionHasNoErrors();
        $this->assertFalse($item->fresh()->trashed());
        $this->assertSame($movements, StockMovement::query()->where('item_id', $item->id)->count());
    }

    public function test_category_edit_archive_and_restore_keep_existing_item_classifications(): void
    {
        $this->workspace();
        $item = Item::query()->whereNotNull('category_id')->firstOrFail();
        $category = Category::query()->findOrFail($item->category_id);
        $url = $this->base().'/catalog/categories/'.$category->id;
        $this->put($url, ['name' => 'Updated category'])->assertSessionHasNoErrors();
        $this->assertSame('Updated category', $category->fresh()->name);
        $this->delete($url)->assertSessionHasNoErrors();
        $this->assertTrue($category->fresh()->trashed());
        $this->assertSame($category->id, $item->fresh()->category_id);
        $this->post($url.'/restore')->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->trashed());
    }

    public function test_used_units_cannot_be_deleted_but_unused_units_can_be_edited_and_deleted(): void
    {
        $this->workspace();
        $used = Item::query()->whereNotNull('base_unit_id')->firstOrFail()->base_unit_id;
        $this->delete($this->base().'/catalog/units/'.$used)->assertSessionHasErrors('unit');
        $this->assertDatabaseHas('units', ['id' => $used], 'tenant');
        $unit = Unit::query()->create(['name' => 'Unused', 'symbol' => 'unused']);
        $this->put($this->base().'/catalog/units/'.$unit->id, ['name' => 'Renamed unit', 'symbol' => 'test'])->assertSessionHasNoErrors();
        $this->assertSame('test', $unit->fresh()->symbol);
        $this->delete($this->base().'/catalog/units/'.$unit->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('units', ['id' => $unit->id], 'tenant');
    }

    public function test_suppliers_can_be_created_with_item_prices_edited_archived_and_restored(): void
    {
        $this->workspace();
        $item = Item::query()->firstOrFail();
        $this->post($this->base().'/purchasing/suppliers', ['name' => 'New supplier', 'item_id' => $item->id, 'price' => 123])->assertSessionHasNoErrors();
        $supplier = Supplier::query()->where('name', 'New supplier')->sole();
        $this->assertDatabaseHas('supplier_prices', ['supplier_id' => $supplier->id, 'item_id' => $item->id, 'price' => 123], 'tenant');
        $url = $this->base().'/purchasing/suppliers/'.$supplier->id;
        $this->put($url, ['name' => 'Renamed supplier', 'phone' => '12345'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed supplier', $supplier->fresh()->name);
        $supplier->update(['balance' => 100]);
        $this->delete($url)->assertSessionHasErrors('supplier');
        $supplier->update(['balance' => 0]);
        $this->delete($url)->assertSessionHasNoErrors();
        $this->assertTrue($supplier->fresh()->trashed());
        $this->post($url.'/restore')->assertSessionHasNoErrors();
        $this->assertFalse($supplier->fresh()->trashed());
    }

    public function test_customer_controls_preserve_history_and_prevent_archiving_unsettled_balances(): void
    {
        $this->workspace();
        $this->post($this->base().'/customers', ['name' => 'Customer', 'credit_limit' => ''])->assertSessionHasNoErrors()->assertRedirect();
        $customer = Customer::query()->where('name', 'Customer')->sole();
        $this->assertSame('0.0000', $customer->credit_limit);
        $url = $this->base().'/customers/'.$customer->id;
        $this->get($url, $this->headers())->assertOk();
        $this->put($url, ['name' => 'Edited customer', 'phone' => '12345'])->assertSessionHasNoErrors();
        $this->assertSame('Edited customer', $customer->fresh()->name);
        $customer->update(['balance' => 10]);
        $this->delete($url)->assertSessionHasErrors('customer');
        $customer->update(['balance' => 0]);
        $this->delete($url)->assertSessionHasNoErrors();
        $this->assertTrue($customer->fresh()->trashed());
        $this->post($url.'/restore')->assertSessionHasNoErrors();
        $this->assertFalse($customer->fresh()->trashed());
    }

    public function test_management_actions_require_permission_and_do_not_resolve_foreign_records(): void
    {
        $this->workspace();
        $this->delete($this->base().'/catalog/units/'.Str::uuid())->assertNotFound();
        $item = Item::query()->firstOrFail();
        $item->delete();
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($owner);
        $this->post($this->base().'/catalog/items/'.$item->id.'/restore')->assertForbidden();
        $this->delete($this->base().'/catalog/categories/'.Category::query()->firstOrFail()->id)->assertForbidden();
        $this->put($this->base().'/catalog/units/'.Unit::query()->firstOrFail()->id, ['name' => 'Changed', 'symbol' => 'bad'])->assertForbidden();
        $this->assertTrue($item->fresh()->trashed());
    }

    public function test_price_controls_allow_corrections_and_protect_lists_assigned_to_customers(): void
    {
        $this->workspace();
        $list = PriceList::query()->create(['name' => 'Test list', 'channel' => 'custom', 'is_default' => false]);
        $item = Item::query()->firstOrFail();
        $price = PriceListItem::query()->create(['price_list_id' => $list->id, 'item_id' => $item->id, 'price' => 100]);
        $url = $this->base().'/catalog/price-lists/'.$list->id;
        $this->put($url, ['name' => 'Updated list'])->assertSessionHasNoErrors();
        $this->assertSame('Updated list', $list->fresh()->name);
        $this->put($this->base().'/catalog/prices/'.$price->id, ['price' => 120])->assertSessionHasNoErrors();
        $this->assertSame('120.0000', $price->fresh()->price);
        $this->delete($url)->assertSessionHasErrors('price_list');
        $this->delete($this->base().'/catalog/prices/'.$price->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('price_list_items', ['id' => $price->id], 'tenant');
        $customer = Customer::query()->create(['name' => 'Assigned customer', 'price_list_id' => $list->id]);
        $this->delete($url)->assertSessionHasErrors('price_list');
        $customer->update(['price_list_id' => null]);
        $this->delete($url)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('price_lists', ['id' => $list->id], 'tenant');
    }

    public function test_branch_details_can_be_edited_and_suspension_can_be_reversed(): void
    {
        $this->workspace();
        $branch = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER', 'status' => 'active']);
        $url = $this->base().'/settings/branches/'.$branch->id;
        $this->put($url, ['name' => 'Updated branch', 'address' => 'Test address', 'phone' => '12345'])->assertSessionHasNoErrors();
        $this->assertSame('Updated branch', $branch->fresh()->name);
        $this->post($url.'/suspend', ['status' => 'suspended', 'reason' => 'Temporary closure'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $branch->fresh()->status);
        $this->post($url.'/suspend', ['status' => 'active', 'reason' => 'Reopened'])->assertSessionHasNoErrors();
        $this->assertSame('active', $branch->fresh()->status);
    }

    private function workspace(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'records'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision(['name' => 'Record test', 'slug' => $this->slug, 'owner_name' => 'Owner', 'owner_email' => $this->slug.'@example.test', 'owner_password' => 'Password123!', 'industry_key' => 'bakery', 'sample' => true]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Password123!'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->actingAs(User::query()->firstOrFail());
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
