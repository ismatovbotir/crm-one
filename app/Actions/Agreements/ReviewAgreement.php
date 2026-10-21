<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use App\Services\Agreements\AgreementValidator;
use App\Services\Agreements\Exceptions\AgreementActionException;

/**
 * Review of non-standard agreements (Spec 06 6.5, AC-13): submit (validator passes), approve
 * (approver is not the preparer; sets approved_at, sending follows) or return to draft with a
 * comment.
 */
class ReviewAgreement
{
    public function __construct(protected TransitionAgreement $transition, protected AgreementValidator $validator, protected GenerateAgreement $generate) {}

    public function submit(Agreement $agreement, User $user): Agreement
    {
        $agreement = $this->generate->refresh($agreement->fresh());
        $this->assertValid($agreement);

        return $this->transition->handle($agreement, AgreementStatus::InReview, $user, null, ['approved_by' => null, 'approved_at' => null]);
    }

    public function approve(Agreement $agreement, User $user, ?string $comment = null): Agreement
    {
        $agreement = $agreement->fresh();

        if ($agreement->status !== AgreementStatus::InReview || $agreement->approved_at !== null) {
            throw new AgreementActionException('not_in_review');
        }

        if ($agreement->prepared_by === $user->id) {
            throw new AgreementActionException('self_approval');
        }

        $agreement->forceFill(['approved_by' => $user->id, 'approved_at' => now()])->save();
        $agreement->logActivity('review_approved', $comment !== null ? ['comment' => $comment] : null);

        return $agreement;
    }

    public function returnToDraft(Agreement $agreement, User $user, string $comment): Agreement
    {
        $agreement = $agreement->fresh();

        if ($agreement->status !== AgreementStatus::InReview) {
            throw new AgreementActionException('not_in_review');
        }

        return $this->transition->handle($agreement, AgreementStatus::Draft, $user, $comment, ['approved_by' => null, 'approved_at' => null]);
    }

    public function assertValid(Agreement $agreement): void
    {
        $errors = $this->validator->errors($agreement);

        if ($errors !== []) {
            throw new AgreementActionException('invalid', ['problems' => implode('; ', array_map(fn (array $error): string => __('agreements.validation.'.$error['key'], $error['params']), $errors))]);
        }
    }
}
