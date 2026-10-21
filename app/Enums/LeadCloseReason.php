<?php

namespace App\Enums;

/**
 * Why a lead was disqualified or lost (Spec 04 BR-5, analytics).
 */
enum LeadCloseReason: string
{
    case NoBudget = 'no_budget';
    case NoNeed = 'no_need';
    case NotDecisionMaker = 'not_decision_maker';
    case WrongTarget = 'wrong_target';
    case Duplicate = 'duplicate';
    case Spam = 'spam';
    case ChoseCompetitor = 'chose_competitor';
    case Price = 'price';
    case Timing = 'timing';
    case NoResponse = 'no_response';
    case Other = 'other';

    public function label(): string
    {
        return __('leads.close_reasons.'.$this->value);
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
