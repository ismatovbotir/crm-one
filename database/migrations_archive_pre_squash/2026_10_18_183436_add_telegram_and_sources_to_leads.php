<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 04 section 15 (lead sources rev. 2, Telegram leads): campaign and social merge into
     * marketing (existing rows migrated, the campaign name stays); Telegram leads keep the chat and
     * the sender's language; a lead may be unassigned (owner null) until a head assigns it.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->change();
            $table->bigInteger('telegram_chat_id')->nullable()->index();
            $table->bigInteger('telegram_user_id')->nullable();
            $table->string('telegram_username', 64)->nullable();
            $table->string('locale', 5)->nullable();
        });

        DB::table('leads')->whereIn('source', ['campaign', 'social'])->update(['source' => 'marketing']);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_id']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_user_id', 'telegram_username', 'locale']);
        });
    }
};
