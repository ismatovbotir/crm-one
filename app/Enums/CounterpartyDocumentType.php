<?php

namespace App\Enums;

/**
 * Qualification documents of suppliers and carriers (Spec 03 7.2, Spec 08 10.3).
 */
enum CounterpartyDocumentType: string
{
    case Registration = 'registration';
    case License = 'license';
    case TransportLicense = 'transport_license';
    case CmrInsurance = 'cmr_insurance';
    case CargoInsurance = 'cargo_insurance';
    case Adr = 'adr';
    case Tir = 'tir';
    case Iso = 'iso';
    case DealerAuthorization = 'dealer_authorization';
    case BankReference = 'bank_reference';
    case Other = 'other';

    public function label(): string
    {
        return __('counterparties.document_types.'.$this->value);
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
