<?php

namespace App\Livewire\Leads;

use App\Actions\Leads\LeadWorkflow;
use App\Actions\Leads\ManageFollowUps;
use App\Actions\Leads\MergeLeads;
use App\Enums\LeadActivityType;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\TelegramChat;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Leads\LeadDuplicateFinder;
use App\Services\Leads\LeadOwners;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lead page (Spec 04 section 8): data, qualification checklist with the gaps, interests, linked
 * offers, activity log and timeline; actions mark contacted, qualify, create offer, close with a
 * reason, reopen with a comment, reassign. Every action is authorized and runs a lead action.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $leadId;

    /** Open panel: close | reopen | assign | convert. */
    public string $panel = '';

    public string $closeStatus = 'lost';

    public string $closeReason = '';

    public string $closeNote = '';

    public string $reopenComment = '';

    /** Number of the lead this one is merged into (merge panel). */
    public string $mergeNumber = '';

    public ?int $newOwnerId = null;

    public string $activityType = 'call';

    public string $activityText = '';

    public string $followUpDue = '';

    public string $followUpNote = '';

    public function mount(Lead $lead): void
    {
        $this->authorize('view', $lead);
        $this->leadId = $lead->id;

        // "Save and convert to customer" from the lead form opens the dialog directly.
        if (request()->query('panel') === 'convert' && auth()->user()->can('convertToCustomer', $lead)) {
            $this->panel = 'convert';
        }
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with(['customer', 'contact', 'owner', 'creator', 'convertedBy', 'items.item.unit', 'offers', 'followUps.assignee'])->findOrFail($this->leadId);
    }

    /**
     * Status changes and logged activities, newest first.
     *
     * @return Collection<int, array{kind: string, at: mixed, model: mixed}>
     */
    #[Computed]
    public function timeline(): Collection
    {
        $lead = $this->lead;
        $transitions = $lead->transitions()->with('user:id,name')->get()->map(fn ($row): array => ['kind' => 'transition', 'at' => $row->created_at, 'model' => $row]);
        $activities = $lead->activities()->with('causer:id,name')->limit(200)->get()->map(fn ($row): array => ['kind' => $row->event === 'lead_activity' ? 'activity' : 'change', 'at' => $row->created_at, 'model' => $row]);

        return $transitions->concat($activities)->sortByDesc(fn (array $row) => [$row['at']?->getTimestamp(), $row['model']->id])->values();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignableOwners(): Collection
    {
        return app(LeadOwners::class)->assignableBy(auth()->user());
    }

    public function openPanel(string $panel): void
    {
        $this->panel = in_array($panel, ['close', 'reopen', 'assign', 'convert', 'merge'], true) ? $panel : '';
        $this->resetErrorBag();
        $this->newOwnerId = $this->lead->owner_id;
    }

    public function markContacted(LeadWorkflow $workflow): void
    {
        $this->authorize('markContacted', $this->lead);
        $this->run(fn () => $workflow->markContacted($this->lead, auth()->user()), __('leads.flash.contacted'));
    }

    public function qualify(LeadWorkflow $workflow): void
    {
        $this->authorize('qualify', $this->lead);
        $this->run(fn () => $workflow->qualify($this->lead, auth()->user()), __('leads.flash.qualified'));
    }

    public function close(LeadWorkflow $workflow): void
    {
        $this->authorize('close', $this->lead);
        $this->validate([
            'closeStatus' => ['required', 'in:disqualified,lost'],
            'closeReason' => ['required', 'string'],
            'closeNote' => ['nullable', 'string', 'max:2000'],
        ], [], ['closeReason' => __('leads.fields.close_reason'), 'closeNote' => __('leads.fields.close_note')]);

        $this->run(fn () => $workflow->close($this->lead, auth()->user(), LeadStatus::from($this->closeStatus), LeadCloseReason::tryFrom($this->closeReason), $this->closeNote), __('leads.flash.closed'), 'closeNote');
    }

    public function reopen(LeadWorkflow $workflow): void
    {
        $this->authorize('reopen', $this->lead);
        $this->validate(['reopenComment' => ['required', 'string', 'max:2000']], [], ['reopenComment' => __('leads.fields.reopen_comment')]);
        $this->run(fn () => $workflow->reopen($this->lead, auth()->user(), $this->reopenComment), __('leads.flash.reopened'), 'reopenComment');
    }

    /**
     * Open leads that look like this one (same tax ID, e-mail, phone, company or customer) and that
     * the user may merge into.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function mergeCandidates(): Collection
    {
        $lead = $this->lead;

        return app(LeadDuplicateFinder::class)
            ->openLeads($lead->tax_id, $lead->company_name, $lead->contact_email, $lead->contact_phone, $lead->customer_id, $lead->id)
            ->filter(fn (Lead $candidate): bool => auth()->user()->can('merge', $candidate))
            ->values();
    }

    public function merge(MergeLeads $merge): void
    {
        $this->authorize('merge', $this->lead);
        $this->validate(['mergeNumber' => ['required', 'string', 'max:30']], [], ['mergeNumber' => __('leads.fields.merge_target')]);

        $target = Lead::query()->visibleTo(auth()->user())->where('number', strtoupper(trim($this->mergeNumber)))->whereIn('status', LeadStatus::openValues())->first();

        if ($target === null) {
            $this->addError('mergeNumber', __('leads.errors.merge_target_unknown'));

            return;
        }

        $this->run(function () use ($merge, $target): void {
            $merge->handle($this->lead, $target, auth()->user());
            $this->redirectRoute('leads.show', ['lead' => $target], navigate: true);
        }, __('leads.flash.merged', ['number' => $target->number]), 'mergeNumber');
    }

    public function assign(LeadWorkflow $workflow): void
    {
        $this->authorize('assign', $this->lead);
        $owner = $this->assignableOwners->firstWhere('id', $this->newOwnerId);

        if ($owner === null) {
            $this->addError('newOwnerId', __('leads.errors.owner_invalid'));

            return;
        }

        $this->run(fn () => $workflow->assign($this->lead, auth()->user(), $owner), __('leads.flash.assigned', ['name' => $owner->name]), 'newOwnerId');
    }

    public function logActivity(LeadWorkflow $workflow): void
    {
        $this->authorize('logActivity', $this->lead);
        $this->validate([
            'activityType' => ['required', 'in:'.implode(',', array_column(LeadActivityType::cases(), 'value'))],
            'activityText' => ['required', 'string', 'max:'.(int) config('leads.activity_max_length', 2000)],
        ], [], ['activityText' => __('leads.fields.activity_text')]);

        $workflow->logActivity($this->lead, auth()->user(), LeadActivityType::from($this->activityType), $this->activityText);
        $this->reset('activityText');
        unset($this->lead, $this->timeline);
        session()->flash('status', __('leads.flash.activity_logged'));
    }

    public function addFollowUp(ManageFollowUps $followUps): void
    {
        $this->authorize('logActivity', $this->lead);
        $this->validate(['followUpDue' => ['required', 'date', 'after:now'], 'followUpNote' => ['nullable', 'string', 'max:500']], [], ['followUpDue' => __('leads.fields.follow_up_due')]);
        $this->run(fn () => $followUps->add($this->lead, auth()->user(), $this->followUpDue, $this->followUpNote), __('leads.flash.follow_up_added'), 'followUpDue');
        $this->reset('followUpDue', 'followUpNote');
    }

    public function completeFollowUp(ManageFollowUps $followUps, int $followUpId): void
    {
        $this->authorize('logActivity', $this->lead);
        $followUp = LeadFollowUp::query()->where('lead_id', $this->leadId)->findOrFail($followUpId);
        $this->run(fn () => $followUps->complete($followUp, auth()->user()), __('leads.flash.follow_up_done'));
    }

    /**
     * @param  callable(): mixed  $action
     */
    protected function run(callable $action, string $success, ?string $errorField = null): void
    {
        try {
            $action();
        } catch (LeadActionException $exception) {
            if ($errorField !== null) {
                $this->addError($errorField, $exception->getMessage());
            } else {
                session()->flash('error', $exception->getMessage());
            }

            unset($this->lead, $this->timeline);

            return;
        }

        $this->panel = '';
        $this->reset('closeReason', 'closeNote', 'reopenComment');
        unset($this->lead, $this->timeline);
        session()->flash('status', $success);
    }

    public function render(): View
    {
        $lead = $this->lead;

        return view('livewire.leads.show', [
            'closeReasons' => LeadCloseReason::options(),
            'activityTypes' => LeadActivityType::options(),
            'gaps' => $lead->status->isOpen() ? $lead->qualificationGaps() : [],
            // Spec 04 section 17: a group lead shows its Telegram group (badge, members, manager actions).
            'telegramChat' => $lead->telegram_chat_id !== null && $lead->telegram_chat_id < 0 ? TelegramChat::query()->where('chat_id', $lead->telegram_chat_id)->first() : null,
        ])->title($lead->number);
    }
}
