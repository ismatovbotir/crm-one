<?php

namespace App\Enums;

/**
 * Legal type of a partner (Spec 02 section 10.3, Spec 03 section 24.7): legal entity, sole
 * proprietor (YaTT), individual (private person), government/budget organization. The company
 * form (MChJ, AJ, ...) stays in the legal name.
 */
enum LegalType: string
{
    case LegalEntity = 'legal_entity';
    case SoleProprietor = 'sole_proprietor';
    case Individual = 'individual';
    case Government = 'government';

    /** Legal name and tax ID are required for every type except a private person. */
    public function requiresLegalName(): bool
    {
        return $this !== self::Individual;
    }

    public function requiresTaxId(): bool
    {
        return $this !== self::Individual;
    }

    /**
     * Value in the 1C customer payload (the outbound contract knows legal_entity and
     * sole_proprietor; government organizations travel as legal entities; individuals are not
     * offered to 1C, see CustomerOutbox::notPushableReason()).
     */
    public function oneCValue(): string
    {
        return match ($this) {
            self::SoleProprietor => 'sole_proprietor',
            self::Individual => 'individual',
            default => 'legal_entity',
        };
    }

    public function label(): string
    {
        return __('customers.legal_types.'.$this->value);
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
