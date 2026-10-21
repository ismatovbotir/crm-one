<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 08 9 / 10.2 (08-5, 08-6): frozen comparison snapshots (immutable once submitted, sha256
     * of canonical JSON) and the decision record extension (snapshot id + hash, selected bid lots,
     * step-up confirmation channel). Awards point to the snapshot they were decided on.
     */
    public function up(): void
    {
        Schema::create('tender_comparisons', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->unsignedSmallInteger('version');
            $table->string('status', 12);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['tender_id', 'round_no', 'version']);
        });

        Schema::table('tender_decisions', function (Blueprint $table) {
            $table->foreignId('comparison_id')->nullable()->after('award_id')->constrained('tender_comparisons')->restrictOnDelete();
            $table->char('comparison_hash', 64)->nullable()->after('comparison_id');
            $table->json('selected')->nullable()->after('comparison_hash');
            $table->string('confirmed_via', 10)->nullable()->after('decided_by');
        });

        Schema::table('tender_awards', function (Blueprint $table) {
            $table->foreignId('comparison_id')->nullable()->after('bid_lot_id')->constrained('tender_comparisons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tender_awards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comparison_id');
        });

        Schema::table('tender_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comparison_id');
            $table->dropColumn(['comparison_hash', 'selected', 'confirmed_via']);
        });

        Schema::dropIfExists('tender_comparisons');
    }
};
