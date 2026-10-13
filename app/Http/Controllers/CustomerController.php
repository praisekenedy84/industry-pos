<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PriceList;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Support\Bc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(private AuditLogger $audit, private FeatureGate $features) {}

    public function index(Request $request): Response
    {
        $this->authorize('customers.view');
        $q = $request->string('q')->toString();

        return Inertia::render('Customers/Index', [
            'customers' => Customer::withTrashed()->when($q, fn ($query) => $query->where('name', 'ilike', "%{$q}%"))->orderBy('name')->paginate(20),
            'canManage' => $request->user()->can('customers.manage'),
            'lists' => PriceList::query()->get(),
            'credit' => $this->features->active('customers.credit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('customers.manage');
        $data = $this->validated($request);
        if (Bc::cmp($data['credit_limit'] ?? 0, 0) > 0) {
            $this->authorize('customers.credit');
        }
        $customer = DB::connection('tenant')->transaction(function () use ($data, $request): Customer {
            $customer = Customer::query()->create([...$data, 'created_by' => $request->user()->id]);
            $this->audit->tenant('customer.created', 'customer', $customer->id);

            return $customer;
        });

        return redirect()->route('customers.show', $customer);
    }

    public function show(string $workspace, Customer $customer): Response
    {
        $this->authorize('customers.view');

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'canManage' => auth()->user()->can('customers.manage'),
            'payments' => CustomerPayment::query()->where('customer_id', $customer->id)->latest('created_at')->limit(30)->get(),
            'sales' => Sale::query()->where('customer_id', $customer->id)->latest()->limit(30)->get(),
            'lists' => PriceList::query()->get(),
            'credit' => $this->features->active('customers.credit'),
        ]);
    }

    public function update(Request $request, string $workspace, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.manage');
        if ($request->filled('credit_limit') && is_numeric($request->input('credit_limit')) && Bc::cmp($request->input('credit_limit'), $customer->credit_limit ?? 0) !== 0) {
            $this->authorize('customers.credit');
        }
        $data = $this->validated($request);
        DB::connection('tenant')->transaction(function () use ($customer, $data): void {
            $before = $customer->toArray();
            $customer->update($data);
            $this->audit->tenant('customer.updated', 'customer', $customer->id, $before, $customer->fresh()->toArray());
        });

        return back()->with('success', 'Customer updated.');
    }

    public function payment(Request $request, string $workspace, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.manage');
        abort_unless($this->features->active('customers.credit'), 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string'],
            'reference' => ['nullable', 'string'],
        ]);
        CustomerPayment::query()->create([
            'customer_id' => $customer->id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'user_id' => $request->user()->id,
        ]);
        $customer->balance = Bc::sub($customer->balance, $data['amount']);
        $customer->save();

        return back()->with('success', 'Payment recorded.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'group_name' => ['nullable', 'string'],
            'price_list_id' => ['nullable', 'uuid', 'exists:tenant.price_lists,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        if (($data['credit_limit'] ?? null) === null) {
            unset($data['credit_limit']);
        }

        return $data;
    }

    public function destroy(string $workspace, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.manage');
        DB::connection('tenant')->transaction(function () use ($customer): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if (Bc::cmp($customer->balance ?? 0, 0) !== 0) {
                throw ValidationException::withMessages(['customer' => 'Settle the customer balance before archiving.']);
            }
            $customer->delete();
            $this->audit->tenant('customer.archived', 'customer', $customer->id);
        });

        return redirect()->route('customers')->with('success', 'Customer archived. Sales history is retained.');
    }

    public function restore(string $workspace, Customer $customer): RedirectResponse
    {
        $this->authorize('customers.manage');
        DB::connection('tenant')->transaction(function () use ($customer): void {
            $customer->restore();
            $this->audit->tenant('customer.restored', 'customer', $customer->id);
        });

        return back()->with('success', 'Customer restored.');
    }
}
