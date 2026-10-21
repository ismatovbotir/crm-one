<?php

namespace App\Enums;

/**
 * Why an alternative option is offered (Spec 05 7.4). Customer-visible: printed and shown in the
 * customer view in the document language (documents.offer.reasons.*).
 */
enum AlternativeReason: string
{
    case OriginalOutOfStock = 'original_out_of_stock';
    case CheaperAnalogue = 'cheaper_analogue';
    case Upgrade = 'upgrade';
    case FasterDelivery = 'faster_delivery';
    case BrandChoice = 'brand_choice';
    case CustomerRequest = 'customer_request';
    case Other = 'other';

    public function label(): string
    {
        return __('documents.offer.reasons.'.$this->value);
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
