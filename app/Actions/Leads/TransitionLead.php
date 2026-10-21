<?php

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a lead's status changes (Spec 04 section 5): checks the allowed-transition map,
 * writes the workflow timestamps and a `status_transitions` row (who/from/to/when/why).
 */
class TransitionLead
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     */
    public function handle(Lead $lead, LeadStatus $to, ?User $user, ?string $reason = null, array $attributes = []): Lead
    {
        $from = $lead->status;

        if (! $from->canTransitionTo($to)) {
            throw new LeadActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        $stamps = match ($to) {
            LeadStatus::Qualified => ['qualified_at' => now()],
            LeadStatus::Converted => ['converted_at' => now()],
            LeadStatus::Disqualified, LeadStatus::Lost => ['closed_at' => now()],
            LeadStatus::Contacted => $from->isClosed() ? ['closed_at' => null, 'close_reason' => null, 'close_note' => null] : [],
            default => [],
        };

        return DB::transaction(function () use ($lead, $from, $to, $user, $reason, $attributes, $stamps): Lead {
            $lead->forceFill([...$stamps, ...$attributes, 'status' => $to, 'last_activity_at' => now()])->save();

            StatusTransition::query()->create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'user_id' => $user?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $lead;
        });
    }

    /**
     * First history row of a new lead (no previous status).
     */
    public static function recordCreation(Lead $lead, ?User $user): void
    {
        StatusTransition::query()->create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'from_status' => null,
            'to_status' => $lead->status->value,
            'user_id' => $user?->id,
            'reason' => null,
            'created_at' => now(),
        ]);
    }
}
