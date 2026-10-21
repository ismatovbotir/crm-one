<?php

namespace App\Actions\Agreements;

use App\Enums\Locale;
use App\Models\Agreement;
use App\Models\CompanySignatory;
use App\Services\Agreements\AgreementValidator;
use App\Services\Agreements\Exceptions\AgreementActionException;

/**
 * Editable parts of a draft (Spec 06 10.2 agreement editor): second language, term, renewal,
 * notice, our signatory and the template's optional clauses. Variables are re-resolved from the
 * current customer/company/order data each time ("refresh data"); sent text never changes.
 */
class UpdateAgreementDraft
{
    public function __construct(protected GenerateAgreement $generate, protected AgreementValidator $validator) {}

    /**
     * @param  array{secondary_locale?: string|null, starts_on?: string|null, ends_on?: string|null, auto_renew?: bool, notice_days?: int, our_signatory_id?: int|null, clause_options?: array<string, bool>}  $data
     */
    public function handle(Agreement $agreement, array $data): Agreement
    {
        $agreement = $agreement->fresh();

        if (! $agreement->status->isEditable()) {
            throw new AgreementActionException('not_editable');
        }

        if (array_key_exists('secondary_locale', $data)) {
            $secondary = Locale::fromAvailable($data['secondary_locale']);
            $agreement->secondary_locale = $secondary !== null && $secondary->value !== $agreement->document_locale ? $secondary->value : null;
        }

        if (! empty($data['our_signatory_id']) && ! CompanySignatory::query()->whereKey($data['our_signatory_id'])->where('is_active', true)->exists()) {
            throw new AgreementActionException('signatory_invalid');
        }

        if (array_key_exists('clause_options', $data)) {
            $declared = array_column($this->validator->templateVersion($agreement, $agreement->document_locale)?->clauses ?? [], 'code');
            $agreement->clause_options = collect($declared)->mapWithKeys(fn (string $code): array => [$code => (bool) ($data['clause_options'][$code] ?? false)])->all();
        }

        $agreement->fill(array_intersect_key($data, array_flip(['starts_on', 'ends_on', 'auto_renew', 'notice_days', 'our_signatory_id'])));
        $agreement->save();

        return $this->generate->refresh($agreement);
    }
}
