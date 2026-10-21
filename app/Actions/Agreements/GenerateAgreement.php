<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\Locale;
use App\Enums\OrderStatus;
use App\Enums\TemplateStatus;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\CompanySignatory;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\Partner;
use App\Models\User;
use App\Services\Agreements\AgreementValidator;
use App\Services\Agreements\AgreementVariables;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an agreement draft (Spec 06 BR-AG.1/3): from an order (kind `order`, one non-cancelled
 * per order), for a customer (`framework`), as an addendum to a signed/active agreement, or as the
 * renewal of one. Uses the published template version of the document language, resolves the
 * whitelisted variables into the frozen snapshot and flags non-standard terms.
 * Numbers: AG-YYYY-NNNNN; addenda "<parent>/A<n>".
 */
class GenerateAgreement
{
    public function __construct(protected AgreementVariables $variables, protected AgreementValidator $validator) {}

    /**
     * @param  array{template_id?: int|null, locale?: string|null, secondary_locale?: string|null, parent?: Agreement|null, renewal_of?: Agreement|null, starts_on?: string|null, ends_on?: string|null}  $options
     */
    public function handle(AgreementKind $kind, Partner $customer, ?Order $order, User $user, array $options = []): Agreement
    {
        if ($kind === AgreementKind::Order) {
            if ($order === null || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Closed], true)) {
                throw new AgreementActionException('order_not_open');
            }

            if ($order->currentAgreement() !== null) {
                throw new AgreementActionException('already_exists');
            }
        }

        $parent = $options['parent'] ?? null;

        if ($kind === AgreementKind::Addendum && ($parent === null || ! $parent->status->isBinding())) {
            throw new AgreementActionException('parent_not_binding');
        }

        $locale = Locale::fromAvailable($options['locale'] ?? null) ?? ($order?->preferredDocumentLocale() ?? $customer->preferredDocumentLocale() ?? Locale::default());
        $secondary = Locale::fromAvailable($options['secondary_locale'] ?? null);
        $template = $this->template($kind, $options['template_id'] ?? null);
        $version = $template->publishedVersion($locale->value);

        if ($version === null) {
            throw new AgreementActionException('template_missing', ['language' => $locale->label()]);
        }

        try {
            return DB::transaction(function () use ($kind, $customer, $order, $user, $options, $parent, $locale, $secondary, $template, $version): Agreement {
                $today = now((string) config('orders.timezone'))->startOfDay();
                $startsOn = $options['starts_on'] ?? $today->toDateString();
                $endsOn = $options['ends_on'] ?? $today->copy()->addMonths((int) config('agreements.default_term_months'))->subDay()->toDateString();

                $agreement = new Agreement([
                    'number' => $this->number($kind, $parent),
                    'kind' => $kind,
                    'status' => AgreementStatus::Draft,
                    'order_id' => $order?->id,
                    'customer_id' => $customer->id,
                    'parent_agreement_id' => $parent?->id,
                    'renewal_of_id' => ($options['renewal_of'] ?? null)?->id,
                    'template_id' => $template->id,
                    'template_version' => $version->version,
                    'document_locale' => $locale->value,
                    'secondary_locale' => $secondary !== null && $secondary !== $locale ? $secondary->value : null,
                    'variables' => [],
                    'clause_options' => collect($version->clauses ?? [])->mapWithKeys(fn (array $clause): array => [$clause['code'] => (bool) ($clause['default'] ?? false)])->all(),
                    'version' => 1,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'value_minor' => $order?->gross_minor,
                    'currency' => $order?->currency ?? $customer->currency,
                    'prepared_by' => $user->id,
                    'our_signatory_id' => CompanySignatory::default()?->id,
                ]);
                $agreement->save();

                // Spec 06 section 25 (DEC-25.3): a retail order needs no agreement; once the user
                // adds one, it becomes required for that order like a B2B agreement.
                if ($kind === AgreementKind::Order && $order !== null && ! $order->agreement_required && $order->sale_type->isRetail()) {
                    $order->forceFill(['agreement_required' => true])->save();
                }

                $this->refresh($agreement);
                TransitionAgreement::record($agreement, null, AgreementStatus::Draft, $user, $order !== null ? 'from_order:'.$order->number : null);

                return $agreement;
            });
        } catch (UniqueConstraintViolationException) {
            throw new AgreementActionException('already_exists');
        }
    }

    /** Re-resolves the variables snapshot and the non-standard flags of a draft. */
    public function refresh(Agreement $agreement): Agreement
    {
        $agreement->load(['order', 'customer', 'parent']);
        $agreement->variables = $this->variables->resolve($agreement, $agreement->order, $agreement->customer);
        $reasons = $this->validator->nonStandardReasons($agreement);
        $agreement->is_non_standard = $reasons !== [];
        $agreement->non_standard_reasons = $reasons === [] ? null : $reasons;
        $agreement->save();

        return $agreement;
    }

    protected function template(AgreementKind $kind, ?int $templateId): AgreementTemplate
    {
        $query = AgreementTemplate::query()->where('kind', $kind)->where('is_active', true);
        $template = $templateId !== null
            ? $query->whereKey($templateId)->first()
            : ((clone $query)->where('code', config('agreements.default_templates.'.$kind->value))->first() ?? $query->whereHas('versions', fn ($q) => $q->where('status', TemplateStatus::Published))->orderBy('id')->first());

        if ($template === null) {
            throw new AgreementActionException('no_template', ['kind' => $kind->label()]);
        }

        return $template;
    }

    protected function number(AgreementKind $kind, ?Agreement $parent): string
    {
        if ($kind === AgreementKind::Addendum && $parent !== null) {
            $count = Agreement::query()->where('parent_agreement_id', $parent->id)->count();

            return $parent->number.'/A'.($count + 1);
        }

        $year = (int) now((string) config('orders.timezone'))->year;

        return NumberSequence::format('AG', $year, NumberSequence::next('AG', $year));
    }
}
