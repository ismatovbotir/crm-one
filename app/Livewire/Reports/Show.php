<?php

namespace App\Livewire\Reports;

use App\Services\Reports\Report;
use App\Services\Reports\ReportRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One report (roadmap step 3): date range (when it applies), preview of the first rows and CSV /
 * XLSX export of all rows (up to ReportExporter::MAX_ROWS).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public const PREVIEW_ROWS = 200;

    #[Locked]
    public string $reportKey;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(string $report, ReportRegistry $registry): void
    {
        $definition = $registry->find($report);
        abort_if($definition === null || ! $definition->allowed(auth()->user()), $definition === null ? 404 : 403);
        $this->reportKey = $report;

        if ($definition->usesDateRange() && $this->from === '' && $this->to === '') {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->toDateString();
        }
    }

    protected function report(): Report
    {
        $report = app(ReportRegistry::class)->find($this->reportKey);
        abort_if($report === null || ! $report->allowed(auth()->user()), 403);

        return $report;
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    public function filters(): array
    {
        $this->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return ['from' => $this->from !== '' ? $this->from : null, 'to' => $this->to !== '' ? $this->to : null];
    }

    public function render(): View
    {
        $report = $this->report();
        $rows = [];
        $more = false;

        foreach ($report->rows(auth()->user(), $this->filters()) as $row) {
            if (count($rows) >= self::PREVIEW_ROWS) {
                $more = true;
                break;
            }

            $rows[] = $row;
        }

        return view('livewire.reports.show', [
            'report' => $report,
            'columns' => $report->columns(),
            'rows' => $rows,
            'more' => $more,
            'exportQuery' => array_filter(['from' => $this->from, 'to' => $this->to]),
        ])->title(__('reports.list.'.$report->key().'.title'));
    }
}
