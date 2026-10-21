<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 7.1 (foundation subset): user realm (internal | portal), profile columns,
     * nullable role (portal users have none) and nullable password (portal users log in by OTP).
     * Existing rows are internal staff, so the column default backfills them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 10)->default('internal')->after('id');
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('position', 120)->nullable()->after('phone');
            $table->timestamp('last_login_at')->nullable()->after('locale');
            $table->timestamp('deactivated_at')->nullable()->after('last_login_at');
            $table->foreignId('deactivated_by')->nullable()->after('deactivated_at')->constrained('users')->nullOnDelete();

            $table->index(['type', 'is_active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->default(null)->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_active']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['type', 'phone', 'position', 'last_login_at', 'deactivated_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('sales')->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
