<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB review 2026-10 N5 (F-09, F-19), index part only. FULLTEXT (ngram) is deferred until the
     * list components switch to MATCH ... AGAINST on MySQL (recorded in docs/db-review-2026-10.md).
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->index('phone');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Own-scope list sorted by name; owner_id alone becomes a prefix.
            $table->index(['owner_id', 'name'], 'customers_owner_name_index');
            $table->dropIndex(['owner_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('owner_id');
            $table->dropIndex('customers_owner_name_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['phone']);
        });
    }
};
