<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Item;
use App\Models\RecipeLine;
use App\Models\Role;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Services\AccessManager;
use App\Services\StockReceipt;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitCatalogTest extends TestCase
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

    public function test_added_unit_can_be_used_as_a_stock_unit_and_in_a_recipe(): void
    {
        $this->workspace();
        $kg = Unit::query()->where('symbol', 'kg')->sole();
        $piece = Unit::query()->where('symbol', 'pc')->sole();

        $this->post($this->base().'/catalog/units', [
            'name' => 'Tonne',
            'symbol' => 't',
            'from_unit_id' => $kg->id,
            'factor' => '0.001',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $tonne = Unit::query()->where('symbol', 't')->sole();
        $this->assertSame('0.001000', UnitConversion::query()->where('from_unit_id', $kg->id)->where('to_unit_id', $tonne->id)->value('factor'));
        $page = $this->get($this->base().'/catalog/units', $this->headers())->assertOk()->json('props');
        $this->assertTrue(collect($page['units'])->contains(fn (array $unit): bool => $unit['symbol'] === 't' && $unit['name'] === 'Tonne'));
        $this->assertTrue(collect($page['conversions'])->contains(fn (array $row): bool => $row['from_symbol'] === 'kg' && $row['to_symbol'] === 't' && $row['factor'] === '0.001000'));

        $this->post($this->base().'/catalog/items', [
            'name' => 'Cement',
            'type' => 'raw_material',
            'base_unit_id' => $tonne->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $cement = Item::query()->where('name', 'Cement')->sole();
        $this->assertSame($tonne->id, $cement->base_unit_id);
        app(StockReceipt::class)->receive($cement, $this->branch(), [
            'request_id' => (string) Str::uuid(),
            'qty' => 1,
            'unit_cost' => 1000,
        ]);

        $this->withCredentials()->postJson($this->base().'/catalog/recipe-estimate', [
            'yield_qty' => 1,
            'lines' => [['item_id' => $cement->id, 'qty' => 2, 'unit_id' => $kg->id]],
        ])->assertOk()->assertJsonPath('total', '2.0000');
        $this->post($this->base().'/catalog/items', [
            'name' => 'Cement mix',
            'type' => 'finished_good',
            'base_unit_id' => $piece->id,
            'default_price' => 500,
            'recipe' => ['yield_qty' => 1, 'lines' => [['item_id' => $cement->id, 'qty' => 2, 'unit_id' => $kg->id]]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($kg->id, RecipeLine::query()->where('item_id', $cement->id)->sole()->unit_id);
    }

    public function test_invalid_units_and_repeated_conversions_are_rejected(): void
    {
        $this->workspace();
        $kg = Unit::query()->where('symbol', 'kg')->sole();
        $gram = Unit::query()->where('symbol', 'g')->sole();

        $this->post($this->base().'/catalog/units', [])->assertSessionHasErrors([
            'name' => 'Enter a unit name.',
            'symbol' => 'Enter a unit symbol.',
        ]);
        $this->post($this->base().'/catalog/units', [
            'name' => 'Bag',
            'symbol' => 'bag',
            'from_unit_id' => $kg->id,
        ])->assertSessionHasErrors(['factor' => 'Enter how many of the new unit equal one of the selected unit.']);
        $this->assertDatabaseMissing('units', ['symbol' => 'bag'], 'tenant');
        $this->post($this->base().'/catalog/units', [
            'name' => 'Bag',
            'symbol' => 'bag',
            'from_unit_id' => $kg->id,
            'factor' => 0,
        ])->assertSessionHasErrors(['factor' => 'Enter a conversion greater than zero.']);
        $this->post($this->base().'/catalog/units', ['name' => 'Kilogram again', 'symbol' => 'KG'])->assertSessionHasErrors(['symbol' => 'A unit with this symbol already exists.']);
        $this->post($this->base().'/catalog/units', ['name' => 'Long symbol', 'symbol' => 'dozen-of-eggs'])->assertSessionHasErrors(['symbol' => 'Use a symbol of 12 characters or fewer.']);

        $this->post($this->base().'/catalog/units/conversions', [
            'from_unit_id' => $kg->id,
            'to_unit_id' => $gram->id,
            'factor' => 1,
        ])->assertSessionHasErrors(['factor' => 'These units already have a conversion.']);
        $this->post($this->base().'/catalog/units/conversions', [
            'from_unit_id' => $gram->id,
            'to_unit_id' => $kg->id,
            'factor' => 1,
        ])->assertSessionHasErrors(['factor' => 'These units already have a conversion.']);
        $this->post($this->base().'/catalog/units/conversions', [
            'from_unit_id' => $kg->id,
            'to_unit_id' => $kg->id,
            'factor' => 1,
        ])->assertSessionHasErrors(['from_unit_id' => 'Choose two different units.']);
    }

    public function test_conversion_between_existing_units_can_be_used_for_a_recipe_quantity(): void
    {
        $this->workspace();
        $piece = Unit::query()->where('symbol', 'pc')->sole();
        $this->post($this->base().'/catalog/units', ['name' => 'Dozen', 'symbol' => 'dz'])->assertSessionHasNoErrors();
        $dozen = Unit::query()->where('symbol', 'dz')->sole();

        $this->post($this->base().'/catalog/units/conversions', [
            'from_unit_id' => $dozen->id,
            'to_unit_id' => $piece->id,
            'factor' => 12,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('12.000000', UnitConversion::query()->where('from_unit_id', $dozen->id)->where('to_unit_id', $piece->id)->value('factor'));

        $this->post($this->base().'/catalog/items', [
            'name' => 'Eggs',
            'type' => 'raw_material',
            'base_unit_id' => $piece->id,
        ])->assertSessionHasNoErrors();
        $eggs = Item::query()->where('name', 'Eggs')->sole();
        app(StockReceipt::class)->receive($eggs, $this->branch(), [
            'request_id' => (string) Str::uuid(),
            'qty' => 24,
            'unit_cost' => 100,
        ]);
        $this->withCredentials()->postJson($this->base().'/catalog/recipe-estimate', [
            'yield_qty' => 1,
            'lines' => [['item_id' => $eggs->id, 'qty' => 1, 'unit_id' => $dozen->id]],
        ])->assertOk()->assertJsonPath('total', '1200.0000');
    }

    public function test_recipe_rejects_a_unit_without_a_conversion_to_the_ingredient(): void
    {
        $this->workspace();
        $kg = Unit::query()->where('symbol', 'kg')->sole();
        $piece = Unit::query()->where('symbol', 'pc')->sole();
        $this->post($this->base().'/catalog/units', ['name' => 'Crate', 'symbol' => 'crate'])->assertSessionHasNoErrors();
        $crate = Unit::query()->where('symbol', 'crate')->sole();
        $this->post($this->base().'/catalog/items', [
            'name' => 'Crated flour',
            'type' => 'raw_material',
            'base_unit_id' => $crate->id,
        ])->assertSessionHasNoErrors();
        $flour = Item::query()->where('name', 'Crated flour')->sole();

        $this->post($this->base().'/catalog/items', [
            'name' => 'Bad mix',
            'type' => 'finished_good',
            'base_unit_id' => $piece->id,
            'default_price' => 10,
            'recipe' => ['yield_qty' => 1, 'lines' => [['item_id' => $flour->id, 'qty' => 1, 'unit_id' => $kg->id]]],
        ])->assertSessionHasErrors(['lines' => 'Choose the ingredient stock unit or define a valid conversion first.']);
        $this->assertDatabaseMissing('items', ['name' => 'Bad mix'], 'tenant');
    }

    public function test_cashier_cannot_add_a_unit(): void
    {
        $this->workspace();
        $owner = User::query()->firstOrFail();
        UserBranchRole::query()->where('user_id', $owner->id)->update([
            'role_id' => Role::query()->where('slug', 'cashier')->value('id'),
        ]);
        app(AccessManager::class)->forget($owner);

        $this->post($this->base().'/catalog/units', ['name' => 'Sack', 'symbol' => 'sack'])->assertForbidden();
        $this->assertDatabaseMissing('units', ['symbol' => 'sack'], 'tenant');
    }

    private function workspace(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->slug = 'units'.strtolower(Str::random(8));
        app(TenantProvisioner::class)->provision([
            'name' => 'Unit catalog',
            'slug' => $this->slug,
            'owner_name' => 'Owner',
            'owner_email' => $this->slug.'@example.test',
            'owner_password' => 'Password123!',
            'industry_key' => 'bakery',
            'sample' => true,
        ]);
        $response = $this->post('/login', ['email' => $this->slug.'@example.test', 'password' => 'Password123!'])->assertRedirect()->assertSessionHasNoErrors();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->actingAs(User::query()->firstOrFail());
    }

    private function branch(): string
    {
        return Branch::query()->where('code', 'MAIN')->value('id');
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
