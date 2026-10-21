<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementDispatch;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;

/**
 * Cancel before signature (Spec 06 6.5, DEC-9): draft, in review or sent for signature, with a
 * reason (system code "order_cancelled" when the order is cancelled). Open share links are revoked.
 */
class CancelAgreement
{
    public function __construct(protected TransitionAgreement $transition) {}

    public function handle(Agreement $agreement, ?User $user, string $reason): Agreement
    {
        $agreement = $agreement->fresh();

        if (! $agreement->status->canTransitionTo(AgreementStatus::Cancelled)) {
            throw new AgreementActionException('invalid_transition', ['from' => $agreement->status->label(), 'to' => AgreementStatus::Cancelled->label()]);
        }

        AgreementDispatch::query()->where('agreement_id', $agreement->id)->whereNull('revoked_at')->whereNotNull('share_token_hash')->update(['revoked_at' => now()]);

        return $this->transition->handle($agreement, AgreementStatus::Cancelled, $user, $reason, ['cancelled_at' => now(), 'cancel_reason' => $reason]);
    }
}
