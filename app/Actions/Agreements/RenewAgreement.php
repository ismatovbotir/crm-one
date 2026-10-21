<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;

/**
 * Prepare the renewal of a signed or active framework agreement (Spec 06 6.5, AC-17): a new
 * framework draft linked by `renewal_of_id`, starting the day after the current term ends. When it
 * is signed and its term starts, the old agreement becomes `renewed`.
 */
class RenewAgreement
{
    public function __construct(protected GenerateAgreement $generate) {}

    public function handle(Agreement $agreement, User $user): Agreement
    {
        $agreement = $agreement->fresh(['customer']);

        if ($agreement->kind !== AgreementKind::Framework || ! $agreement->status->isBinding()) {
            throw new AgreementActionException('not_renewable');
        }

        if (Agreement::query()->where('renewal_of_id', $agreement->id)->where('status', '!=', AgreementStatus::Cancelled)->exists()) {
            throw new AgreementActionException('renewal_exists');
        }

        $startsOn = ($agreement->ends_on ?? now())->addDay();

        return $this->generate->handle(AgreementKind::Framework, $agreement->customer, null, $user, [
            'template_id' => $agreement->template_id,
            'locale' => $agreement->document_locale,
            'secondary_locale' => $agreement->secondary_locale,
            'renewal_of' => $agreement,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $startsOn->addMonths((int) config('agreements.default_term_months'))->subDay()->toDateString(),
        ]);
    }
}
