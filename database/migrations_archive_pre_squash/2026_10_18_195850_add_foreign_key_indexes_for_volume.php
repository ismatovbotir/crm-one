<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Volume test (roadmap step 8): foreign keys used for parent → child lookups, joins, counts and
     * scopes get an index. SQLite and PostgreSQL do not index foreign keys automatically (MySQL
     * does). Audit columns (`created_by`, `approved_by`, …) are left out on purpose: they are never
     * filtered on and users are deactivated, not deleted. Each index is only added when no index
     * already starts with that column, so the migration is safe on every driver.
     *
     * @var array<string, list<string>>
     */
    public const INDEXES = [
        'accounting_entries' => ['invoice_id', 'allocation_id', 'reverses_entry_id'],
        'agreements' => ['order_id', 'parent_agreement_id', 'renewal_of_id', 'template_id'],
        'categories' => ['parent_id'],
        'counterparties' => ['owner_id', 'department_id'],
        'counterparty_contacts' => ['counterparty_id'],
        'counterparty_documents' => ['counterparty_id'],
        'deliveries' => ['warehouse_id'],
        'delivery_lines' => ['item_id'],
        'departments' => ['parent_id'],
        'document_line_serials' => ['item_serial_id', 'item_id'],
        'goods_receipts' => ['warehouse_id', 'customer_id'],
        'integration_logs' => ['inbound_batch_id'],
        'invoice_lines' => ['invoice_id'],
        'invoices' => ['agreement_id'],
        'item_serials' => ['sold_order_line_id', 'warehouse_id'],
        'items' => ['unit_id', 'category_id'],
        'lead_follow_ups' => ['lead_id'],
        'lead_items' => ['item_id'],
        'leads' => ['customer_id', 'contact_id'],
        'offer_versions' => ['root_offer_id'],
        'offers' => ['contact_id', 'previous_version_id'],
        'order_lines' => ['offer_line_id', 'warehouse_id'],
        'orders' => ['framework_agreement_id', 'contact_id'],
        'payment_allocations' => ['invoice_id'],
        'payment_schedule_items' => ['agreement_id'],
        'payments' => ['recorded_by', 'refund_of_payment_id'],
        'portal_memberships' => ['contact_id', 'counterparty_id'],
        'purchase_order_lines' => ['item_id', 'tender_item_id'],
        'purchase_orders' => ['order_id', 'owner_id', 'department_id'],
        'stock_reservations' => ['warehouse_id'],
        'telegram_link_tokens' => ['user_id'],
        'tender_awards' => ['comparison_id', 'lot_id', 'bid_id', 'counterparty_id'],
        'tender_bid_lines' => ['tender_item_id'],
        'tender_bids' => ['counterparty_id', 'invitation_id', 'link_id'],
        'tender_committee' => ['user_id'],
        'tender_decisions' => ['comparison_id'],
        'tender_items' => ['item_id'],
        'tender_questions' => ['counterparty_id'],
        'tender_requirements' => ['tender_id'],
        'tender_scores' => ['criterion_id'],
        'tender_transport_specs' => ['purchase_order_id'],
        'tenders' => ['parent_tender_id', 'department_id', 'requested_by'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $leading = array_map(fn (array $index): string => $index['columns'][0], Schema::getIndexes($table));
            $missing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column) && ! in_array($column, $leading, true)));

            if ($missing !== []) {
                Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
                    foreach ($missing as $column) {
                        $blueprint->index($column);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $names = array_column(Schema::getIndexes($table), 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $names): void {
                foreach ($columns as $column) {
                    if (in_array($table.'_'.$column.'_index', $names, true)) {
                        $blueprint->dropIndex([$column]);
                    }
                }
            });
        }
    }
};
