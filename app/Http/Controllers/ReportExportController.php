<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV / XLSX download of a report the user may run (roadmap step 3).
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, ReportRegistry $registry, ReportExporter $exporter): StreamedResponse
    {
        $definition = $registry->find($report);
        abort_if($definition === null || ! in_array($format, ['csv', 'xlsx'], true), 404);
        abort_unless($definition->allowed($request->user()), 403);

        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $request->user()->logActivity('report_exported', ['report' => $report, 'format' => $format, 'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null]);

        return $exporter->download($definition, $request->user(), ['from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null], $format);
    }
}
