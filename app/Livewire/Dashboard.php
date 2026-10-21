<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\IntegrationFeed;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /**
     * 1C feed freshness for admin, manager and finance (Spec 02 BR-I.21, section 12 dashboard alerts).
     *
     * @return array<string, array<string, mixed>>
     */
    public function integrationStatuses(): array
    {
        $user = auth()->user();

        if (! in_array($user?->role, [UserRole::Admin, UserRole::Manager, UserRole::Finance, UserRole::Accountant], true)) {
            return [];
        }

        return IntegrationFeed::statuses();
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'sections' => app(DashboardMetrics::class)->sections(auth()->user()),
            'integrationStatuses' => $this->integrationStatuses(),
        ])->title(__('dashboard.title'));
    }
}
