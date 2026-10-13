<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Support\Bc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    use ResolvesBranch;

    public function __construct(private AccessManager $access, private AuditLogger $audit, private DocumentNumbers $numbers) {}

    public function index(Request $request): Response
    {
        $this->authorize('expenses.view');
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['nullable', Rule::in(array_keys(Expense::categories()))], 'status' => ['nullable', Rule::in(['recorded', 'void'])],
        ]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '') + ['from' => now('Africa/Dar_es_Salaam')->startOfMonth()->toDateString(), 'to' => now('Africa/Dar_es_Salaam')->toDateString(), 'category' => '', 'status' => 'recorded'];
        $query = Expense::query()->where('branch_id', $this->branchId())->whereIn('branch_id', $this->access->visibleBranchIds($request->user(), 'expenses.view'))->whereBetween('expense_date', [$filters['from'], $filters['to']]);
        if ($this->access->ownOnly($request->user(), 'expenses.view')) {
            $query->where('created_by', $request->user()->id);
        }
        if ($filters['category']) {
            $query->where('category', $filters['category']);
        }
        $total = (clone $query)->where('status', 'recorded')->sum('amount');
        $breakdown = (clone $query)->where('status', 'recorded')->selectRaw('category, SUM(amount) as amount')->groupBy('category')->orderByDesc('amount')->get();
        $query->where('status', $filters['status']);

        return Inertia::render('Expenses/Index', [
            'expenses' => $query->orderByDesc('expense_date')->orderByDesc('created_at')->paginate(20)->withQueryString()->through(fn (Expense $expense) => $expense->setAttribute('can_void', $this->access->allows($request->user(), 'expenses.void', $expense))),
            'total' => Bc::num($total), 'filters' => $filters, 'categories' => Expense::categories(),
            'breakdown' => $breakdown,
            'methods' => PaymentMethod::query()->where('enabled', true)->get(['key', 'name', 'requires_reference']),
            'today' => now('Africa/Dar_es_Salaam')->toDateString(),
            'can' => ['create' => $this->access->allows($request->user(), 'expenses.create'), 'void' => $this->access->allows($request->user(), 'expenses.void')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $branchId = $this->branchId();
        $this->authorize('expenses.create', (object) ['branch_id' => $branchId, 'created_by' => $request->user()->id]);
        $this->assertBranchCanTransact($branchId);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'category' => ['required', Rule::in(array_keys(Expense::categories()))],
            'description' => ['required', 'string', 'max:500'], 'payee' => ['nullable', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'payment_method' => ['required', Rule::exists('tenant.payment_methods', 'key')->where('enabled', true)],
            'reference' => ['nullable', 'string', 'max:200'],
        ]);
        $method = PaymentMethod::query()->where('key', $data['payment_method'])->firstOrFail();
        if ($method->requires_reference && empty($data['reference'])) {
            throw ValidationException::withMessages(['reference' => 'A payment reference is required for this method.']);
        }
        $data['amount'] = Bc::num($data['amount']);
        DB::connection('tenant')->transaction(function () use ($request, $branchId, $data): void {
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            $existing = Expense::query()->where('branch_id', $branchId)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ($existing->created_by !== $request->user()->id || collect($data)->except('request_id')->contains(fn ($value, $key) => ($key === 'expense_date' ? $existing->expense_date->toDateString() : $existing->{$key}) != $value)) {
                    throw ValidationException::withMessages(['request_id' => 'This submission was already recorded with different details. Start a new expense.']);
                }

                return;
            }
            $expense = Expense::query()->create($data + ['number' => $this->numbers->next($branchId, 'expense'), 'branch_id' => $branchId, 'created_by' => $request->user()->id, 'status' => 'recorded']);
            $this->audit->tenant('expense.recorded', 'expense', $expense->id, null, $expense->toArray());
        });

        return back()->with('success', 'Expense recorded.');
    }

    public function void(Request $request, string $workspace, Expense $expense): RedirectResponse
    {
        abort_unless($expense->branch_id === $this->branchId(), 404);
        $this->authorize('expenses.void', $expense);
        $this->assertBranchCanTransact($expense->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::connection('tenant')->transaction(function () use ($request, $expense, $data): void {
            $record = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($record->status === 'void') {
                return;
            }
            $before = $record->toArray();
            $record->update(['status' => 'void', 'void_reason' => $data['reason'], 'voided_by' => $request->user()->id, 'voided_at' => now()]);
            $this->audit->tenant('expense.voided', 'expense', $record->id, $before, $record->toArray());
        });

        return back()->with('success', 'Expense voided and excluded from totals.');
    }
}
