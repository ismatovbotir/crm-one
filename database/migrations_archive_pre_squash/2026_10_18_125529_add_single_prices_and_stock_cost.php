<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pricing simplification (Spec 01/02 rev. 4): every item has exactly one sale (out) price and
     * one purchase (in) price, each with its currency, in integer minor units, both sent by 1C.
     * Stock rows carry their stock cost; the item's stock cost price is the weighted average.
     *
     * Backfill: the sale price is taken from the current default price list (base currency first),
     * newest row valid today at quantity tier 0, together with its currency and VAT basis. The old
     * price-list tables are dropped by the next migration.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_price_minor')->nullable()->after('is_serialized');
            $table->char('sale_currency', 3)->nullable()->after('sale_price_minor');
            $table->unsignedBigInteger('purchase_price_minor')->nullable()->after('sale_currency');
            $table->char('purchase_currency', 3)->nullable()->after('purchase_price_minor');
            $table->boolean('prices_include_vat')->default(false)->after('purchase_currency');
        });

        Schema::table('item_stocks', function (Blueprint $table) {
            // Total cost of the quantity on hand; signed because 1C allows negative balances.
            $table->bigInteger('cost_minor')->nullable()->after('reserved');
            $table->char('cost_currency', 3)->nullable()->after('cost_minor');
        });

        $this->backfillSalePrices();
    }

    public function down(): void
    {
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->dropColumn(['cost_minor', 'cost_currency']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sale_price_minor', 'sale_currency', 'purchase_price_minor', 'purchase_currency', 'prices_include_vat']);
        });
    }

    protected function backfillSalePrices(): void
    {
        if (! Schema::hasTable('price_lists') || ! Schema::hasTable('price_list_items')) {
            return;
        }

        $base = strtoupper((string) config('crm.base_currency', 'UZS'));
        $today = now()->toDateString();

        $lists = DB::table('price_lists')
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get(['id', 'currency', 'prices_include_vat'])
            ->sortBy(fn (object $list): string => ($list->currency === $base ? '0' : '1').str_pad((string) $list->id, 12, '0', STR_PAD_LEFT))
            ->values();

        if ($lists->isEmpty()) {
            return;
        }

        DB::table('items')->orderBy('id')->select('id')->chunkById(500, function ($items) use ($lists, $today): void {
            foreach ($items as $item) {
                foreach ($lists as $list) {
                    $price = DB::table('price_list_items')
                        ->where('price_list_id', $list->id)
                        ->where('item_id', $item->id)
                        ->where('min_qty', 0)
                        ->where('valid_from', '<=', $today)
                        ->orderByDesc('valid_from')
                        ->value('price_minor');

                    if ($price !== null) {
                        DB::table('items')->where('id', $item->id)->update([
                            'sale_price_minor' => (int) $price,
                            'sale_currency' => $list->currency,
                            'prices_include_vat' => (bool) $list->prices_include_vat,
                        ]);

                        break;
                    }
                }
            }
        });
    }
};
