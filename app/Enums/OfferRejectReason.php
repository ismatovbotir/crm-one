<?php

namespace App\Enums;

/**
 * Why a customer rejected an offer (Spec 05 7.1, analytics).
 */
enum OfferRejectReason: string
{
    case Price = 'price';
    case LeadTime = 'lead_time';
    case ChoseCompetitor = 'chose_competitor';
    case NoBudget = 'no_budget';
    case ProjectCancelled = 'project_cancelled';
    case SpecificationMismatch = 'specification_mismatch';
    case Other = 'other';

    public function label(): string
    {
        return __('offers.reject_reasons.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
