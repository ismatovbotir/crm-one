<?php

namespace App\Actions\Leads;

use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Lead;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Spec 04 section 19 (retention): removes the personal data of lost and disqualified leads that
 * were closed before the cutoff. The lead row, company, need, source, score and status history
 * stay so reporting keeps working. Written with the query builder on purpose: the model events
 * would copy the old values into the change log. The change log is scrubbed for the same fields
 * (a deliberate, documented exception to its append-only rule) and gets one `anonymised` row.
 */
class AnonymiseClosedLeads
{
    /** Lead columns set to null. */
    public const PERSONAL_COLUMNS = [
        'contact_name', 'contact_position', 'contact_email', 'contact_phone', 'tax_id',
        'telegram_user_id', 'telegram_username', 'duplicate_override_reason', 'close_note',
    ];

    /**
     * @return int number of leads anonymised (or that would be, when $dryRun)
     */
    public function handle(CarbonInterface $cutoff, bool $dryRun = false): int
    {
        $query = Lead::query()
            ->whereIn('status', [LeadStatus::Lost->value, LeadStatus::Disqualified->value])
            ->whereNull('anonymised_at')
            ->where('closed_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;

        $query->orderBy('id')->chunkById(100, function ($leads) use (&$count): void {
            foreach ($leads as $lead) {
                $this->anonymise($lead);
                $count++;
            }
        });

        return $count;
    }

    public function anonymise(Lead $lead): void
    {
        DB::transaction(function () use ($lead): void {
            $values = array_fill_keys(self::PERSONAL_COLUMNS, null);

            // In a private Telegram chat the chat id is the person's Telegram id; group ids (negative) are kept.
            if ($lead->telegram_chat_id !== null && $lead->telegram_chat_id > 0) {
                $values['telegram_chat_id'] = null;
            }

            DB::table('leads')->where('id', $lead->id)->update($values + ['anonymised_at' => now()]);
            DB::table('lead_follow_ups')->where('lead_id', $lead->id)->update(['note' => null]);

            $this->scrubStatusReasons($lead);
            $this->scrubChangeLog($lead);

            $lead->logActivity('anonymised', ['months' => (int) config('operations.retention.closed_leads_months')]);
        });
    }

    /** Reasons carry the close note and reopen comments: keep the reason code (and the merge target), drop the free text. */
    private function scrubStatusReasons(Lead $lead): void
    {
        DB::table('status_transitions')
            ->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->whereNotNull('reason')
            ->orderBy('id')->each(function (object $row): void {
                $reason = match (true) {
                    str_starts_with($row->reason, 'close:') => trim(explode(' — ', $row->reason, 2)[0]),
                    str_starts_with($row->reason, 'merged_into:') => $row->reason,
                    default => null,
                };
                DB::table('status_transitions')->where('id', $row->id)->update(['reason' => $reason]);
            });
    }

    private function scrubChangeLog(Lead $lead): void
    {
        ActivityLog::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)
            ->orderBy('id')->each(function (ActivityLog $row): void {
                $properties = $row->properties;

                if (! is_array($properties) || $properties === []) {
                    return;
                }

                if ($row->event === 'lead_activity') {
                    $properties['text'] = '';
                } else {
                    foreach (['old', 'new'] as $side) {
                        if (isset($properties[$side]) && is_array($properties[$side])) {
                            $properties[$side] = array_diff_key($properties[$side], array_flip([...self::PERSONAL_COLUMNS, 'telegram_chat_id']));
                        }
                    }
                }

                DB::table('activity_log')->where('id', $row->id)->update(['properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            });
    }
}
