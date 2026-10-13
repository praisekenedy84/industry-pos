<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ProductionOrder;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeCostLine;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\RunInput;
use App\Models\RunOutput;
use App\Models\RunWaste;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\UnitConversion;
use App\Support\Bc;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BatchProduction
{
    public function __construct(private StockLedger $ledger, private DocumentNumbers $numbers, private AuditLogger $audit) {}

    /** @return array{material: ?string, labor: string, overhead: string, total: ?string, unit_cost: ?string, issues: list<string>} */
    public function estimateRecipe(RecipeVersion $version, string $branchId): array
    {
        return $this->estimateMixture([
            'lines' => RecipeLine::query()->where('recipe_version_id', $version->id)->get()->toArray(),
            'yield_qty' => $version->yield_qty,
            'labor' => RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'labor')->sum('amount'),
            'overhead' => RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'overhead')->sum('amount'),
        ], $branchId);
    }

    /**
     * @param  array{lines: array, yield_qty: mixed, labor?: mixed, overhead?: mixed}  $data
     * @return array{material: ?string, labor: string, overhead: string, total: ?string, unit_cost: ?string, issues: list<string>}
     */
    public function estimateMixture(array $data, string $branchId): array
    {
        $material = '0.0000';
        $issues = [];
        $lines = collect($data['lines'])->map(fn (array $line): object => (object) $line);
        $items = Item::withTrashed()->whereIn('id', $lines->pluck('item_id'))->get()->keyBy('id');
        foreach ($lines->groupBy('item_id') as $itemId => $ingredientLines) {
            $ingredient = $items->get($itemId);
            try {
                if (! $ingredient?->base_unit_id) {
                    throw ValidationException::withMessages(['cost' => 'Set a stock unit for this ingredient.']);
                }
                $quantity = '0.0000';
                foreach ($ingredientLines as $line) {
                    $quantity = Bc::add($quantity, $this->convert(Bc::num($line->qty), $line->unit_id ?? $ingredient->base_unit_id, $ingredient->base_unit_id));
                }
                $this->ledger->assertKnownCost($itemId, $branchId);
                if (Bc::cmp($this->ledger->onHand($itemId, $branchId), $quantity) < 0) {
                    throw ValidationException::withMessages(['cost' => 'Add ingredient stock at this branch to cost the full recipe.']);
                }
                $material = Bc::add($material, $this->ledger->valueForIssue($itemId, $branchId, $quantity));
            } catch (ValidationException $exception) {
                $issues[] = ($ingredient?->name ?? 'Ingredient').': '.collect($exception->errors())->flatten()->first();
            }
        }
        if ($lines->isEmpty() || Bc::cmp($data['yield_qty'], 0) <= 0) {
            $issues[] = 'Save ingredients and a positive expected output to calculate the recipe cost.';
        }
        $labor = Bc::num($data['labor'] ?? 0);
        $overhead = Bc::num($data['overhead'] ?? 0);
        $total = $issues ? null : Bc::add($material, Bc::add($labor, $overhead));

        return ['material' => $issues ? null : $material, 'labor' => $labor, 'overhead' => $overhead, 'total' => $total, 'unit_cost' => $total === null ? null : Bc::div($total, $data['yield_qty']), 'issues' => $issues];
    }

    /** @param array{item_id: string, branch_id: string, planned_qty?: mixed, notes?: ?string} $data */
    public function start(array $data): ProductionOrder
    {
        Validator::make($data, [
            'item_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
            'planned_qty' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($data): ProductionOrder {
            $this->lockBranch($data['branch_id']);
            if (! empty($data['request_id'])) {
                $existing = ProductionOrder::query()->where('branch_id', $data['branch_id'])->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    if ($existing->item_id !== $data['item_id'] || Bc::cmp($existing->planned_qty ?? 0, $data['planned_qty'] ?? 0) !== 0 || ($existing->notes ?? '') !== ($data['notes'] ?? '')) {
                        throw ValidationException::withMessages(['request_id' => 'This request already started a different batch.']);
                    }

                    return $existing;
                }
            }
            $item = Item::query()->lockForUpdate()->findOrFail($data['item_id']);
            if (! $item->track_stock || $item->type !== 'finished_good' || ! $item->base_unit_id) {
                throw ValidationException::withMessages(['item_id' => 'Choose a produced product with a stock unit. Use Add stock for traded goods.']);
            }
            $recipe = Recipe::query()->where('item_id', $item->id)->orderBy('created_at')->orderBy('id')->first();
            $version = $recipe ? RecipeVersion::query()->where('recipe_id', $recipe->id)->where('version', $recipe->current_version)->firstOrFail() : null;
            if (! $version) {
                throw ValidationException::withMessages(['item_id' => 'Add a recipe in Products before starting this batch.']);
            }
            $order = ProductionOrder::query()->create([
                'request_id' => $data['request_id'] ?? null,
                'number' => $this->numbers->next($data['branch_id'], 'batch'),
                'branch_id' => $data['branch_id'], 'item_id' => $item->id, 'output_unit_id' => $item->base_unit_id,
                'recipe_id' => $recipe?->id, 'recipe_version_id' => $version?->id,
                'planned_qty' => $data['planned_qty'] ?? null, 'notes' => $data['notes'] ?? null,
                'workflow_version' => 2, 'track_materials' => Setting::getValue('production_track_materials', true),
                'status' => 'in_progress', 'started_at' => now(), 'created_by' => Auth::id(), 'assignee_id' => Auth::id(),
            ]);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => null], ['status' => 'in_progress']);

            return $order;
        });
    }

    /** @param array{good_qty: mixed, waste_qty?: mixed, waste_unit_id?: ?string, waste_reason?: ?string, notes?: ?string} $data */
    public function complete(ProductionOrder $order, array $data): ProductionRun
    {
        Validator::make($data, [
            'good_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'waste_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'waste_unit_id' => ['nullable', 'uuid', 'exists:tenant.units,id'],
            'waste_reason' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'],
            'actual_usage' => ['nullable', 'array'],
            'actual_usage.*.recipe_line_id' => ['required', 'uuid', 'distinct'],
            'actual_usage.*.qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'labor_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'overhead_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999'],
            'waste_cost' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($order, $data): ProductionRun {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->lockBranch($order->branch_id);
            $good = Bc::num($data['good_qty']);
            $waste = Bc::num($data['waste_qty'] ?? 0);
            if (Bc::cmp($good, 0) <= 0 && Bc::cmp($waste, 0) <= 0) {
                throw ValidationException::withMessages(['good_qty' => 'Record some good output or waste.']);
            }
            $version = $order->recipe_version_id ? RecipeVersion::query()->findOrFail($order->recipe_version_id) : null;
            $itemId = $order->item_id ?? $version?->item_id ?? Recipe::withTrashed()->find($order->recipe_id)?->item_id;
            $item = Item::withTrashed()->findOrFail($itemId);
            $unitId = $order->output_unit_id ?? $item->base_unit_id;
            $wasteUnitId = $data['waste_unit_id'] ?? $unitId;
            $existing = ProductionRun::query()->where('production_order_id', $order->id)->first();
            $completionHash = $this->completionHash($data, $wasteUnitId);
            if ($order->status === 'reversed') {
                throw ValidationException::withMessages(['status' => 'This batch was reversed. Start a new batch to record corrected quantities.']);
            }
            if ($order->stock_posted_at) {
                if ($existing?->completion_hash && $existing->completion_hash !== $completionHash) {
                    throw ValidationException::withMessages(['good_qty' => 'Completed results cannot be edited. Reverse the batch to correct it.']);
                }
                $recordedWaste = $existing ? RunWaste::query()->where('production_run_id', $existing->id)->first() : null;
                if (! $existing || Bc::cmp($good, $existing->good_qty) !== 0 || Bc::cmp($waste, $existing->waste_qty) !== 0 || (Bc::cmp($waste, 0) > 0 && $wasteUnitId !== ($recordedWaste?->unit_id ?? $unitId))) {
                    throw ValidationException::withMessages(['good_qty' => 'Completed quantities cannot be edited. Use an auditable adjustment.']);
                }

                return $existing;
            }
            if (! in_array($order->status, ['planned', 'draft', 'on_hold', 'in_progress'], true)) {
                throw ValidationException::withMessages(['status' => 'Only unfinished batches can be completed.']);
            }
            if ($existing) {
                throw ValidationException::withMessages(['status' => 'This historical batch has recorded movements. Reconcile it before completing.']);
            }
            if ($item->base_unit_id !== $unitId || ! $unitId) {
                throw ValidationException::withMessages(['good_qty' => 'The product unit changed after this batch started. Restore it before completing.']);
            }
            $wasteEquivalent = $this->convert($waste, $wasteUnitId, $unitId, true);
            $total = $wasteEquivalent === null ? null : Bc::add($good, $wasteEquivalent);
            $tracksMaterials = $order->track_materials ?? Setting::getValue('production_track_materials', true);
            $treatment = Setting::getValue('production_waste_treatment', 'absorbed');
            if (! in_array($treatment, ['absorbed', 'expensed'], true)) {
                throw ValidationException::withMessages(['cost' => 'Choose a valid production waste treatment in settings.']);
            }
            if (Bc::cmp($good, 0) === 0) {
                $treatment = 'expensed';
            }
            $run = ProductionRun::query()->create([
                'production_order_id' => $order->id, 'started_at' => $order->started_at ?? now(), 'created_by' => Auth::id(),
                'completion_hash' => $completionHash,
            ]);
            $material = '0.0000';
            $labor = '0.0000';
            $overhead = '0.0000';
            if ($version) {
                if (Bc::cmp($version->yield_qty, 0) <= 0) {
                    throw ValidationException::withMessages(['cost' => 'The recipe needs a positive expected output quantity.']);
                }
                $lines = RecipeLine::query()->where('recipe_version_id', $version->id)->orderBy('item_id')->get();
                $actualUsage = collect($data['actual_usage'] ?? [])->keyBy('recipe_line_id');
                if ($actualUsage->keys()->diff($lines->pluck('id'))->isNotEmpty()) {
                    throw ValidationException::withMessages(['actual_usage' => 'Adjust only ingredients from the recipe captured by this batch.']);
                }
                foreach ($lines as $line) {
                    $input = Item::withTrashed()->findOrFail($line->item_id);
                    if (! $input->base_unit_id || ! $input->track_stock || $input->type === 'service' || $input->id === $item->id) {
                        throw ValidationException::withMessages(['cost' => 'Recipe materials must have units and differ from the output.']);
                    }
                    $override = $actualUsage->get($line->id)['qty'] ?? null;
                    if ($total === null && $override === null) {
                        throw ValidationException::withMessages(['waste_unit_id' => 'Define a waste conversion or enter actual usage for every ingredient, labour and overhead.']);
                    }
                    $planned = $total === null ? '0.0000' : bcdiv(bcmul((string) $line->qty, $total, 8), (string) $version->yield_qty, 4);
                    if ($override === null && Bc::cmp($planned, 0) <= 0) {
                        throw ValidationException::withMessages(['actual_usage' => 'An ingredient quantity rounds to zero. Enter actual usage explicitly or record a larger batch.']);
                    }
                    $plannedQty = $this->convert($planned, $line->unit_id ?? $input->base_unit_id, $input->base_unit_id);
                    $qty = $this->convert(Bc::num($override ?? $planned), $line->unit_id ?? $input->base_unit_id, $input->base_unit_id);
                    $unitCost = '0.0000';
                    $materialCost = '0.0000';
                    if (Bc::cmp($qty, 0) > 0) {
                        $this->ledger->assertKnownCost($input->id, $order->branch_id);
                        $materialCost = $this->ledger->valueForIssue($input->id, $order->branch_id, $qty);
                        $unitCost = Bc::div($materialCost, $qty);
                    }
                    if ($tracksMaterials && Bc::cmp($qty, 0) > 0) {
                        $movement = $this->ledger->post([
                            'item_id' => $input->id, 'branch_id' => $order->branch_id, 'type' => 'production_consume',
                            'quantity' => $qty, 'signed_qty' => Bc::mul($qty, -1),
                            'reference_type' => 'production_run', 'reference_id' => $run->id, 'user_id' => Auth::id(),
                            'reason' => 'Batch '.$order->number,
                        ]);
                        $unitCost = $movement->unit_cost;
                        $materialCost = $movement->total_cost;
                    }
                    $material = Bc::add($material, $materialCost);
                    RunInput::query()->create([
                        'production_run_id' => $run->id, 'item_id' => $input->id,
                        'planned_qty' => $plannedQty, 'actual_qty' => $qty, 'unit_cost' => $unitCost,
                    ]);
                }
                if ($total === null && (! isset($data['labor_cost'], $data['overhead_cost']) || (Bc::cmp($good, 0) > 0 && ! isset($data['waste_cost'])))) {
                    throw ValidationException::withMessages(['waste_unit_id' => 'Without a conversion, enter actual labour, overhead and the cost allocated to waste (zero is allowed).']);
                }
                $laborDefault = RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'labor')->sum('amount');
                $overheadDefault = RecipeCostLine::query()->where('recipe_version_id', $version->id)->where('type', 'overhead')->sum('amount');
                $labor = Bc::num($data['labor_cost'] ?? bcdiv(bcmul((string) $laborDefault, $total ?? '0', 8), (string) $version->yield_qty, 4));
                $overhead = Bc::num($data['overhead_cost'] ?? bcdiv(bcmul((string) $overheadDefault, $total ?? '0', 8), (string) $version->yield_qty, 4));
                $batchCost = Bc::add($material, Bc::add($labor, $overhead));
            } else {
                if ($total === null) {
                    throw ValidationException::withMessages(['waste_unit_id' => 'Define a unit conversion for this historical recipe-free batch.']);
                }
                if ($item->production_unit_cost === null || Bc::cmp($item->production_unit_cost, 0) < 0) {
                    throw ValidationException::withMessages(['cost' => 'Set a production unit cost on the product or add a costed recipe before completing.']);
                }
                $batchCost = Bc::mul($item->production_unit_cost, $total);
            }
            if (Bc::cmp($batchCost, 0) < 0 || Bc::cmp($batchCost, '99999999999999') > 0) {
                throw ValidationException::withMessages(['cost' => 'The batch cost is outside the allowed range.']);
            }
            $wasteCost = Bc::cmp($good, 0) === 0 ? $batchCost : ($total === null ? Bc::num($data['waste_cost']) : bcdiv(bcmul($batchCost, $wasteEquivalent, 8), $total, 4));
            if (Bc::cmp($wasteCost, $batchCost) > 0) {
                throw ValidationException::withMessages(['waste_cost' => 'Waste cost cannot exceed total batch cost.']);
            }
            $loss = $treatment === 'expensed' ? $wasteCost : '0.0000';
            $inventoryValue = Bc::sub($batchCost, $loss);
            $unitCost = Bc::cmp($good, 0) > 0 ? Bc::div($inventoryValue, $good) : '0.0000';
            $movement = null;
            if (Bc::cmp($good, 0) > 0) {
                $movement = $this->ledger->post([
                    'item_id' => $item->id, 'branch_id' => $order->branch_id, 'type' => 'production_output',
                    'quantity' => $good, 'signed_qty' => $good, 'unit_cost' => $unitCost, 'total_cost' => $inventoryValue,
                    'reference_type' => 'production_run', 'reference_id' => $run->id, 'user_id' => Auth::id(),
                    'lot_code' => $order->lot_code ?? $order->number, 'reason' => 'Good output '.$order->number,
                ]);
                RunOutput::query()->create([
                    'production_run_id' => $run->id, 'item_id' => $item->id, 'qty' => $good, 'unit_cost' => $unitCost,
                    'lot_id' => $movement->lot_id, 'stock_movement_id' => $movement->id,
                    'run_number' => $order->number, 'branch_id' => $order->branch_id,
                ]);
            }
            if (Bc::cmp($waste, 0) > 0) {
                RunWaste::query()->create([
                    'production_run_id' => $run->id, 'item_id' => $item->id, 'qty' => $waste,
                    'unit_id' => $wasteUnitId, 'output_equivalent_qty' => $wasteEquivalent, 'cost_value' => $wasteCost,
                    'reason' => $data['waste_reason'] ?? null, 'branch_id' => $order->branch_id,
                    'recorded_by' => Auth::id(), 'recorded_at' => now(),
                ]);
            }
            $run->update([
                'status' => 'completed', 'ended_at' => now(), 'good_qty' => $good, 'waste_qty' => $waste,
                'material_cost' => $material, 'labor_cost' => $labor, 'overhead_cost' => $overhead,
                'batch_cost' => $batchCost, 'unit_cost' => $unitCost, 'inventory_value' => $inventoryValue,
                'waste_cost' => $wasteCost, 'loss_value' => $loss, 'waste_treatment' => $treatment, 'notes' => $data['notes'] ?? null,
            ]);
            $journalId = $this->postJournal($order, $run, $tracksMaterials && $version ? $material : '0.0000');
            $previous = $order->status;
            $order->update([
                'status' => 'completed', 'actual_qty' => $good, 'completed_at' => now(), 'stock_posted_at' => now(),
                'item_id' => $item->id, 'output_unit_id' => $unitId, 'workflow_version' => 2,
                'started_at' => $order->started_at ?? now(), 'track_materials' => $tracksMaterials,
            ]);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => $previous], ['status' => 'completed']);
            $this->audit->tenant('production.completed', 'production_run', $run->id, null, [
                'good_qty' => $good, 'waste_qty' => $waste, 'waste_unit_id' => $wasteUnitId,
                'batch_cost' => $batchCost, 'inventory_value' => $inventoryValue, 'loss_value' => $loss,
                'stock_movement_id' => $movement?->id, 'journal_id' => $journalId,
            ]);

            return $run->fresh();
        });
    }

    public function reverse(ProductionOrder $order, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        DB::connection('tenant')->transaction(function () use ($order, $reason): void {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->lockBranch($order->branch_id);
            $database = DB::connection('tenant');
            $run = ProductionRun::query()->where('production_order_id', $order->id)->firstOrFail();
            $original = $database->table('production_journals')->where('production_run_id', $run->id)->where('kind', 'completion')->first();
            if ($order->status === 'reversed') {
                return;
            }
            if ($order->status !== 'completed' || ! $original) {
                throw ValidationException::withMessages(['status' => 'Only completed batches with a production journal can be reversed.']);
            }
            $output = RunOutput::query()->where('production_run_id', $run->id)->first();
            if ($output) {
                $lot = $output->lot_id ? StockLot::query()->lockForUpdate()->find($output->lot_id) : null;
                if (Bc::cmp($this->ledger->onHand($output->item_id, $order->branch_id), $output->qty) < 0 || ($output->lot_id && (! $lot || Bc::cmp($lot->qty, $output->qty) < 0))) {
                    throw ValidationException::withMessages(['stock' => 'Return or restore the batch stock before reversing. Some output has already been sold, transferred or consumed.']);
                }
                $this->ledger->post([
                    'item_id' => $output->item_id, 'branch_id' => $order->branch_id, 'type' => 'production_reversal',
                    'quantity' => $output->qty, 'signed_qty' => Bc::mul($output->qty, -1), 'unit_cost' => $output->unit_cost,
                    'total_cost' => $run->inventory_value, 'lot_id' => $output->lot_id,
                    'reference_type' => 'production_reversal', 'reference_id' => $run->id, 'user_id' => Auth::id(), 'reason' => $reason,
                ]);
            }
            foreach (StockMovement::query()->where('reference_type', 'production_run')->where('reference_id', $run->id)->where('type', 'production_consume')->orderBy('item_id')->get() as $movement) {
                $this->ledger->post([
                    'item_id' => $movement->item_id, 'branch_id' => $order->branch_id, 'type' => 'production_reversal',
                    'quantity' => $movement->quantity, 'signed_qty' => $movement->quantity, 'unit_cost' => $movement->unit_cost,
                    'total_cost' => $movement->total_cost, 'lot_code' => 'REV-'.$order->number,
                    'reference_type' => 'production_reversal', 'reference_id' => $run->id, 'user_id' => Auth::id(), 'reason' => $reason,
                ]);
            }
            $id = (string) Str::uuid();
            $database->table('production_journals')->insert([
                'id' => $id, 'production_run_id' => $run->id, 'branch_id' => $order->branch_id,
                'number' => 'REV-'.$original->number, 'kind' => 'reversal', 'reverses_id' => $original->id, 'reason' => $reason,
                'waste_treatment' => $original->waste_treatment, 'batch_cost' => Bc::mul($original->batch_cost, -1),
                'inventory_value' => Bc::mul($original->inventory_value, -1), 'loss_value' => Bc::mul($original->loss_value, -1),
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($database->table('production_journal_lines')->where('production_journal_id', $original->id)->get() as $line) {
                $database->table('production_journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'production_journal_id' => $id, 'account_code' => $line->account_code,
                    'purpose' => $line->purpose, 'debit' => $line->credit, 'credit' => $line->debit,
                ]);
            }
            $order->update(['status' => 'reversed']);
            $this->audit->tenant('production.status_changed', 'production_order', $order->id, ['status' => 'completed'], ['status' => 'reversed', 'notes' => $reason]);
            $this->audit->tenant('production.reversed', 'production_run', $run->id, null, ['reason' => $reason, 'journal_id' => $id]);
        });
    }

    private function lockBranch(string $branchId): void
    {
        $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);
        if ($branch->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'This branch cannot transact.']);
        }
    }

    private function convert(string $quantity, string $from, string $to, bool $allowMissing = false): ?string
    {
        if ($from === $to || Bc::cmp($quantity, 0) === 0) {
            return $quantity;
        }
        $conversion = UnitConversion::query()->where('from_unit_id', $from)->where('to_unit_id', $to)->first();
        $reverse = false;
        if (! $conversion) {
            $conversion = UnitConversion::query()->where('from_unit_id', $to)->where('to_unit_id', $from)->first();
            $reverse = true;
        }
        if (! $conversion || bccomp((string) $conversion->factor, '0', 6) <= 0) {
            if ($allowMissing) {
                return null;
            }
            throw ValidationException::withMessages(['waste_unit_id' => 'Define a valid unit conversion before combining these quantities.']);
        }
        $converted = $reverse ? bcdiv($quantity, (string) $conversion->factor, 4) : bcmul($quantity, (string) $conversion->factor, 4);
        if (Bc::cmp($converted, 0) <= 0) {
            throw ValidationException::withMessages(['waste_unit_id' => 'The converted quantity is too small to record.']);
        }

        return $converted;
    }

    /** @param array<string, mixed> $data */
    private function completionHash(array $data, string $wasteUnitId): string
    {
        $usage = collect($data['actual_usage'] ?? [])->filter(fn (array $line): bool => isset($line['qty']))
            ->mapWithKeys(fn (array $line): array => [$line['recipe_line_id'] => Bc::num($line['qty'])])->sortKeys()->all();

        return hash('sha256', json_encode([
            Bc::num($data['good_qty']), Bc::num($data['waste_qty'] ?? 0), $wasteUnitId,
            $data['waste_reason'] ?? null, $data['notes'] ?? null, $usage,
            isset($data['labor_cost']) ? Bc::num($data['labor_cost']) : null,
            isset($data['overhead_cost']) ? Bc::num($data['overhead_cost']) : null,
            isset($data['waste_cost']) ? Bc::num($data['waste_cost']) : null,
        ], JSON_THROW_ON_ERROR));
    }

    private function postJournal(ProductionOrder $order, ProductionRun $run, string $consumedMaterialCost): string
    {
        $id = (string) Str::uuid();
        $database = DB::connection('tenant');
        $database->table('production_journals')->insert([
            'id' => $id, 'production_run_id' => $run->id, 'branch_id' => $order->branch_id,
            'number' => 'PJ-'.$order->id, 'waste_treatment' => $run->waste_treatment,
            'batch_cost' => $run->batch_cost, 'inventory_value' => $run->inventory_value, 'loss_value' => $run->loss_value,
            'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        $entries = [
            ['finished_inventory', 'debit', $run->inventory_value],
            ['production_loss', 'debit', $run->loss_value],
            ['raw_material_inventory', 'credit', $consumedMaterialCost],
            ['production_clearing', 'credit', Bc::sub($run->batch_cost, $consumedMaterialCost)],
        ];
        foreach ($entries as [$purpose, $side, $amount]) {
            if (Bc::cmp($amount, 0) <= 0) {
                continue;
            }
            $account = Setting::getValue('production_account_'.$purpose, $purpose);
            if (! is_string($account) || trim($account) === '') {
                throw ValidationException::withMessages(['cost' => 'Configure the '.$purpose.' account before completing.']);
            }
            $database->table('production_journal_lines')->insert([
                'id' => (string) Str::uuid(), 'production_journal_id' => $id, 'account_code' => $account,
                'purpose' => $purpose, 'debit' => $side === 'debit' ? $amount : 0, 'credit' => $side === 'credit' ? $amount : 0,
            ]);
        }

        return $id;
    }
}
