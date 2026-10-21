<?php

namespace App\Livewire\Reports;

use App\Services\Reports\ReportRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reports overview (roadmap step 3): the reports the user may run.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(ReportRegistry $registry): void
    {
        abort_if($registry->allowedFor(auth()->user()) === [], 403);
    }

    public function render(ReportRegistry $registry): View
    {
        return view('livewire.reports.index', ['reports' => $registry->allowedFor(auth()->user())])->title(__('reports.title'));
    }
}
