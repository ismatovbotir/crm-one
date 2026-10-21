<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\ManageTenderParticipants;
use App\Actions\Tenders\TenderWorkflow;
use App\Enums\CriterionMethod;
use App\Enums\Incoterm;
use App\Enums\TenderKind;
use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Enums\UserRole;
use App\Models\Counterparty;
use App\Models\Tender;
use App\Models\TenderCommitteeMember;
use App\Models\TenderInvitation;
use App\Models\TenderInvitationLink;
use App\Models\TenderQuestion;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\BidLinks;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Services\Tenders\TenderClock;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tender card (Spec 03 section 9): header and workflow actions, completeness panel (blockers,
 * warnings with acknowledgement comments), tabs Overview / Lots / Criteria & committee /
 * Invitees / Q&A and addenda / Bids (sealed) / Evaluation & award / History. Lots, bids and
 * evaluation are child components; every action re-authorizes server-side.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $tenderId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** @var array<string, mixed> */
    public array $header = [];

    /** @var array<string, string> issue key => comment */
    public array $acks = [];

    public string $panel = '';

    public string $comment = '';

    /** @var list<array{code: string, label: string, weight: string, method: string}> */
    public array $criteria = [];

    public string $requirementText = '';

    public bool $requirementKnockout = false;

    public string $committeeUserId = '';

    public string $inviteSearch = '';

    public string $declineInvitationId = '';

    public string $declineReason = '';

    /** @var array{counterparty_id: string, received_via: string, question: string} */
    public array $question = ['counterparty_id' => '', 'received_via' => 'email', 'question' => ''];

    /** @var array<int, string> */
    public array $answers = [];

    /** Plain bid link shown once after "Copy link" (Spec 08 8.2); never stored. */
    public string $issuedLink = '';

    public ?int $issuedForInvitation = null;

    /** @var array{summary: string, changes: string, new_bids_due_at: string} */
    public array $addendum = ['summary' => '', 'changes' => '', 'new_bids_due_at' => ''];

    public function mount(Tender $tender): void
    {
        $this->authorize('view', $tender);
        $this->tenderId = $tender->id;
        $this->fillForms();
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->with(['owner', 'requester', 'department', 'lots', 'criteria', 'requirements', 'committee.user', 'invitations.counterparty', 'invitations.links', 'questions.counterparty', 'addenda.creator', 'sources'])->findOrFail($this->tenderId);
    }

    /**
     * @return list<array{code: string, level: string, key: string, params: array<string, string|int>, lot: int|null}>
     */
    #[Computed]
    public function issues(): array
    {
        return $this->tender->status === TenderStatus::Draft ? app(CompletenessChecker::class)->check($this->tender) : (array) ($this->tender->completeness['issues'] ?? []);
    }

    /**
     * @return Collection<int, Counterparty>
     */
    #[Computed]
    public function inviteOptions(): Collection
    {
        $term = trim($this->inviteSearch);

        if ($term === '') {
            return new Collection;
        }

        return Counterparty::query()
            ->where($this->tender->type === TenderType::Transport ? 'is_carrier' : 'is_supplier', true)
            ->whereNotIn('status', ['suspended', 'blacklisted'])
            ->whereNotIn('id', $this->tender->invitations->pluck('counterparty_id'))
            ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit(10)->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function committeeCandidates(): Collection
    {
        return User::query()->where('type', 'internal')->where('is_active', true)->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', UserRole::Admin->value))
            ->whereNotIn('id', $this->tender->committee->pluck('user_id'))->orderBy('name')->get(['id', 'name']);
    }

    #[On('tender-updated')]
    public function refreshTender(): void
    {
        unset($this->tender, $this->issues);
    }

    public function saveHeader(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $currencies = config('tenders.currencies');
        $this->validate([
            'header.title' => ['required', 'string', 'max:255'],
            'header.description' => ['nullable', 'string', 'max:5000'],
            'header.method' => ['required', Rule::enum(TenderMethod::class)],
            'header.kind' => ['required', Rule::enum(TenderKind::class)],
            'header.notice_locale' => ['required', 'in:uz,ru,en'],
            'header.evaluation_currency' => ['required', Rule::in($currencies)],
            'header.accepted_currencies' => ['required', 'array', 'min:1'],
            'header.accepted_currencies.*' => [Rule::in($currencies)],
            'header.budget' => ['nullable', 'string', 'max:30'],
            'header.budget_currency' => ['nullable', Rule::in($currencies)],
            'header.incumbent_counterparty_id' => ['nullable', 'integer', 'exists:counterparties,id'],
            'header.incumbent_disclosed' => ['boolean'],
            'header.payment_terms' => ['nullable', 'string', 'max:2000'],
            'header.payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'header.prepayment_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'header.bid_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'header.confidential' => ['boolean'],
            'header.link_verification_required' => ['boolean'],
            'header.urgent' => ['boolean'],
            'header.urgent_justification' => ['nullable', 'string', 'max:2000'],
            'header.single_source_justification' => ['nullable', 'string', 'max:2000'],
            'header.incoterm' => ['nullable', Rule::enum(Incoterm::class)],
            'header.incoterm_place' => ['nullable', 'string', 'max:255'],
            'header.delivery_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'header.delivery_address' => ['nullable', 'string', 'max:1000'],
            'header.partial_deliveries_allowed' => ['nullable', 'in:0,1'],
            'header.is_import' => ['boolean'],
            'header.required_certificates' => ['nullable', 'string', 'max:2000'],
            'header.warranty_terms' => ['nullable', 'string', 'max:2000'],
            'header.expected_downtime_days' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'header.lane_period_from' => ['nullable', 'date'],
            'header.lane_period_to' => ['nullable', 'date', 'after_or_equal:header.lane_period_from'],
            'header.shipments_per_month' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'header.clarification_deadline_at' => ['nullable', 'date'],
            'header.bids_due_at' => ['nullable', 'date'],
        ], [], ['header.title' => __('tenders.fields.title'), 'header.accepted_currencies' => __('tenders.fields.accepted_currencies')]);

        $h = $this->header;
        $budgetCurrency = $h['budget_currency'] ?: $h['evaluation_currency'];

        try {
            $budget = filled($h['budget']) ? Money::fromMajor((string) $h['budget'], $budgetCurrency)->amount : null;
        } catch (InvalidArgumentException) {
            $this->addError('header.budget', __('tenders.errors.amount_invalid', ['value' => (string) $h['budget']]));

            return;
        }

        $blank = fn ($value) => is_string($value) && trim($value) === '' ? null : $value;

        try {
            $manage->updateHeader($this->tender, [
                'title' => trim((string) $h['title']),
                'description' => $blank($h['description']),
                'method' => $h['method'],
                'kind' => $h['kind'],
                'notice_locale' => $h['notice_locale'],
                'evaluation_currency' => $h['evaluation_currency'],
                'accepted_currencies' => $h['accepted_currencies'],
                'budget_minor' => $budget,
                'budget_currency' => $budget !== null ? $budgetCurrency : null,
                'incumbent_counterparty_id' => $blank($h['incumbent_counterparty_id']),
                'incumbent_disclosed' => (bool) $h['incumbent_disclosed'],
                'payment_terms' => $blank($h['payment_terms']),
                'payment_days' => $blank($h['payment_days']),
                'prepayment_bp' => filled($h['prepayment_percent']) ? (int) bcmul((string) $h['prepayment_percent'], '100', 0) : null,
                'bid_validity_days' => (int) $h['bid_validity_days'],
                'confidential' => (bool) $h['confidential'],
                'link_verification_required' => (bool) $h['link_verification_required'],
                'urgent' => (bool) $h['urgent'],
                'urgent_justification' => $blank($h['urgent_justification']),
                'single_source_justification' => $blank($h['single_source_justification']),
                'incoterm' => $blank($h['incoterm']),
                'incoterm_place' => $blank($h['incoterm_place']),
                'delivery_warehouse_id' => $blank($h['delivery_warehouse_id']),
                'delivery_address' => $blank($h['delivery_address']),
                'partial_deliveries_allowed' => $h['partial_deliveries_allowed'] === '' || $h['partial_deliveries_allowed'] === null ? null : $h['partial_deliveries_allowed'] === '1',
                'is_import' => (bool) $h['is_import'],
                'required_certificates' => $blank($h['required_certificates']),
                'warranty_terms' => $blank($h['warranty_terms']),
                'expected_downtime_days' => filled($h['expected_downtime_days']) ? (string) $h['expected_downtime_days'] : '1',
                'lane_period_from' => $blank($h['lane_period_from']),
                'lane_period_to' => $blank($h['lane_period_to']),
                'shipments_per_month' => $blank($h['shipments_per_month']),
                'clarification_deadline_at' => TenderClock::parseLocal($h['clarification_deadline_at']),
                'bids_due_at' => TenderClock::parseLocal($h['bids_due_at']),
            ]);
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('tenders.flash.saved'));
        $this->refreshTender();
    }

    public function takeOwnership(ManageTender $manage): void
    {
        $this->authorize('takeOwnership', $this->tender);
        $this->run(fn () => $manage->takeOwnership($this->tender, auth()->user()), 'tenders.flash.taken');
    }

    public function saveCriteria(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:10'],
            'criteria.*.code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/'],
            'criteria.*.label' => ['nullable', 'string', 'max:255'],
            'criteria.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'criteria.*.method' => ['required', Rule::enum(CriterionMethod::class)],
        ]);

        $this->run(fn () => $manage->setCriteria($this->tender, array_map(fn (array $c): array => [
            'code' => $c['code'],
            'label' => trim((string) $c['label']) ?: null,
            'weight_bp' => (int) bcmul((string) $c['weight'], '100', 0),
            'method' => $c['method'],
        ], $this->criteria)), 'tenders.flash.saved');
    }

    public function addCriterion(): void
    {
        $this->criteria[] = ['code' => 'criterion_'.(count($this->criteria) + 1), 'label' => '', 'weight' => '0', 'method' => 'manual'];
    }

    public function removeCriterion(int $index): void
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    public function addRequirement(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->validate(['requirementText' => ['required', 'string', 'max:2000']], [], ['requirementText' => __('tenders.requirements.text')]);
        $this->run(fn () => $manage->addRequirement($this->tender, trim($this->requirementText), $this->requirementKnockout), null);
        $this->reset('requirementText', 'requirementKnockout');
    }

    public function removeRequirement(ManageTender $manage, int $id): void
    {
        $this->authorize('update', $this->tender);
        $this->run(fn () => $manage->removeRequirement($this->tender, $id), null);
    }

    public function addCommitteeMember(ManageTenderParticipants $participants): void
    {
        $this->authorize('manageCommittee', $this->tender);
        $user = User::query()->find((int) $this->committeeUserId) ?? abort(422);
        $this->run(fn () => $participants->addCommitteeMember($this->tender, $user), 'tenders.flash.saved');
        $this->reset('committeeUserId');
    }

    public function removeCommitteeMember(ManageTenderParticipants $participants, int $id): void
    {
        $this->authorize('manageCommittee', $this->tender);
        $this->run(fn () => $participants->removeCommitteeMember(TenderCommitteeMember::query()->where('tender_id', $this->tenderId)->findOrFail($id)), null);
    }

    public function declareNoConflict(ManageTenderParticipants $participants): void
    {
        $this->authorize('declareNoConflict', $this->tender);
        $this->run(fn () => $participants->declareNoConflict($this->tender, auth()->user()), 'tenders.flash.coi_declared');
    }

    public function invite(ManageTenderParticipants $participants, int $counterpartyId): void
    {
        $this->authorize('manageInvitations', $this->tender);
        $this->run(fn () => $participants->invite($this->tender, Counterparty::query()->findOrFail($counterpartyId), auth()->user()), 'tenders.flash.invited');
        $this->inviteSearch = '';
    }

    public function removeInvitation(ManageTenderParticipants $participants, int $id): void
    {
        $this->authorize('manageInvitations', $this->tender);
        $this->run(fn () => $participants->removeInvitation(TenderInvitation::query()->where('tender_id', $this->tenderId)->findOrFail($id)), null);
    }

    /**
     * Send a unique bid link by e-mail or generate it for copying (one active link per
     * invitation; regenerating revokes the previous link).
     */
    public function sendLink(BidLinks $links, int $invitationId, string $via): void
    {
        $this->authorize('enterBid', $this->tender);
        $invitation = TenderInvitation::query()->where('tender_id', $this->tenderId)->findOrFail($invitationId);

        try {
            $issued = $links->issue($invitation, auth()->user(), $via === 'email' ? 'email' : 'copy');
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        if ($via === 'email') {
            $this->reset('issuedLink', 'issuedForInvitation');
            session()->flash('status', __('tenders.flash.link_sent'));
        } else {
            $this->issuedLink = route('bid.show', ['token' => $issued['token']]);
            $this->issuedForInvitation = $invitation->id;
        }

        $this->refreshTender();
    }

    public function revokeLink(BidLinks $links, int $linkId): void
    {
        $this->authorize('manageInvitations', $this->tender);
        $link = TenderInvitationLink::query()->whereHas('invitation', fn ($q) => $q->where('tender_id', $this->tenderId))->findOrFail($linkId);
        $links->revoke($link, auth()->user(), __('tenders.links.revoked_reason'));
        $this->reset('issuedLink', 'issuedForInvitation');
        session()->flash('status', __('tenders.flash.link_revoked'));
        $this->refreshTender();
    }

    public function recordDecline(ManageTenderParticipants $participants): void
    {
        $this->authorize('manageInvitations', $this->tender);
        $invitation = TenderInvitation::query()->where('tender_id', $this->tenderId)->findOrFail((int) $this->declineInvitationId);
        $this->run(fn () => $participants->recordDecline($invitation, $this->declineReason), 'tenders.flash.saved');
        $this->reset('declineInvitationId', 'declineReason');
    }

    public function recordQuestion(TenderWorkflow $workflow): void
    {
        $this->authorize('manageQuestions', $this->tender);
        $this->validate([
            'question.question' => ['required', 'string', 'max:5000'],
            'question.counterparty_id' => ['nullable', 'integer'],
            'question.received_via' => ['required', Rule::in(config('tenders.vocab.received_via'))],
        ], [], ['question.question' => __('tenders.qa.question')]);
        $counterparty = filled($this->question['counterparty_id']) ? $this->tender->invitations->firstWhere('counterparty_id', (int) $this->question['counterparty_id'])?->counterparty : null;
        $this->run(fn () => $workflow->recordQuestion($this->tender, $counterparty, $this->question['question'], $this->question['received_via'], auth()->user()), 'tenders.flash.saved');
        $this->reset('question');
    }

    public function answerQuestion(TenderWorkflow $workflow, int $id): void
    {
        $this->authorize('manageQuestions', $this->tender);
        $question = TenderQuestion::query()->where('tender_id', $this->tenderId)->findOrFail($id);
        $this->run(fn () => $workflow->answerQuestion($question, (string) ($this->answers[$id] ?? ''), auth()->user()), 'tenders.flash.answer_published');
        unset($this->answers[$id]);
    }

    public function addAddendum(TenderWorkflow $workflow): void
    {
        $this->authorize('addAddendum', $this->tender);
        $this->validate(['addendum.summary' => ['required', 'string', 'max:255'], 'addendum.changes' => ['nullable', 'string', 'max:5000'], 'addendum.new_bids_due_at' => ['nullable', 'date']], [], ['addendum.summary' => __('tenders.addenda.summary')]);
        $this->run(fn () => $workflow->addAddendum($this->tender, auth()->user(), $this->addendum['summary'], $this->addendum['changes'] ?: null, TenderClock::parseLocal($this->addendum['new_bids_due_at'])), 'tenders.flash.addendum_issued');
        $this->reset('addendum');
        $this->fillForms();
    }

    public function submit(TenderWorkflow $workflow): void
    {
        $this->authorize('submit', $this->tender);
        $this->run(fn () => $workflow->submitForApproval($this->tender, auth()->user(), $this->acks), 'tenders.flash.submitted');
    }

    public function openPanel(string $panel): void
    {
        $this->panel = in_array($panel, ['approve', 'reject', 'cancel'], true) ? $panel : '';
        $this->comment = '';
    }

    public function decidePublication(TenderWorkflow $workflow, bool $approve): void
    {
        $this->authorize('approvePublication', $this->tender);
        $this->run(fn () => $workflow->decidePublication($this->tender, auth()->user(), $approve, trim($this->comment) ?: null), $approve ? 'tenders.flash.approved' : 'tenders.flash.rejected');
        $this->panel = '';
    }

    public function publish(TenderWorkflow $workflow): void
    {
        $this->authorize('publish', $this->tender);
        $this->run(fn () => $workflow->publish($this->tender, auth()->user()), 'tenders.flash.published');
    }

    public function closeNow(TenderWorkflow $workflow): void
    {
        $this->authorize('close', $this->tender);
        $this->run(fn () => $workflow->close($this->tender, auth()->user()), 'tenders.flash.closed');
        $this->dispatch('tender-updated');
    }

    public function cancel(TenderWorkflow $workflow): void
    {
        $this->authorize('cancel', $this->tender);
        $this->run(fn () => $workflow->cancel($this->tender, auth()->user(), $this->comment), 'tenders.flash.cancelled');
        $this->panel = '';
    }

    public function retender(TenderWorkflow $workflow): void
    {
        $this->authorize('retender', $this->tender);

        try {
            $copy = $workflow->retender($this->tender, auth()->user());
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->redirectRoute('tenders.show', $copy, navigate: true);
    }

    public function startExecution(TenderWorkflow $workflow): void
    {
        $this->authorize('execute', $this->tender);
        $this->run(fn () => $workflow->startExecution($this->tender, auth()->user()), 'tenders.flash.saved');
    }

    public function complete(TenderWorkflow $workflow): void
    {
        $this->authorize('execute', $this->tender);
        $this->run(fn () => $workflow->complete($this->tender, auth()->user()), 'tenders.flash.completed');
    }

    protected function run(callable $action, ?string $successKey): void
    {
        try {
            $action();
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refreshTender();

            return;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        $this->refreshTender();
        $this->fillCriteria();
    }

    protected function fillForms(): void
    {
        $t = $this->tender;
        $budgetCurrency = (string) ($t->budget_currency ?? $t->evaluation_currency);
        $this->header = [
            'title' => $t->title, 'description' => (string) $t->description, 'method' => $t->method->value, 'kind' => $t->kind->value,
            'notice_locale' => $t->notice_locale, 'evaluation_currency' => $t->evaluation_currency, 'accepted_currencies' => (array) $t->accepted_currencies,
            'budget' => $t->budget_minor !== null ? Money::of($t->budget_minor, $budgetCurrency)->toMajor() : '', 'budget_currency' => $budgetCurrency,
            'incumbent_counterparty_id' => (string) ($t->incumbent_counterparty_id ?? ''), 'incumbent_disclosed' => $t->incumbent_disclosed,
            'payment_terms' => (string) $t->payment_terms, 'payment_days' => $t->payment_days, 'prepayment_percent' => $t->prepayment_bp !== null ? bcdiv((string) $t->prepayment_bp, '100', 2) : '',
            'bid_validity_days' => $t->bid_validity_days, 'confidential' => $t->confidential, 'link_verification_required' => $t->link_verification_required, 'urgent' => $t->urgent,
            'urgent_justification' => (string) $t->urgent_justification, 'single_source_justification' => (string) $t->single_source_justification,
            'incoterm' => (string) $t->incoterm, 'incoterm_place' => (string) $t->incoterm_place, 'delivery_warehouse_id' => (string) ($t->delivery_warehouse_id ?? ''),
            'delivery_address' => (string) $t->delivery_address, 'partial_deliveries_allowed' => $t->partial_deliveries_allowed === null ? '' : ($t->partial_deliveries_allowed ? '1' : '0'),
            'is_import' => $t->is_import, 'required_certificates' => (string) $t->required_certificates, 'warranty_terms' => (string) $t->warranty_terms,
            'expected_downtime_days' => (string) $t->expected_downtime_days, 'lane_period_from' => (string) $t->lane_period_from?->toDateString(),
            'lane_period_to' => (string) $t->lane_period_to?->toDateString(), 'shipments_per_month' => $t->shipments_per_month,
            'clarification_deadline_at' => TenderClock::toLocalInput($t->clarification_deadline_at), 'bids_due_at' => TenderClock::toLocalInput($t->bids_due_at),
        ];
        $this->acks = (array) $t->acknowledged_warnings;
        $this->fillCriteria();
    }

    protected function fillCriteria(): void
    {
        $this->criteria = $this->tender->criteria->map(fn ($c): array => ['code' => $c->code, 'label' => (string) $c->label, 'weight' => bcdiv((string) $c->weight_bp, '100', 2), 'method' => $c->method->value])->values()->all();
    }

    public function render(): View
    {
        $tender = $this->tender;
        $router = app(ApprovalRouter::class);

        return view('livewire.tenders.show', [
            'methods' => TenderMethod::options(),
            'kinds' => TenderKind::options(),
            'criterionMethods' => CriterionMethod::options(),
            'incoterms' => Incoterm::options(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'publishRouting' => $router->publishLevels($tender),
            'blockers' => CompletenessChecker::blockers($this->issues),
            'tz' => TenderClock::label(),
        ])->title($tender->number);
    }
}
