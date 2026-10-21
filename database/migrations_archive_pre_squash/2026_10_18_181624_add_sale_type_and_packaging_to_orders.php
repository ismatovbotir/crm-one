<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 25 (target sales flow): sale type on customers (default b2b) and orders
     * (snapshot, overridable while new); packaging as its own step: the order status `in_progress`
     * becomes `packing`, `packed` follows; the packing list (draft delivery note) records when it
     * was packed and by whom.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('sale_type', 10)->default('b2b');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('sale_type', 10)->default('b2b');
            $table->timestamp('packed_at')->nullable();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('orders')->where('status', 'in_progress')->update(['status' => 'packing']);
        DB::table('status_transitions')->where('subject_type', 'order')->where('from_status', 'in_progress')->update(['from_status' => 'packing']);
        DB::table('status_transitions')->where('subject_type', 'order')->where('to_status', 'in_progress')->update(['to_status' => 'packing']);
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('status', ['packing', 'packed'])->update(['status' => 'in_progress']);
        DB::table('status_transitions')->where('subject_type', 'order')->whereIn('from_status', ['packing', 'packed'])->update(['from_status' => 'in_progress']);
        DB::table('status_transitions')->where('subject_type', 'order')->whereIn('to_status', ['packing', 'packed'])->update(['to_status' => 'in_progress']);

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packed_by');
            $table->dropColumn('packed_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['sale_type', 'packed_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('sale_type');
        });
    }
};
