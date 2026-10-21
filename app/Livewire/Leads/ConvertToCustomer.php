<?php

namespace App\Livewire\Leads;

use App\Actions\Leads\ConvertLeadToCustomer;
use App\Enums\LeadStatus;
use App\Enums\LegalType;
use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Leads\LeadConversionMatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Convert to customer" dialog on the lead page (Spec 04 section 18): shows organizations that
 * match the lead (tax ID, e-mail, phone, name), lets the manager create a new customer, link an
 * existing one or add the customer role to a supplier/carrier-only organization, choose per field
 * what to keep, and pick the follow-on options. Everything is re-checked in the action.
 */
class ConvertToCustomer extends Component
{
    #[Locked]
    public int $leadId;

    /** create | link | add_role */
    public string $mode = 'create';

    public ?int $organizationId = null;

    /** @var array<string, string> field => lead|keep */
    public array $fields = [];

    public string $saleType = 'b2b';

    public string $legalType = 'legal_entity';

    public bool $notDuplicate = false;

    public bool $createOffer = true;

    public bool $invitePortal = false;

    public bool $onecPull = false;

    public function mount(int $leadId): void
    {
        $this->leadId = $leadId;
        $this->authorize('convertToCustomer', $this->lead);

        $taxMatch = $this->matches->first(fn (array $match): bool => in_array('tax_id', $match['reasons'], true) && $match['action'] !== 'unavailable');
        $linked = $this->matches->first(fn (array $match): bool => in_array('linked', $match['reasons'], true) && $match['action'] === 'link');
        $default = $linked ?? $taxMatch;

        if ($default !== null) {
            $this->choose($default['action'], $default['organization']->id);
        }
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['items.item', 'owner'])->findOrFail($this->leadId);
    }

    /**
     * @return Collection<int, array{organization: Partner, reasons: list<string>, action: string}>
     */
    #[Computed]
    public function matches(): Collection
    {
        return app(LeadConversionMatcher::class)->matches($this->lead);
    }

    #[Computed]
    public function taxIdBlocked(): bool
    {
        return app(LeadConversionMatcher::class)->taxIdMatch($this->lead) !== null;
    }

    /**
     * @return list<array{field: string, lead: string, current: string, default: string}>
     */
    #[Computed]
    public function diff(): array
    {
        $organization = $this->target();

        return $organization === null ? [] : app(LeadConversionMatcher::class)->diff($this->lead, $organization);
    }

    /** Whether the user may change the chosen organization's data (always for a new customer role). */
    #[Computed]
    public function mayChangeFields(): bool
    {
        if ($this->mode === 'add_role') {
            return true;
        }

        $customer = $this->mode === 'link' && $this->organizationId !== null ? Customer::query()->find($this->organizationId) : null;

        return $customer !== null && auth()->user()->can('update', $customer);
    }

    public function choose(string $mode, ?int $organizationId = null): void
    {
        if (! in_array($mode, ConvertLeadToCustomer::MODES, true)) {
            return;
        }

        $match = $organizationId !== null ? $this->matches->first(fn (array $row): bool => $row['organization']->id === $organizationId) : null;

        if ($mode !== 'create' && ($match === null || $match['action'] !== $mode)) {
            return;
        }

        if ($mode === 'create' && $this->taxIdBlocked) {
            return;
        }

        $this->mode = $mode;
        $this->organizationId = $mode === 'create' ? null : $organizationId;
        unset($this->diff, $this->mayChangeFields);
        $this->fields = collect($this->diff)->mapWithKeys(fn (array $row): array => [$row['field'] => $this->mayChangeFields ? $row['default'] : 'keep'])->all();
        $this->resetErrorBag();
    }

    public function convert(ConvertLeadToCustomer $convert): void
    {
        // Someone else converted it meanwhile (two managers, two tabs): say so instead of a 403.
        if ($this->lead->status === LeadStatus::Converted && auth()->user()->can('view', $this->lead)) {
            session()->flash('error', __('leads.errors.already_converted'));
            $this->redirectRoute('leads.show', $this->lead, navigate: true);

            return;
        }

        $this->authorize('convertToCustomer', $this->lead);
        $this->validate([
            'saleType' => ['required', 'in:'.implode(',', array_column(SaleType::cases(), 'value'))],
            'legalType' => ['required', 'in:'.implode(',', array_column(LegalType::cases(), 'value'))],
            'fields.*' => ['in:lead,keep'],
        ]);

        try {
            $result = $convert->handle($this->lead, auth()->user(), [
                'mode' => $this->mode,
                'partner_id' => $this->organizationId,
                'fields' => $this->fields,
                'sale_type' => $this->saleType,
                'legal_type' => $this->legalType,
                'not_duplicate' => $this->notDuplicate,
                'create_offer' => $this->createOffer,
                'invite_portal' => $this->invitePortal,
                'onec_pull' => $this->onecPull,
            ]);
        } catch (LeadActionException $exception) {
            if ($exception->key === 'already_converted') {
                session()->flash('error', $exception->getMessage());
                $this->redirectRoute('leads.show', $this->lead, navigate: true);

                return;
            }

            $this->addError('conversion', $exception->getMessage());
            unset($this->matches, $this->diff);

            return;
        }

        session()->flash('status', __('leads.conversion.done', ['name' => $result['customer']->name]));

        if ($result['notes'] !== []) {
            session()->flash('error', implode(' ', $result['notes']));
        }

        if ($result['offer'] !== null) {
            $this->redirectRoute('offers.edit', $result['offer'], navigate: true);

            return;
        }

        $this->redirectRoute('customers.show', $result['customer'], navigate: true);
    }

    protected function target(): ?Partner
    {
        return $this->mode === 'create' || $this->organizationId === null ? null : Partner::withTrashed()->find($this->organizationId);
    }

    public function render(): View
    {
        return view('livewire.leads.convert-to-customer', [
            'saleTypes' => SaleType::options(),
            'legalTypes' => LegalType::options(),
        ]);
    }
}
