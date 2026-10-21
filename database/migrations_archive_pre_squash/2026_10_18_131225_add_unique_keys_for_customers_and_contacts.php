<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB review 2026-10 N3 (F-07): the "one primary contact per customer" and "unique tax ID among
     * active CRM-created customers" rules move from the application into unique generated keys
     * (works on SQLite 3.31+ and MySQL 8). 1C-originated duplicates stay allowed (BR-I.17).
     * SaveContact unsets the old primary before setting the new one, so swaps never collide.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_key')->nullable()
                ->virtualAs('case when is_primary = 1 and deleted_at is null then customer_id end');
            $table->unique('primary_key', 'contacts_one_primary_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('crm_tax_key', 32)->nullable()
                ->virtualAs("case when origin = 'crm' and deleted_at is null and tax_id is not null then tax_id end");
            $table->unique('crm_tax_key', 'customers_crm_tax_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_crm_tax_unique');
            $table->dropColumn('crm_tax_key');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_one_primary_unique');
            $table->dropColumn('primary_key');
        });
    }
};
