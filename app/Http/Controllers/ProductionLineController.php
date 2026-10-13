<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\ProductionLine;
use App\Models\Recipe;
use App\Models\StockBalance;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductionLineController extends Controller
{
    use ResolvesBranch;

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('production.orders.view');

        return Inertia::render('Production/Lines', [
            'lines' => ProductionLine::query()->where('branch_id', $this->branchId())
                ->with('recipe.item:id,name,sku')
                ->withCount([
                    'orders as planned_count' => fn ($query) => $query->where('status', 'planned'),
                    'orders as running_count' => fn ($query) => $query->where('status', 'in_progress'),
                    'orders as completed_count' => fn ($query) => $query->where('status', 'completed'),
                ])->orderBy('name')->get(),
            'recipes' => Recipe::query()->whereHas('item', fn ($query) => $query->where('track_stock', true)->where('type', '!=', 'service')->whereNotNull('base_unit_id'))
                ->with('item:id,name,sku')->orderBy('name')->get(),
            'stock' => StockBalance::query()->where('branch_id', $this->branchId())->whereNull('lot_id')->pluck('qty', 'item_id'),
            'canManage' => $request->user()->can('production.orders.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('production.orders.manage');
        $this->assertBranchCanTransact();
        $line = ProductionLine::query()->create([
            ...$this->validated($request),
            'branch_id' => $this->branchId(),
        ]);
        $this->audit->tenant('production.line.created', 'production_line', $line->id, null, $line->toArray());

        return back()->with('success', 'Production line created. Plan a batch to start making products.');
    }

    public function update(Request $request, string $workspace, ProductionLine $line): RedirectResponse
    {
        $this->authorize('production.orders.manage', $line);
        abort_unless($line->branch_id === $this->branchId(), 404);
        $this->assertBranchCanTransact();
        $data = $this->validated($request, $line);
        $before = $line->toArray();
        $line->update($data);
        $this->audit->tenant('production.line.updated', 'production_line', $line->id, $before, $line->toArray());

        return back()->with('success', 'Production line updated.');
    }

    private function validated(Request $request, ?ProductionLine $line = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', Rule::unique('tenant.production_lines')->where('branch_id', $this->branchId())->ignore($line?->id)],
            'recipe_id' => ['bail', 'required', 'uuid', Rule::exists('tenant.recipes', 'id')->whereNull('deleted_at'), function (string $attribute, mixed $value, \Closure $fail) use ($line): void {
                $recipe = Recipe::query()->with('item')->find($value);
                if ($recipe && (! $recipe->item?->track_stock || $recipe->item?->type === 'service' || ! $recipe->item?->base_unit_id)) {
                    $fail('Choose a bill of materials for a stocked good with a unit of measure.');
                }
                if ($line && $line->recipe_id !== $value && $line->orders()->whereIn('status', ['planned', 'in_progress', 'on_hold'])->exists()) {
                    $fail('Complete or cancel open batches before changing the line recipe.');
                }
            }],
            'status' => ['required', 'in:active,inactive'],
            'daily_capacity' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
