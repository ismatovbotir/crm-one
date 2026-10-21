<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\SubmitBid;
use App\Enums\BidStatus;
use App\Enums\ChargeCode;
use App\Enums\ChargeInclusion;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderInvitation;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderClock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bids tab (Spec 03 section 9, BR-T.5, BR-T.14): sealed list (before opening: counterparty,
 * version, time, channel only — content only for the employee who entered it), bid entry from an
 * off-platform quote with evidence, withdrawal before the deadline, receipts (hash).
 */
class BidBoard extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $tenderId;

    public bool $entering = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<TemporaryUploadedFile> */
    public array $evidence = [];

    public ?int $withdrawBidId = null;

    public string $withdrawReason = '';

    public function mount(int $tenderId): void
    {
        $this->tenderId = $tenderId;
        $this->authorize('view', $this->tender);
        $this->resetForm();
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->with(['lots.items', 'invitations.counterparty'])->findOrFail($this->tenderId);
    }

    /**
     * Metadata only: content relations are loaded per bid after the policy check in the view.
     *
     * @return Collection<int, TenderBid>
     */
    #[Computed]
    public function bids(): Collection
    {
        return TenderBid::query()->where('tender_id', $this->tenderId)
            ->with(['counterparty', 'enteredBy'])
            ->orderBy('counterparty_id')->orderByDesc('round_no')->orderByDesc('version')
            ->get(['id', 'tender_id', 'counterparty_id', 'invitation_id', 'round_no', 'version', 'status', 'channel', 'entered_by', 'currency', 'submitted_at', 'payload_hash', 'needs_confirmation', 'withdrawn_at', 'disqualification_reason']);
    }

    public function startEntry(?int $invitationId = null): void
    {
        $this->authorize('enterBid', $this->tender);
        $this->resetForm();
        $this->form['invitation_id'] = (string) ($invitationId ?? '');
        $this->entering = true;
    }

    public function submit(SubmitBid $submit): void
    {
        $this->authorize('enterBid', $this->tender);
        $this->validate([
            'form.invitation_id' => ['required', 'integer'],
            'form.currency' => ['required', Rule::in((array) $this->tender->accepted_currencies)],
            'form.valid_until' => ['nullable', 'date'],
            'form.payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'form.prepayment_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'form.subcontractor_name' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
            'form.exclusions' => ['nullable', 'string', 'max:5000'],
            'form.correction_reason' => ['nullable', 'string', 'max:2000'],
            'form.source.received_via' => ['required', Rule::in(config('tenders.vocab.received_via'))],
            'form.source.received_at' => ['required', 'date'],
            'form.source.sender_name' => ['required', 'string', 'max:255'],
            'form.source.sender_contact' => ['nullable', 'string', 'max:255'],
            'form.source.note' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['array', 'max:10'],
            'evidence.*' => ['file', 'max:'.(int) config('tenders.evidence_max_kb')],
        ], [], [
            'form.invitation_id' => __('tenders.bids.counterparty'),
            'form.source.received_at' => __('tenders.bids.received_at'),
            'form.source.sender_name' => __('tenders.bids.sender_name'),
        ]);

        $invitation = TenderInvitation::query()->where('tender_id', $this->tenderId)->findOrFail((int) $this->form['invitation_id']);
        $data = $this->form;
        $data['source']['received_at'] = TenderClock::parseLocal((string) $data['source']['received_at']);

        try {
            $submit->handle($invitation, $data, auth()->user(), array_values($this->evidence));
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        session()->flash('status', __('tenders.flash.bid_entered'));
        $this->entering = false;
        $this->resetForm();
        unset($this->bids);
        $this->dispatch('tender-updated');
    }

    public function withdraw(SubmitBid $submit): void
    {
        $bid = TenderBid::query()->where('tender_id', $this->tenderId)->findOrFail((int) $this->withdrawBidId);
        $this->authorize('withdraw', $bid);

        try {
            $submit->withdraw($bid, auth()->user(), $this->withdrawReason);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('tenders.flash.bid_withdrawn'));
        $this->reset('withdrawBidId', 'withdrawReason');
        unset($this->bids);
        $this->dispatch('tender-updated');
    }

    protected function resetForm(): void
    {
        $tender = $this->tender;
        $lots = [];

        foreach ($tender->lots as $lot) {
            $lines = [];

            if ($tender->type === TenderType::Transport) {
                foreach (ChargeCode::cases() as $code) {
                    $lines[$code->value] = ['inclusion' => $code === ChargeCode::Freight ? ChargeInclusion::Included->value : '', 'amount' => '', 'basis' => '', 'description' => ''];
                }
            } else {
                foreach ($lot->items as $item) {
                    $lines[$item->id] = ['unit_price' => '', 'quantity' => '', 'offered_brand' => '', 'offered_model' => '', 'compliant' => true, 'deviation' => ''];
                }
            }

            $lots[$lot->id] = ['offered' => true, 'transit_days' => '', 'lead_time_days' => '', 'route' => '', 'border_crossing' => '', 'vehicle_offered' => '', 'downtime_rate' => '', 'payment_terms_offered' => '', 'exceptions' => '', 'lines' => $lines];
        }

        $this->form = [
            'invitation_id' => '',
            'currency' => (string) (((array) $tender->accepted_currencies)[0] ?? 'UZS'),
            'valid_until' => '',
            'payment_days' => '',
            'prepayment_percent' => '',
            'subcontracting' => false,
            'subcontractor_name' => '',
            'notes' => '',
            'exclusions' => '',
            'conditions_accepted' => true,
            'correction_reason' => '',
            'lots' => $lots,
            'source' => ['received_via' => 'email', 'received_at' => TenderClock::toLocalInput(now()->toImmutable()), 'sender_name' => '', 'sender_contact' => '', 'note' => ''],
        ];
        $this->evidence = [];
    }

    public function render(): View
    {
        return view('livewire.tenders.bid-board', [
            'chargeCodes' => ChargeCode::options(),
            'inclusions' => ChargeInclusion::options(),
            'isTransport' => $this->tender->type === TenderType::Transport,
            'tz' => TenderClock::label(),
            'currentStatuses' => [BidStatus::Submitted, BidStatus::Awarded, BidStatus::NotAwarded, BidStatus::Disqualified],
        ]);
    }
}
