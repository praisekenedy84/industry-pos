<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Models\UserBranchRole;
use App\Services\AccessManager;
use App\Tenancy\TenancyManager;
use App\Tenancy\TenantProvisioner;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'UTC'));
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

    public function test_recording_is_decimal_audited_and_idempotent(): void
    {
        $this->workspace('expenses');
        $payload = $this->payload();
        $this->from('/w/expenses/expenses')->post('/w/expenses/expenses', $payload + ['status' => 'void', 'branch_id' => Str::uuid()])->assertRedirect('/w/expenses/expenses')->assertSessionHasNoErrors();
        $this->post('/w/expenses/expenses', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::query()->count());
        $expense = Expense::query()->firstOrFail();
        $this->assertSame('12500.5000', $expense->amount);
        $this->assertSame('recorded', $expense->status);
        $this->assertSame(Branch::query()->firstOrFail()->id, $expense->branch_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'expense.recorded')->count());
        $this->get('/w/expenses/expenses')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Expenses/Index')->has('expenses.data', 1)->where('total', '12500.5000')->has('breakdown', 1));
        $this->post('/w/expenses/expenses', array_replace($payload, ['amount' => '40']))->assertSessionHasErrors('request_id');
        $this->assertSame(1, Expense::query()->count());
    }

    public function test_invalid_expenses_and_disabled_payment_methods_are_rejected(): void
    {
        $this->workspace('expensevalidation');
        foreach (['0', '-1', '1.001', '1000000000000'] as $amount) {
            $this->post('/w/expensevalidation/expenses', array_replace($this->payload(), ['amount' => $amount]))->assertSessionHasErrors('amount');
        }
        $this->post('/w/expensevalidation/expenses', array_replace($this->payload(), ['expense_date' => '2026-10-13', 'category' => 'inventory', 'payment_method' => 'missing']))->assertSessionHasErrors(['expense_date', 'category', 'payment_method']);
        PaymentMethod::query()->where('key', 'cash')->update(['requires_reference' => true]);
        $this->post('/w/expensevalidation/expenses', $this->payload())->assertSessionHasErrors('reference');
        PaymentMethod::query()->where('key', 'cash')->update(['enabled' => false]);
        $this->post('/w/expensevalidation/expenses', $this->payload())->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_void_preserves_the_record_and_removes_it_from_totals(): void
    {
        $this->workspace('expensevoid');
        $expense = $this->expense();
        $url = '/w/expensevoid/expenses/'.$expense->id.'/void';
        $this->get('/w/expensevoid/reports/profit.operating?from=2026-10-12&to=2026-10-12')->assertInertia(fn (AssertableInertia $page) => $page->where('result.rows.0.operating_result', '-12500.5000'));
        $this->post($url, [])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'Duplicate bill'])->assertSessionHasNoErrors();
        $this->post($url, ['reason' => 'Another reason'])->assertSessionHasNoErrors();
        $this->assertSame('Duplicate bill', $expense->fresh()->void_reason);
        $this->assertSame('void', $expense->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'expense.voided')->count());
        $this->get('/w/expensevoid/expenses?status=void')->assertInertia(fn (AssertableInertia $page) => $page->where('total', '0.0000')->has('expenses.data', 1));
        $this->get('/w/expensevoid/reports/expenses.categories?from=2026-10-12&to=2026-10-12')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('result.rows', 0));
        $this->get('/w/expensevoid/reports/profit.operating?from=2026-10-12&to=2026-10-12')->assertInertia(fn (AssertableInertia $page) => $page->where('result.rows.0.operating_result', '0.0000'));
    }

    public function test_reports_deduct_only_recorded_expenses_for_the_branch_and_period(): void
    {
        $this->workspace('expenseprofit');
        $branch = Branch::query()->firstOrFail();
        $item = Item::query()->create(['name' => 'Consultation', 'type' => 'service', 'sku' => 'CONSULT']);
        $sale = Sale::query()->create(['branch_id' => $branch->id, 'user_id' => User::query()->firstOrFail()->id, 'status' => 'completed', 'total' => '200']);
        SaleLine::query()->create(['sale_id' => $sale->id, 'item_id' => $item->id, 'qty' => 2, 'unit_price' => 100, 'net' => 200, 'gross' => 200, 'unit_cost' => 40]);
        $recorded = $this->expense(['amount' => '50.10']);
        $this->expense(['amount' => '900', 'status' => 'void']);
        $outside = $this->expense(['amount' => '800', 'expense_date' => '2026-10-11']);
        $other = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER']);
        $foreign = $this->expense(['amount' => '700', 'branch_id' => $other->id]);
        $query = '?from=2026-10-12&to=2026-10-12';
        $this->get('/w/expenseprofit/reports/profit.operating'.$query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('result.rows.0.revenue', '200.0000')->where('result.rows.0.cost_of_goods', '80.0000')->where('result.rows.0.gross_profit', '120.0000')->where('result.rows.0.operating_expenses', '50.1000')->where('result.rows.0.operating_result', '69.9000'));
        $this->get('/w/expenseprofit/reports/expenses.categories'.$query)->assertInertia(fn (AssertableInertia $page) => $page->has('result.rows', 1)->where('result.rows.0.amount', '50.1000'));
        $csv = $this->get('/w/expenseprofit/reports/expenses.register/export'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString($recorded->number, $csv);
        $this->assertStringContainsString('50.1000', $csv);
        $this->assertStringNotContainsString($outside->number, $csv);
        $this->assertStringNotContainsString($foreign->number, $csv);
    }

    public function test_branch_permissions_protect_records_and_report_exports(): void
    {
        $this->workspace('expenseaccess');
        $user = User::query()->firstOrFail();
        $other = Branch::query()->create(['name' => 'Other', 'code' => 'OTHER']);
        $expense = $this->expense(['branch_id' => $other->id]);
        UserBranchRole::query()->where('user_id', $user->id)->update(['role_id' => Role::query()->where('slug', 'branch_manager')->value('id')]);
        app(AccessManager::class)->forget($user);
        $this->get('/w/expenseaccess/reports/expenses.register?branch_id='.$other->id)->assertForbidden();
        $this->get('/w/expenseaccess/reports/profit.operating/export?branch_id='.$other->id)->assertForbidden();
        $this->post('/w/expenseaccess/expenses/'.$expense->id.'/void', ['reason' => 'Forged branch'])->assertNotFound();
        UserBranchRole::query()->where('user_id', $user->id)->update(['role_id' => Role::query()->where('slug', 'cashier')->value('id')]);
        app(AccessManager::class)->forget($user);
        $this->get('/w/expenseaccess/expenses')->assertForbidden();
        $this->post('/w/expenseaccess/expenses', $this->payload())->assertForbidden();
        $this->get('/w/expenseaccess/reports/expenses.register/export')->assertForbidden();
    }

    public function test_workspaces_are_isolated_and_suspended_branches_cannot_spend(): void
    {
        $this->workspace('expensefirst');
        $expenseId = $this->expense()->id;
        $this->workspace('expensesecond');
        $this->post('/w/expensesecond/expenses/'.$expenseId.'/void', ['reason' => 'Wrong workspace'])->assertNotFound();
        Branch::query()->firstOrFail()->update(['status' => 'suspended']);
        $this->post('/w/expensesecond/expenses', $this->payload())->assertForbidden();
        $this->assertSame(0, Expense::query()->count());
    }

    private function workspace(string $slug): void
    {
        app(TenantProvisioner::class)->provision(['name' => ucfirst($slug), 'slug' => $slug, 'owner_name' => 'Owner', 'owner_email' => $slug.'@example.test', 'owner_password' => 'Makerly#2026', 'industry_key' => 'bakery', 'sample' => false]);
        $response = $this->post('/login', ['email' => $slug.'@example.test', 'password' => 'Makerly#2026']);
        $response->assertRedirect();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'expense_date' => '2026-10-12', 'category' => 'utilities', 'description' => 'Electricity bill', 'amount' => '12500.50', 'payment_method' => 'cash'];
    }

    private function expense(array $attributes = []): Expense
    {
        return Expense::factory()->create($attributes + ['branch_id' => Branch::query()->firstOrFail()->id, 'created_by' => User::query()->firstOrFail()->id]);
    }
}
