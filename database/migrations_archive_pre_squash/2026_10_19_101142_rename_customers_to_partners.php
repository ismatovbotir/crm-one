<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 24 (Partner model), stage A:
     *  - table `customers` → `partners` (foreign keys of other tables follow the rename: SQLite
     *    rewrites them, MySQL keeps them by table id); its `customers_*` indexes are renamed;
     *  - organization-wide links become `partner_id`: contacts.customer_id, and organization_id on
     *    counterparties, portal_memberships, customer_assets, service_tickets,
     *    catalog_visibility_rules, requisitions (indexes renamed with them);
     *  - role-specific links keep `customer_id` (orders, offers, leads, agreements, invoices,
     *    payments, ledger, outboxes, serials, receipts, telegram chats, portal memberships, bank
     *    accounts): they point to a partner in the customer role;
     *  - stored morph type `customer` / `organization` → `partner` (the old aliases still resolve).
     * Reversible; no data is changed except the morph type values.
     */
    public const COLUMNS = [
        'contacts' => ['customer_id', 'partner_id'],
        'counterparties' => ['organization_id', 'partner_id'],
        'portal_memberships' => ['organization_id', 'partner_id'],
        'customer_assets' => ['organization_id', 'partner_id'],
        'service_tickets' => ['organization_id', 'partner_id'],
        'catalog_visibility_rules' => ['organization_id', 'partner_id'],
        'requisitions' => ['organization_id', 'partner_id'],
    ];

    public function up(): void
    {
        Schema::rename('customers', 'partners');
        $this->renameIndexes('partners', 'customers_', 'partners_');

        foreach (self::COLUMNS as $table => [$from, $to]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($from, $to));
            $this->renameIndexes($table, $table.'_'.$from, $table.'_'.$to);
        }

        DB::table('activity_log')->whereIn('subject_type', ['customer', 'organization'])->update(['subject_type' => 'partner']);
        DB::table('attachments')->whereIn('attachable_type', ['customer', 'organization'])->update(['attachable_type' => 'partner']);
    }

    public function down(): void
    {
        DB::table('attachments')->where('attachable_type', 'partner')->update(['attachable_type' => 'customer']);
        DB::table('activity_log')->where('subject_type', 'partner')->update(['subject_type' => 'customer']);

        foreach (array_reverse(self::COLUMNS, true) as $table => [$from, $to]) {
            $this->renameIndexes($table, $table.'_'.$to, $table.'_'.$from);
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameColumn($to, $from));
        }

        $this->renameIndexes('partners', 'partners_', 'customers_');
        Schema::rename('partners', 'customers');
    }

    /** Renames every non-primary index of the table whose name starts with $from. */
    protected function renameIndexes(string $table, string $from, string $to): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary'] || ! str_starts_with($index['name'], $from) || str_starts_with($index['name'], 'sqlite_')) {
                continue;
            }

            $new = $to.substr($index['name'], strlen($from));
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->renameIndex($index['name'], $new));
        }
    }
};
