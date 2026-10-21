<?php

namespace App\Services\Agreements;

use App\Enums\AgreementKind;
use App\Enums\Locale;
use App\Enums\TemplateStatus;
use App\Models\Agreement;
use App\Models\AgreementTemplateVersion;
use App\Models\CompanySignatory;
use App\Support\BusinessSettings;

/**
 * Agreement checks (Spec 06 BR-AG.2) and the non-standard detection that drives review
 * (6.5 draft → in_review guard).
 */
class AgreementValidator
{
    public function __construct(protected TemplateRenderer $renderer) {}

    /**
     * Blocking problems; empty = may be submitted or sent.
     *
     * @return list<array{key: string, params: array<string, string>}>
     */
    public function errors(Agreement $agreement): array
    {
        $errors = [];
        $missing = AgreementVariables::missing($agreement->variables ?? []);

        if ($missing !== []) {
            $errors[] = ['key' => 'missing_variables', 'params' => ['keys' => implode(', ', array_map(fn (string $key): string => __('agreements.variables.'.str_replace('.', '_', $key)), $missing))]];
        }

        foreach ($agreement->printLocales() as $locale) {
            $version = $this->templateVersion($agreement, $locale);

            if ($version === null) {
                $errors[] = ['key' => 'template_missing', 'params' => ['language' => Locale::from($locale)->label()]];

                continue;
            }

            foreach ($this->renderer->lint($version->body, array_column($version->clauses ?? [], 'code')) as $lint) {
                $errors[] = ['key' => 'template_'.$lint['key'], 'params' => $lint['params']];
            }
        }

        if ($agreement->starts_on !== null && $agreement->ends_on !== null && $agreement->starts_on->gt($agreement->ends_on)) {
            $errors[] = ['key' => 'dates_order', 'params' => []];
        }

        $signatory = $agreement->our_signatory_id !== null ? CompanySignatory::query()->find($agreement->our_signatory_id) : CompanySignatory::default();

        if ($signatory === null || ! $signatory->isValidOn($agreement->starts_on ?? now())) {
            $errors[] = ['key' => 'signatory_invalid', 'params' => []];
        }

        if ($agreement->kind === AgreementKind::Order && $agreement->order !== null
            && ($agreement->value_minor !== $agreement->order->gross_minor || $agreement->currency !== $agreement->order->currency)) {
            $errors[] = ['key' => 'value_mismatch', 'params' => []];
        }

        return $errors;
    }

    /**
     * Why the agreement needs review by an approver (not the preparer).
     *
     * @return list<string> codes: clauses_changed, payment_terms, high_value
     */
    public function nonStandardReasons(Agreement $agreement): array
    {
        $reasons = [];
        $version = $this->templateVersion($agreement, $agreement->document_locale);
        $defaults = collect($version?->clauses ?? [])->mapWithKeys(fn (array $clause): array => [$clause['code'] => (bool) ($clause['default'] ?? false)])->all();

        foreach ($agreement->clause_options ?? [] as $code => $enabled) {
            if (array_key_exists($code, $defaults) && $defaults[$code] !== (bool) $enabled) {
                $reasons[] = 'clauses_changed';

                break;
            }
        }

        $order = $agreement->order;
        $customer = $agreement->customer;

        if ($order !== null && $customer !== null && $order->payment_terms_days !== null && $order->payment_terms_days !== $customer->payment_terms_days) {
            $reasons[] = 'payment_terms';
        }

        $threshold = BusinessSettings::array('agreements.review_above_minor')[$agreement->currency ?? ''] ?? null;

        if ($threshold !== null && $agreement->value_minor !== null && $agreement->value_minor >= (int) $threshold) {
            $reasons[] = 'high_value';
        }

        return $reasons;
    }

    public function templateVersion(Agreement $agreement, string $locale): ?AgreementTemplateVersion
    {
        return AgreementTemplateVersion::query()
            ->where('template_id', $agreement->template_id)
            ->where('version', $agreement->template_version)
            ->where('locale', $locale)
            ->where('status', '!=', TemplateStatus::Draft)
            ->first()
            ?? AgreementTemplateVersion::query()
                ->where('template_id', $agreement->template_id)
                ->where('locale', $locale)
                ->where('status', TemplateStatus::Published)
                ->orderByDesc('version')
                ->first();
    }
}
