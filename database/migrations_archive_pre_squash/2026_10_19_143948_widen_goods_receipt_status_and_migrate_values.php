<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 section 26.4: goods receipts get their own status set (GoodsReceiptStatus).
     * Existing drafts become `received_from_1c` (pushed by 1C, nothing entered yet) or
     * `in_progress` (CRM-created, or a 1C receipt with entered quantities/serials); `posted` and
     * `cancelled` keep their values. Delivery notes keep draft/posted/cancelled.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('status', 20)->change();
        });

        DB::table('goods_receipts')->where('status', 'draft')->where('origin', 'onec')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('document_line_serials')
                ->whereColumn('document_line_serials.document_id', 'goods_receipts.id')
                ->where('document_line_serials.document_type', 'goods_receipt')
                ->where('document_line_serials.status', 'draft'))
            ->update(['status' => 'received_from_1c']);

        DB::table('goods_receipts')->where('status', 'draft')->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        DB::table('goods_receipts')->whereIn('status', ['received_from_1c', 'in_progress', 'checked', 'on_hold'])->update(['status' => 'draft']);

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('status', 12)->change();
        });
    }
};
