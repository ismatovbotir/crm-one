<?php

namespace App\Actions\Leads;

use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Illuminate\Support\Facades\DB;

/**
 * Spec 04 section 20: merges a duplicate open lead (source) into another open lead (target).
 * The target keeps its number and gains what the source has and it lacks: interested items
 * (quantities add up), follow-ups, Telegram chat and message links, linked offers, orders and
 * the source-lead link of a customer. The source is closed as lost with reason "duplicate",
 * points at the target (`merged_into_id`) and keeps its own history. Nothing is deleted.
 */
class MergeLeads
{
    /** Target columns filled from the source only when the target has no value. */
    private const FILLABLE = [
        'tax_id', 'industry', 'contact_name', 'contact_position', 'contact_email', 'contact_phone', 'expected_close_date',
        'telegram_chat_id', 'telegram_user_id', 'telegram_username', 'locale', 'sale_type',
    ];

    public function __construct(protected TransitionLead $transition, protected SaveLead $saveLead) {}

    public function handle(Lead $source, Lead $target, User $by): Lead
    {
        if ($source->is($target)) {
            throw new LeadActionException('merge_same');
        }

        return DB::transaction(function () use ($source, $target, $by): Lead {
            // Lock in id order so two merges in opposite directions cannot deadlock.
            $locked = Lead::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id];
            $target = $locked[$target->id];

            if (! $by->can('merge', $source) || ! $by->can('merge', $target)) {
                throw new LeadActionException('merge_forbidden');
            }

            if ($source->customer_id !== null && $target->customer_id !== null && $source->customer_id !== $target->customer_id) {
                throw new LeadActionException('merge_customer_conflict');
            }

            $itemCount = $this->moveItems($source, $target);

            DB::table('lead_follow_ups')->where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            DB::table('telegram_chats')->where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            DB::table('telegram_messages')->where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            DB::table('offers')->where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            DB::table('orders')->where('lead_id', $source->id)->update(['lead_id' => $target->id]);
            DB::table('partners')->where('source_lead_id', $source->id)->update(['source_lead_id' => $target->id]);
            DB::table('leads')->where('merged_into_id', $source->id)->update(['merged_into_id' => $target->id]);

            $this->fillTarget($source, $target, $itemCount);

            $this->transition->handle($source, LeadStatus::Lost, $by, 'merged_into:'.$target->number, [
                'close_reason' => LeadCloseReason::Duplicate,
                'close_note' => null,
                'merged_into_id' => $target->id,
            ]);

            $source->logActivity('merged_into', ['target' => $target->number, 'target_id' => $target->id]);
            $target->logActivity('merged_from', ['source' => $source->number, 'source_id' => $source->id]);

            return $target;
        });
    }

    /**
     * @return int number of interested items the target has afterwards
     */
    private function moveItems(Lead $source, Lead $target): int
    {
        $existing = $target->items()->get()->keyBy('item_id');

        foreach ($source->items()->get() as $line) {
            $same = $existing->get($line->item_id);

            if ($same !== null) {
                $same->update(['quantity' => bcadd((string) $same->quantity, (string) $line->quantity, 3)]);
                $line->delete();
            } else {
                $line->update(['lead_id' => $target->id]);
                $existing->put($line->item_id, $line);
            }
        }

        if ($existing->count() > (int) config('leads.max_items', 50)) {
            throw new LeadActionException('too_many_items', ['max' => (int) config('leads.max_items', 50)]);
        }

        return $existing->count();
    }

    private function fillTarget(Lead $source, Lead $target, int $itemCount): void
    {
        $fill = [];

        foreach (self::FILLABLE as $column) {
            if (blank($target->{$column}) && filled($source->{$column})) {
                $fill[$column] = $source->{$column};
            }
        }

        if ($target->customer_id === null && $source->customer_id !== null) {
            $fill += ['customer_id' => $source->customer_id, 'contact_id' => $source->contact_id];
        }

        if ($target->contact_id === null && ($fill['contact_id'] ?? null) === null && $source->contact_id !== null && ($fill['customer_id'] ?? $target->customer_id) === $source->customer_id) {
            $fill['contact_id'] = $source->contact_id;
        }

        if ($target->est_value_minor === null && $source->est_value_minor !== null) {
            $fill += ['est_value_minor' => $source->est_value_minor, 'currency' => $source->currency];
        }

        if (filled($source->need) && $source->need !== $target->need) {
            $fill['need'] = blank($target->need) ? $source->need : $target->need."\n\n".$source->number.': '.$source->need;
        }

        $target->forceFill([...$fill, 'last_activity_at' => now()])->save();
        $target->forceFill(['score' => $this->saveLead->score($target, $itemCount)])->saveQuietly();
    }
}
