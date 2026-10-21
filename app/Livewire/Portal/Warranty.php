<?php

namespace App\Livewire\Portal;

use App\Services\Stock\SerialNormalizer;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Warranty lookup (Spec 06 21 deferred item): a serial sold to this company shows the item,
 * sale date and warranty end; serials of other companies are "not found" (no disclosure).
 */
#[Layout('layouts.portal')]
class Warranty extends Component
{
    public string $serial = '';

    #[Locked]
    public ?int $resultId = null;

    public bool $searched = false;

    public function lookup(PortalScope $scope, SerialNormalizer $normalizer): void
    {
        $this->validate(['serial' => ['required', 'string', 'max:100']], [], ['serial' => __('portal.warranty.serial')]);
        $normalized = $normalizer->normalize($this->serial)['value'] ?? null;
        $this->resultId = $normalized === null ? null : $scope->soldSerials()->where('serial_normalized', $normalized)->value('id');
        $this->searched = true;
    }

    public function render(PortalScope $scope): View
    {
        return view('livewire.portal.warranty', [
            'result' => $this->resultId !== null ? $scope->soldSerials()->with('item')->find($this->resultId) : null,
        ])->title(__('portal.warranty.title'));
    }
}
