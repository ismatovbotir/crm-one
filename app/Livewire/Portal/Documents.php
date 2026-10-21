<?php

namespace App\Livewire\Portal;

use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Agreements or invoices of the company (Spec 03 4.8: portal admin, approver, finance), each with
 * its print in the customer's language.
 */
#[Layout('layouts.portal')]
class Documents extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = 'agreements';

    public function mount(string $kind, PortalContext $context): void
    {
        abort_unless(in_array($kind, ['agreements', 'invoices'], true), 404);
        abort_unless($context->canSeeFinance(), 403);
        $this->kind = $kind;
    }

    public function render(PortalScope $scope): View
    {
        $query = $this->kind === 'agreements' ? $scope->agreements() : $scope->invoices();

        return view('livewire.portal.documents', [
            'rows' => $query->latest('id')->paginate(config('crm.per_page')),
        ])->title(__('portal.nav.'.$this->kind));
    }
}
