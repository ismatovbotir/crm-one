<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use Carbon\CarbonImmutable;

/**
 * Daily agreement lifecycle (Spec 06 BR-AG.6/8, AC-17), idempotent:
 *  - signed agreements whose term started become active (a renewal retires its predecessor);
 *  - active agreements past `ends_on` expire, unless a signed renewal exists (then renewed);
 *  - expiry reminders at the configured stages (one per stage, recorded in `reminders_sent`) and
 *    unsigned reminders are written to the activity log and surface in the agreement queues
 *    (notification delivery joins with the notification centre, job 8).
 */
class RunAgreementLifecycle
{
    public function __construct(protected TransitionAgreement $transition, protected RecordSignature $signature) {}

    /**
     * @return array{activated: int, expired: int, renewed: int, reminders: int}
     */
    public function handle(): array
    {
        $today = CarbonImmutable::now((string) config('orders.timezone'))->startOfDay();
        $result = ['activated' => 0, 'expired' => 0, 'renewed' => 0, 'reminders' => 0];

        Agreement::query()->where('status', AgreementStatus::Signed)->whereDate('starts_on', '<=', $today->toDateString())->each(function (Agreement $agreement) use ($today, &$result): void {
            $this->signature->activateIfStarted($agreement, $today);
            $result['activated']++;
        });

        Agreement::query()->where('status', AgreementStatus::Active)->whereDate('ends_on', '<', $today->toDateString())->each(function (Agreement $agreement) use (&$result): void {
            $renewal = Agreement::query()->where('renewal_of_id', $agreement->id)->whereIn('status', [AgreementStatus::Signed, AgreementStatus::Active])->first();

            if ($renewal !== null) {
                $this->transition->handle($agreement, AgreementStatus::Renewed, null, 'renewed_by:'.$renewal->number, ['renewed_by_id' => $renewal->id]);
                $result['renewed']++;
            } else {
                $this->transition->handle($agreement, AgreementStatus::Expired, null, 'ends_on_passed');
                $result['expired']++;
            }
        });

        $stages = array_map('intval', (array) config('agreements.expiry_reminder_days'));
        rsort($stages);

        Agreement::query()->where('status', AgreementStatus::Active)->whereNotNull('ends_on')
            ->whereDate('ends_on', '<=', $today->addDays($stages[0] ?? 90)->toDateString())
            ->each(function (Agreement $agreement) use ($today, $stages, &$result): void {
                $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($agreement->ends_on->toDateString(), (string) config('orders.timezone')), false);
                $sent = (array) ($agreement->reminders_sent ?? []);
                $due = array_values(array_filter($stages, fn (int $stage): bool => $daysLeft <= $stage && ! in_array('expiry_'.$stage, $sent, true)));

                if ($due === [] || Agreement::query()->where('renewal_of_id', $agreement->id)->where('status', '!=', AgreementStatus::Cancelled)->exists()) {
                    return;
                }

                $stage = min($due);
                $agreement->forceFill(['reminders_sent' => [...$sent, ...array_map(fn (int $s): string => 'expiry_'.$s, $due)]])->save();
                $agreement->logActivity('expiry_reminder', ['days' => $daysLeft, 'stage' => $stage]);
                $result['reminders']++;
            });

        $unsignedDays = (int) config('agreements.unsigned_reminder_days');

        Agreement::query()->where('status', AgreementStatus::SentForSignature)->where('sent_at', '<=', now()->subDays($unsignedDays))->each(function (Agreement $agreement) use (&$result): void {
            $key = 'unsigned_'.$agreement->version.'_'.now((string) config('orders.timezone'))->toDateString();
            $sent = (array) ($agreement->reminders_sent ?? []);

            if (in_array($key, $sent, true) || $agreement->customer_signed_uploaded_at !== null) {
                return;
            }

            $agreement->forceFill(['reminders_sent' => [...array_filter($sent, fn (string $entry): bool => ! str_starts_with($entry, 'unsigned_')), $key]])->save();
            $agreement->logActivity('unsigned_reminder', ['days' => (int) $agreement->sent_at->diffInDays(now())]);
            $result['reminders']++;
        });

        return $result;
    }
}
