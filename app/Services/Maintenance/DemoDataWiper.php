<?php

namespace App\Services\Maintenance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes demo data before go-live (roadmap step 7, `crm:wipe-demo`). Safety rules:
 *  - refuses when any business record belongs to a user who is not a demo user (`@crm.test`), so
 *    real data entered after the demo can never be wiped by mistake (unless `--allow-real`, which
 *    is refused in production);
 *  - in production it needs `--force`;
 *  - the catalog (items, units, categories, stock, serials) is only removed with `--catalog`
 *    (it normally comes from 1C again);
 *  - keeps settings, departments, agreement templates, company profile and non-demo users.
 * Tables are emptied in dependency order inside one transaction with foreign keys switched off.
 */
class DemoDataWiper
{
    public const DEMO_DOMAIN = '@crm.test';

    /** Business tables, children first. */
    public const BUSINESS_TABLES = [
        'lead_follow_ups', 'lead_items', 'lead_drafts',
        'tender_scores', 'tender_bid_lines', 'tender_bid_lots', 'tender_bid_sources', 'tender_link_events', 'tender_bids', 'tender_decisions', 'tender_comparisons', 'tender_awards',
        'purchase_order_lines', 'purchase_orders', 'tender_invitation_links', 'tender_invitations', 'tender_questions', 'tender_addenda', 'tender_committee', 'tender_requirements', 'tender_criteria',
        'tender_transport_specs', 'tender_items', 'tender_lots', 'tender_sources', 'tenders',
        'organization_merges', 'counterparty_ratings', 'counterparty_documents', 'counterparty_contacts', 'counterparties',
        'payment_allocations', 'payment_schedule_items', 'accounting_entries', 'payments', 'invoice_lines', 'invoices',
        'document_line_serials', 'serial_events', 'serial_import_rows', 'serial_import_batches', 'delivery_lines', 'deliveries', 'goods_receipt_lines', 'goods_receipts', 'stock_reservations',
        'agreement_dispatches', 'agreement_versions', 'agreements',
        'order_lines', 'orders',
        'offer_versions', 'offer_approvals', 'offer_selection_events', 'offer_line_selections', 'offer_lines', 'offer_option_groups', 'offers',
        'leads', 'portal_memberships', 'contacts', 'customer_bank_accounts', 'customer_outbox', 'onec_outbox',
        'partners', 'attachments', 'status_transitions', 'activity_log', 'notifications',
    ];

    public const CATALOG_TABLES = ['item_images', 'item_serials', 'item_stocks', 'item_alternatives', 'items', 'categories', 'units'];

    /**
     * Owners of business records that are not demo users (empty = safe to wipe).
     *
     * @return list<string>
     */
    public function realOwners(): array
    {
        $demo = User::query()->where('email', 'like', '%'.self::DEMO_DOMAIN)->pluck('id')->all();
        $owners = collect();

        foreach (['leads' => 'owner_id', 'partners' => 'owner_id', 'offers' => 'owner_id', 'orders' => 'owner_id', 'payments' => 'recorded_by'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $owners = $owners->merge(DB::table($table)->whereNotNull($column)->whereNotIn($column, $demo)->distinct()->pluck($column));
            }
        }

        return User::query()->whereKey($owners->unique()->all())->pluck('email')->all();
    }

    /**
     * @return array<string, int> rows removed per table
     */
    public function wipe(bool $catalog): array
    {
        $tables = array_values(array_filter([...self::BUSINESS_TABLES, ...($catalog ? self::CATALOG_TABLES : [])], fn (string $table): bool => Schema::hasTable($table)));
        $removed = [];

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($tables, &$removed): void {
                foreach ($tables as $table) {
                    $removed[$table] = DB::table($table)->delete();
                }

                DB::table('number_sequences')->delete();
                $demoUsers = User::query()->where('email', 'like', '%'.self::DEMO_DOMAIN)->pluck('id');
                DB::table('telegram_links')->whereIn('user_id', $demoUsers)->delete();
                DB::table('department_user')->whereIn('user_id', $demoUsers)->delete();
                $removed['users'] = User::query()->whereKey($demoUsers)->delete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return array_filter($removed);
    }
}
