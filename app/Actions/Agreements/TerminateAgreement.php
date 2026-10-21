<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\OrderStatus;
use App\Models\Agreement;
use App\Models\Order;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\OneC\Outbox\OneCOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Terminate a signed or active agreement (Spec 06 6.5, BR-AG.9, AC-18): reason code + text, the
 * date respects the notice period unless terminated by mutual consent, and every open order under
 * it (its own order or orders linked to this framework) must be resolved first.
 */
class TerminateAgreement
{
    public const REASONS = ['customer_request', 'breach', 'mutual', 'replaced', 'other'];

    public function __construct(protected TransitionAgreement $transition) {}

    public function handle(Agreement $agreement, User $user, string $date, string $reasonCode, string $reason): Agreement
    {
        $agreement = $agreement->fresh();

        if (! in_array($agreement->status, [AgreementStatus::Signed, AgreementStatus::Active], true)) {
            throw new AgreementActionException('invalid_transition', ['from' => $agreement->status->label(), 'to' => AgreementStatus::Terminated->label()]);
        }

        if (! in_array($reasonCode, self::REASONS, true)) {
            throw new AgreementActionException('termination_reason');
        }

        $today = CarbonImmutable::now((string) config('orders.timezone'))->startOfDay();
        $terminatedOn = CarbonImmutable::parse($date, (string) config('orders.timezone'))->startOfDay();

        if ($terminatedOn->lt($today) || ($reasonCode !== 'mutual' && $terminatedOn->lt($today->addDays($agreement->notice_days)))) {
            throw new AgreementActionException('notice_period', ['days' => $agreement->notice_days]);
        }

        $open = Order::query()
            ->where(fn ($query) => $query->where('framework_agreement_id', $agreement->id)->when($agreement->order_id !== null, fn ($q) => $q->orWhere('id', $agreement->order_id)))
            ->whereNotIn('status', [OrderStatus::Closed, OrderStatus::Cancelled])
            ->pluck('number');

        if ($open->isNotEmpty()) {
            throw new AgreementActionException('open_orders', ['orders' => $open->implode(', ')]);
        }

        return DB::transaction(function () use ($agreement, $user, $reasonCode, $reason, $terminatedOn): Agreement {
            $this->transition->handle($agreement, AgreementStatus::Terminated, $user, $reasonCode.': '.$reason, [
                'terminated_on' => $terminatedOn->toDateString(),
                'termination_reason_code' => $reasonCode,
                'termination_reason' => $reason,
            ]);
            app(OneCOutbox::class)->publishAgreement('agreement.terminated', $agreement);

            return $agreement;
        });
    }
}
