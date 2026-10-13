<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBranch;
use App\Models\ReportSubscription;
use App\Models\ReportView;
use App\Reports\ReportRegistry;
use App\Services\AccessManager;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use ResolvesBranch;

    public function __construct(private ReportRegistry $reports, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $catalog = collect($this->reports->catalog())->filter(fn ($report) => $request->user()->can($report['permission']))->values();

        return Inertia::render('Reports/Index', ['reports' => $catalog]);
    }

    public function show(Request $request, string $workspace, string $report): Response
    {
        $meta = $this->reports->find($report);
        abort_unless($meta, 404);
        $this->authorize($meta['permission']);
        $filters = $this->reportFilters($request, $meta);
        $result = $this->reports->run($report, $filters);

        return Inertia::render('Reports/Show', [
            'report' => $meta,
            'result' => $result,
            'filters' => $filters,
            'saved' => ReportView::query()->where('user_id', $request->user()->id)->where('report_key', $report)->get(),
        ]);
    }

    public function export(Request $request, string $workspace, string $report): StreamedResponse
    {
        $meta = $this->reports->find($report);
        abort_unless($meta, 404);
        $this->authorize($meta['permission']);
        $exportPermission = str_contains($meta['permission'], 'margin') ? 'reports.margin.export' : 'reports.sales.export';
        if ($request->user()->can($exportPermission) || $request->user()->can('settings.export')) {
            $this->authorize($exportPermission);
        } else {
            $this->authorize($meta['permission']);
        }
        $format = $request->string('format', 'csv');
        $result = $this->reports->run($report, $this->reportFilters($request, $meta));
        $this->audit->tenant('report.exported', 'report', null, null, ['report' => $report, 'format' => (string) $format]);
        $filename = $report.'.'.($format === 'xls' ? 'xls' : 'csv');

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $result['columns']);
            foreach ($result['rows'] as $row) {
                $line = [];
                foreach ($result['columns'] as $column) {
                    $line[] = is_array($row) ? ($row[$column] ?? '') : ($row->{$column} ?? '');
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function subscribe(Request $request, string $workspace, string $report): RedirectResponse
    {
        $meta = $this->reports->find($report);
        abort_unless($meta, 404);
        $this->authorize($meta['permission']);
        $data = $request->validate([
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'name' => ['nullable', 'string'],
        ]);
        ReportSubscription::query()->create([
            'user_id' => $request->user()->id,
            'report_key' => $report,
            'frequency' => $data['frequency'],
            'filters' => $this->reportFilters($request, $meta),
        ]);
        if ($request->filled('name')) {
            ReportView::query()->create([
                'user_id' => $request->user()->id,
                'report_key' => $report,
                'name' => $data['name'],
                'filters' => $this->reportFilters($request, $meta),
            ]);
        }

        return back()->with('success', 'Report subscription saved.');
    }

    private function filters(Request $request): array
    {
        return [
            'from' => $request->string('from')->toString() ?: now('Africa/Dar_es_Salaam')->toDateString(),
            'to' => $request->string('to')->toString() ?: now('Africa/Dar_es_Salaam')->toDateString(),
            'branch_id' => $request->string('branch_id')->toString() ?: $this->branchId(),
            'item_id' => $request->string('item_id')->toString() ?: null,
        ];
    }

    private function reportFilters(Request $request, array $meta): array
    {
        $filters = $this->filters($request);
        if (in_array($meta['key'], ['expenses.register', 'expenses.categories', 'profit.operating'], true)) {
            $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'branch_id' => ['nullable', 'uuid']]);
            $access = app(AccessManager::class);
            abort_unless(in_array($filters['branch_id'], $access->visibleBranchIds($request->user(), $meta['permission']), true), 403);
            if ($access->ownOnly($request->user(), $meta['permission'])) {
                $filters['created_by'] = $request->user()->id;
            }
        }

        return $filters;
    }
}
