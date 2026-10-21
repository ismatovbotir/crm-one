<?php

namespace App\Services\Offers;

use App\Enums\OptionGroupKind;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Support\Quantity;
use Carbon\CarbonImmutable;

/**
 * Checks before submit/send (Spec 05 6.3). Returns issues with a V-code, a translation key
 * (offers.validation.*), a level (blocker | warning) and parameters for the message.
 */
class OfferValidator
{
    public const BLOCKER = 'blocker';

    public const WARNING = 'warning';

    /**
     * @return list<array{code: string, key: string, level: string, params: array<string, string|int>}>
     */
    public function validate(Offer $offer): array
    {
        $offer->loadMissing(['lines.item', 'groups', 'contact']);
        $issues = [];
        $add = function (string $code, string $key, string $level, array $params = []) use (&$issues): void {
            $issues[] = ['code' => $code, 'key' => $key, 'level' => $level, 'params' => $params];
        };

        $blocks = $offer->blocks();
        $fixedCount = $blocks->where('type', 'line')->count();
        $alternativeCount = $blocks->where('type', 'group')->filter(fn (array $block): bool => $block['group']->kind === OptionGroupKind::Alternative && $block['lines']->isNotEmpty())->count();

        if ($fixedCount === 0 && $alternativeCount === 0) {
            $add('V1', 'no_content', self::BLOCKER);
        }

        $maxOptions = (int) config('offers.max_options_per_group', 6);

        foreach ($blocks->where('type', 'group') as $block) {
            $group = $block['group'];
            $count = $block['lines']->count();
            $params = ['group' => $block['number'].' '.$group->title];

            if ($group->kind === OptionGroupKind::Alternative && ($count < 2 || $count > $maxOptions)) {
                $add('V2', 'alternative_size', self::BLOCKER, [...$params, 'max' => $maxOptions]);
            }

            if ($group->kind === OptionGroupKind::Optional && $count < 1) {
                $add('V2', 'optional_size', self::BLOCKER, $params);
            }

            if ($group->min_select > $group->max_select || $group->max_select > max(1, $count) || ($group->kind === OptionGroupKind::Alternative && $group->max_select < 1)) {
                $add('V3', 'min_max', self::BLOCKER, $params);
            }

            if ($group->kind === OptionGroupKind::Alternative && $count > 0 && $block['lines']->where('is_recommended', true)->isEmpty()) {
                $add('V4', 'no_recommended', self::WARNING, $params);
            }

            if ($block['lines']->pluck('item_id')->duplicates()->isNotEmpty()) {
                $add('V7', 'duplicate_item', self::BLOCKER, $params);
            }

            if ($group->kind === OptionGroupKind::Alternative) {
                foreach ($block['lines'] as $line) {
                    if (blank($line->comparison_notes) && $line->alternative_reason === null) {
                        $add('V6', 'no_difference', self::WARNING, ['line' => $block['number'].$line->option_label]);
                    }
                }
            }
        }

        foreach ($blocks as $block) {
            foreach ($block['type'] === 'line' ? [$block['line']] : $block['lines'] as $line) {
                $this->lineIssues($line, $block['number'].($line->option_label ?? ''), $add);
            }
        }

        $today = CarbonImmutable::now((string) config('offers.timezone'))->startOfDay();
        $maxDate = $today->addDays((int) config('offers.max_validity_days', 90));

        if ($offer->valid_until->toDateString() < $today->toDateString() || $offer->valid_until->toDateString() > $maxDate->toDateString()) {
            $add('V8', 'validity', self::BLOCKER, ['max' => (int) config('offers.max_validity_days', 90)]);
        }

        if ($offer->contact === null || blank($offer->contact->email)) {
            $add('V9', 'contact_email', self::WARNING);
        }

        return $issues;
    }

    /**
     * @param  list<array{code: string, key: string, level: string, params: array<string, string|int>}>  $issues
     */
    public static function hasBlockers(array $issues): bool
    {
        return collect($issues)->contains('level', self::BLOCKER);
    }

    /**
     * Quantity rules shared with the line actions (V5): > 0, unit precision, whole numbers for
     * serial-tracked items (Spec 02 BR-I.13b), minimum order quantity and order step.
     * Returns a translation key under offers.validation or null.
     */
    public static function quantityError(string $quantity, int $unitPrecision, bool $isSerialized, ?string $minOrderQty = null, ?string $orderStep = null): ?string
    {
        $quantity = Quantity::normalize($quantity);

        if (! Quantity::isDecimal($quantity) || bccomp($quantity, '0', Quantity::SCALE) <= 0) {
            return 'quantity_positive';
        }

        if ($isSerialized && Quantity::decimals($quantity) > 0) {
            return 'quantity_serialized';
        }

        if (! Quantity::fitsPrecision($quantity, $unitPrecision)) {
            return 'quantity_precision';
        }

        if ($minOrderQty !== null && bccomp($minOrderQty, '0', Quantity::SCALE) > 0 && Quantity::compare($quantity, $minOrderQty) < 0) {
            return 'quantity_min_order';
        }

        if ($orderStep !== null && ! Quantity::isMultipleOf($quantity, $orderStep)) {
            return 'quantity_step';
        }

        return null;
    }

    /**
     * @param  callable(string, string, string, array<string, string|int>): void  $add
     */
    protected function lineIssues(OfferLine $line, string $label, callable $add): void
    {
        $item = $line->item;

        if ($item === null || ! $item->isSellable()) {
            $add('V5', 'item_not_sellable', self::BLOCKER, ['line' => $label]);

            return;
        }

        $error = self::quantityError(
            (string) $line->quantity,
            $line->unit_precision,
            $line->is_serialized,
            $item->min_order_qty !== null ? (string) $item->min_order_qty : null,
            $item->order_step !== null ? (string) $item->order_step : null,
        );

        if ($error !== null) {
            $add('V5', $error, self::BLOCKER, ['line' => $label, 'precision' => $line->unit_precision, 'min' => (string) $item->min_order_qty, 'step' => (string) $item->order_step]);
        }
    }
}
