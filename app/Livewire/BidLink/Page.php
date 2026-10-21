<?php

namespace App\Livewire\BidLink;

use App\Actions\Tenders\ManageTenderParticipants;
use App\Actions\Tenders\SubmitBid;
use App\Actions\Tenders\TenderWorkflow;
use App\Documents\DocumentRenderer;
use App\Enums\BidStatus;
use App\Enums\ChargeCode;
use App\Enums\ChargeInclusion;
use App\Enums\Locale;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderInvitationLink;
use App\Services\Auth\Exceptions\OneTimeCodeException;
use App\Services\Tenders\BidLinks;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderClock;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Public page of a unique bid link (Spec 08 8.2, channel B). The link resolves exactly one
 * invitation; nothing else is ever loaded from client input (AC-13). Shows the notice
 * (whitelisted fields), published answers, the carrier's own bids and receipts; lets the carrier
 * ask a question, verify with a code sent to the invited contact, submit/revise/withdraw a bid
 * before the deadline, or decline. Other carriers, their count and internal data are never shown.
 */
#[Layout('layouts.bid')]
class Page extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $linkId;

    #[Locked]
    public string $pageUrl = '';

    #[Locked]
    public string $submissionUuid = '';

    public string $code = '';

    public string $question = '';

    public string $declineReason = '';

    public string $withdrawReason = '';

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<TemporaryUploadedFile> */
    public array $files = [];

    public function mount(string $token, BidLinks $links): void
    {
        $limiterKey = 'bid-link-invalid|'.request()->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            abort(429);
        }

        $link = $links->resolve($token);

        if ($link === null) {
            RateLimiter::hit($limiterKey, 60);
            abort(404);
        }

        $this->linkId = $link->id;
        $this->pageUrl = request()->url();
        session()->put('bid_links.'.$link->id, true);

        if (Locale::fromAvailable(request()->query('lang')) !== null) {
            session()->put('bid_link_locale.'.$link->id, request()->query('lang'));
        }

        $this->applyLocale();
        $links->recordOpen($link, request());
        $this->resetForm();
    }

    public function boot(): void
    {
        if (isset($this->linkId)) {
            $this->applyLocale();
        }
    }

    /** The link, re-validated on every request (revoked or expired → 404). */
    #[Computed]
    public function link(): TenderInvitationLink
    {
        $link = TenderInvitationLink::query()->with(['invitation.tender', 'invitation.counterparty'])->find($this->linkId);

        abort_if($link === null || ! $link->isActive() || ! session()->has('bid_links.'.$this->linkId), 404);

        return $link;
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->with(['lots.items', 'lots.transportSpec', 'criteria', 'requirements', 'addenda', 'questions' => fn ($q) => $q->whereNotNull('published_at')])->findOrFail($this->link->invitation->tender_id);
    }

    /**
     * Own bid versions only.
     *
     * @return Collection<int, TenderBid>
     */
    #[Computed]
    public function myBids(): Collection
    {
        return TenderBid::query()->where('invitation_id', $this->link->tender_invitation_id)->with(['lots.lines.tenderItem', 'lots.lot'])->orderByDesc('round_no')->orderByDesc('version')->get();
    }

    protected function verified(): bool
    {
        $at = session('bid_link_verified.'.$this->linkId);

        return ! $this->tender->link_verification_required || ($at !== null && now()->subHours(12)->lessThan($at));
    }

    public function sendCode(BidLinks $links): void
    {
        try {
            $links->sendCode($this->link, Locale::current());
        } catch (TenderActionException|OneTimeCodeException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('bid_link.code_sent'));
    }

    public function verify(BidLinks $links): void
    {
        $this->validate(['code' => ['required', 'digits:6']], [], ['code' => __('bid_link.code')]);

        if (! $links->verifyCode($this->link, $this->code)) {
            $this->addError('code', __('bid_link.code_invalid'));

            return;
        }

        session()->put('bid_link_verified.'.$this->linkId, now());
        $this->code = '';
        session()->flash('status', __('bid_link.verified'));
    }

    public function ask(TenderWorkflow $workflow): void
    {
        $this->validate(['question' => ['required', 'string', 'max:3000']], [], ['question' => __('bid_link.question')]);

        try {
            $workflow->recordQuestion($this->tender, $this->link->invitation->counterparty, $this->question, 'link', null);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        app(BidLinks::class)->event($this->link, 'question');
        $this->question = '';
        session()->flash('status', __('bid_link.question_sent'));
    }

    public function submit(SubmitBid $submit, BidLinks $links): void
    {
        abort_unless($links->canSubmit($this->link), 403);

        if (! $this->verified()) {
            session()->flash('error', __('bid_link.verify_first'));

            return;
        }

        $this->validate([
            'form.currency' => ['required', Rule::in((array) $this->tender->accepted_currencies)],
            'form.valid_until' => ['required', 'date'],
            'form.payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'form.prepayment_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'form.subcontractor_name' => ['nullable', 'string', 'max:255'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
            'form.exclusions' => ['nullable', 'string', 'max:5000'],
            'form.conditions_accepted' => ['accepted'],
            'files' => ['array', 'max:10'],
            'files.*' => ['file', 'max:'.(int) config('tenders.evidence_max_kb')],
        ], [], ['form.valid_until' => __('bid_link.valid_until'), 'form.conditions_accepted' => __('bid_link.conditions')]);

        try {
            $submit->fromLink($this->link, $this->form, $this->submissionUuid, array_values($this->files));
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->submissionUuid = (string) Str::uuid();
        $this->files = [];
        unset($this->myBids);
        session()->flash('status', __('bid_link.submitted'));
    }

    public function withdraw(SubmitBid $submit, BidLinks $links): void
    {
        abort_unless($links->canSubmit($this->link), 403);
        $bid = $this->myBids->firstWhere('status', BidStatus::Submitted) ?? abort(404);

        try {
            $submit->withdraw($bid, null, $this->withdrawReason);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $links->event($this->link, 'withdrawn');
        $this->withdrawReason = '';
        unset($this->myBids);
        session()->flash('status', __('bid_link.withdrawn'));
    }

    public function decline(ManageTenderParticipants $participants, BidLinks $links): void
    {
        abort_unless($links->canSubmit($this->link), 403);

        try {
            $participants->recordDecline($this->link->invitation, $this->declineReason);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $links->event($this->link, 'declined');
        unset($this->link);
        session()->flash('status', __('bid_link.declined'));
    }

    protected function applyLocale(): void
    {
        $chosen = session('bid_link_locale.'.$this->linkId);
        $invitationLocale = TenderInvitationLink::query()->whereKey($this->linkId)->with('invitation')->first()?->invitation?->notice_locale;
        App::setLocale((Locale::fromAvailable($chosen) ?? Locale::fromAvailable($invitationLocale) ?? Locale::default())->value);
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
                    $lines[$item->id] = ['unit_price' => '', 'quantity' => '', 'offered_brand' => '', 'compliant' => true, 'deviation' => ''];
                }
            }

            $lots[$lot->id] = ['offered' => true, 'transit_days' => '', 'lead_time_days' => '', 'route' => '', 'border_crossing' => '', 'vehicle_offered' => '', 'downtime_rate' => '', 'exceptions' => '', 'lines' => $lines];
        }

        $this->form = [
            'currency' => (string) (((array) $tender->accepted_currencies)[0] ?? 'UZS'),
            'valid_until' => $tender->bids_due_at?->addDays($tender->bid_validity_days)->setTimezone(TenderClock::timezone())->toDateString() ?? '',
            'payment_days' => '',
            'prepayment_percent' => '',
            'subcontracting' => false,
            'subcontractor_name' => '',
            'notes' => '',
            'exclusions' => '',
            'conditions_accepted' => false,
            'lots' => $lots,
        ];
        $this->submissionUuid = (string) Str::uuid();
    }

    public function render(DocumentRenderer $renderer, BidLinks $links): View
    {
        $tender = $this->tender;
        $language = Locale::current();

        return view('livewire.bid-link.page', [
            'notice' => $renderer->render('documents.tender_notice', [
                'tender' => $tender,
                'invitee' => $this->link->invitation->counterparty?->name,
                'seller' => CompanyProfile::seller($language),
                'timezone' => TenderClock::timezone(),
                'timezoneLabel' => TenderClock::label(),
            ], $language),
            'company' => CompanyProfile::seller($language)['name'],
            'canSubmit' => $links->canSubmit($this->link),
            'isTransport' => $tender->type === TenderType::Transport,
            'chargeCodes' => ChargeCode::options(),
            'inclusions' => ChargeInclusion::options(),
            'tz' => TenderClock::label(),
            'deadline' => $tender->localTime($tender->bids_due_at),
            'verified' => $this->verified(),
        ])->title($tender->number);
    }
}
