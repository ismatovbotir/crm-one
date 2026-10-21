<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Carbon\CarbonImmutable;

/**
 * Follow-up tasks on a lead (Spec 04 section 16): add (assignee = lead owner, reminder at the due
 * time by default) and complete. Both are logged on the lead.
 */
class ManageFollowUps
{
    public function add(Lead $lead, User $user, string $dueAt, ?string $note, bool $remind = true): LeadFollowUp
    {
        $due = CarbonImmutable::parse($dueAt);

        if ($due->isBefore(now()->subMinute())) {
            throw new LeadActionException('follow_up_past');
        }

        $followUp = $lead->followUps()->create([
            'assignee_id' => $lead->owner_id ?? $user->id,
            'created_by' => $user->id,
            'due_at' => $due,
            'remind_at' => $remind ? $due : null,
            'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
        ]);
        $lead->logActivity('follow_up_added', ['due_at' => $due->toDateTimeString(), 'note' => $followUp->note]);

        return $followUp;
    }

    public function complete(LeadFollowUp $followUp, User $user): LeadFollowUp
    {
        if ($followUp->completed_at !== null) {
            return $followUp;
        }

        $followUp->forceFill(['completed_at' => now(), 'completed_by' => $user->id])->save();
        $followUp->lead->logActivity('follow_up_done', ['note' => $followUp->note]);
        $followUp->lead->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $followUp;
    }
}
