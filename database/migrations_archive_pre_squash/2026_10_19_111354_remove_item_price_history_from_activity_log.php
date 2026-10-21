<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Item price columns that are no longer audited (Spec 01 section 6.2 rev. 2026-10-19). */
    public const PRICE_KEYS = ['sale_price_minor', 'sale_currency', 'purchase_price_minor', 'purchase_currency', 'prices_include_vat'];

    /**
     * No price or stock history (user decision 2026-10-19): items keep only the current purchase
     * and sale price; stock rows were never audited. The item change log held price changes, which
     * acted as a price history; they are removed from it here (rows that only contained price
     * changes are deleted, other rows lose the price keys). Document snapshots (offers, orders,
     * invoices, deliveries, payments) are not touched.
     *
     * Data cleanup only: down() cannot bring the old values back (the dev database was backed up
     * before running it).
     */
    public function up(): void
    {
        DB::table('activity_log')->where('subject_type', 'item')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $properties = json_decode((string) $row->properties, true);

                if (! is_array($properties)) {
                    continue;
                }

                $touched = false;

                foreach (['old', 'new'] as $side) {
                    if (isset($properties[$side]) && is_array($properties[$side])) {
                        $before = count($properties[$side]);
                        $properties[$side] = array_diff_key($properties[$side], array_flip(self::PRICE_KEYS));
                        $touched = $touched || count($properties[$side]) !== $before;
                    }
                }

                if (! $touched) {
                    continue;
                }

                if (($properties['new'] ?? []) === [] && ($properties['old'] ?? []) === []) {
                    DB::table('activity_log')->where('id', $row->id)->delete();
                } else {
                    DB::table('activity_log')->where('id', $row->id)->update(['properties' => json_encode($properties)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible data cleanup (see up()).
    }
};
