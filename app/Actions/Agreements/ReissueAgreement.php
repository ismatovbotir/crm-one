<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\AgreementDispatch;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;

/**
 * Re-issue a sent agreement whose content must change (Spec 06 6.5, AC-16): back to draft as
 * version + 1 with a reason. The sent version's stored text, fingerprint and dispatches stay and
 * remain printable (?version=); share links of the old version are revoked; review starts over.
 */
class ReissueAgreement
{
    public function __construct(protected TransitionAgreement $transition, protected GenerateAgreement $generate) {}

    public function handle(Agreement $agreement, User $user, string $reason): Agreement
    {
        $agreement = $agreement->fresh();

        if ($agreement->status !== AgreementStatus::SentForSignature) {
            throw new AgreementActionException('not_sent');
        }

        AgreementDispatch::query()->where('agreement_id', $agreement->id)->whereNull('revoked_at')->whereNotNull('share_token_hash')->update(['revoked_at' => now()]);

        $this->transition->handle($agreement, AgreementStatus::Draft, $user, 'reissue:'.$reason, [
            'version' => $agreement->version + 1,
            'approved_by' => null,
            'approved_at' => null,
            'sent_at' => null,
            'sent_by' => null,
            'customer_signed_uploaded_at' => null,
            'signed_attachment_id' => null,
        ]);

        return $this->generate->refresh($agreement->fresh());
    }
}
