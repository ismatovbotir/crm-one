<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Services\Maintenance\SetupChecklist;
use App\Support\BusinessSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Initial setup wizard (roadmap step 7, admin): the go-live checklist with live status, links to
 * the screens where each step is done, and the switch for enforcing staff 2FA.
 */
#[Layout('layouts.app')]
class SetupWizard extends Component
{
    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
    }

    public function toggleTwoFactor(): void
    {
        $this->authorize('manage', Setting::class);
        $on = ! BusinessSettings::bool('security.enforce_two_factor');
        Setting::putMany(['security.enforce_two_factor' => $on ? '1' : '0'], auth()->user());
        auth()->user()->logActivity('two_factor_enforcement_changed', ['enforced' => $on]);
        session()->flash('status', $on ? __('setup.two_factor_on') : __('setup.two_factor_off'));
    }

    public function render(SetupChecklist $checklist): View
    {
        $steps = $checklist->steps();

        return view('livewire.settings.setup-wizard', [
            'steps' => $steps,
            'ready' => $checklist->ready($steps),
            'enforced' => BusinessSettings::bool('security.enforce_two_factor'),
        ])->title(__('setup.title'));
    }
}
