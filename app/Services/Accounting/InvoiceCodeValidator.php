<?php

namespace App\Services\Accounting;

use App\Enums\ItemType;
use App\Models\OrderLine;
use App\Support\BusinessSettings;

/**
 * Issue-time code validation (Spec 06 BR-AC.12): per line, empty `accountant_code` follows its
 * setting; for goods lines (ESF goods scope) empty `class_code` / `package_code` follow theirs.
 * Modes: off | warn | block. A missing accountant code is a warning only by user decision.
 */
class InvoiceCodeValidator
{
    /**
     * @param  iterable<OrderLine>  $lines
     * @return list<array{line: int, name: string, code: string, mode: string}>
     */
    public function check(iterable $lines): array
    {
        $modes = BusinessSettings::array('accounting.invoice_code_validation');
        $problems = [];

        foreach ($lines as $line) {
            $goods = $line->item?->type !== ItemType::Service;

            foreach (['accountant_code', 'class_code', 'package_code'] as $code) {
                $mode = (string) ($modes[$code] ?? 'warn');

                if ($mode === 'off' || filled($line->{$code}) || ($code !== 'accountant_code' && ! $goods)) {
                    continue;
                }

                $problems[] = ['line' => (int) $line->position, 'name' => (string) $line->name, 'code' => $code, 'mode' => $code === 'accountant_code' && $mode === 'block' ? 'warn' : $mode];
            }
        }

        return $problems;
    }

    /**
     * @param  list<array{line: int, name: string, code: string, mode: string}>  $problems
     */
    public static function blocks(array $problems): bool
    {
        return collect($problems)->contains('mode', 'block');
    }
}
