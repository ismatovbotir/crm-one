<?php

namespace App\Enums;

/**
 * Incoterms 2020.
 */
enum Incoterm: string
{
    case Exw = 'EXW';
    case Fca = 'FCA';
    case Fas = 'FAS';
    case Fob = 'FOB';
    case Cfr = 'CFR';
    case Cif = 'CIF';
    case Cpt = 'CPT';
    case Cip = 'CIP';
    case Dap = 'DAP';
    case Dpu = 'DPU';
    case Ddp = 'DDP';

    public function label(): string
    {
        return __('tenders.incoterms.'.$this->value);
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
