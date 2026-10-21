<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 17.10 (admin matching of Telegram users): a link made by an administrator stays
     * "pending verification" (no notifications, no bot commands) until the user confirms it with a
     * code sent by the bot to that exact chat, or an administrator records "verified in person" with a
     * reason. Existing links were made by the users themselves (deep link): they count as verified.
     */
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_method', 20)->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('verify_code_hash', 64)->nullable();
            $table->timestamp('verify_code_expires_at')->nullable();
            $table->unsignedTinyInteger('verify_attempts')->default(0);
            $table->string('force_reason', 500)->nullable();
        });

        DB::table('telegram_links')->update(['verified_at' => DB::raw('linked_at'), 'verification_method' => 'self_link']);
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropForeign(['linked_by']);
        });

        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verification_method', 'linked_by', 'verify_code_hash', 'verify_code_expires_at', 'verify_attempts', 'force_reason']);
        });
    }
};
