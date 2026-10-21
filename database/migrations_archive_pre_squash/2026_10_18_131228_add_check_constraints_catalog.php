<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * DB review 2026-10 N6 (F-11), adapted to rev. 4: CHECK constraints on existing catalog tables
     * (MySQL only, SchemaChecks no-ops on SQLite). Stock quantity may be negative (1C allows it);
     * stock cost too (negative balance). Pre-flight each expression on production data first.
     */
    /** @var array<string, array<string, string>> */
    private array $checks = [
        'item_stocks' => ['item_stocks_reserved_chk' => 'reserved >= 0'],
        'items' => [
            'items_tax_bp_chk' => 'tax_rate_bp <= 10000',
            'items_moq_chk' => 'min_order_qty is null or min_order_qty > 0',
            'items_step_chk' => 'order_step is null or order_step > 0',
            'items_sale_currency_chk' => 'sale_price_minor is null or sale_currency is not null',
            'items_purchase_currency_chk' => 'purchase_price_minor is null or purchase_currency is not null',
        ],
        'units' => ['units_precision_chk' => '`precision` <= 3'],
    ];

    public function up(): void
    {
        foreach ($this->checks as $table => $checks) {
            SchemaChecks::add($table, $checks);
        }
    }

    public function down(): void
    {
        foreach ($this->checks as $table => $checks) {
            SchemaChecks::drop($table, array_keys($checks));
        }
    }
};
