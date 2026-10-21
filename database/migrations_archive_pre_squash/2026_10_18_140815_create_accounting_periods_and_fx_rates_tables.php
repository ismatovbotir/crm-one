<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 06 7.14: accounting periods (lock/reopen, BR-AC.13) and manual CBU exchange rates
     * (UZS per 1 unit, BR-AC.8). A period row exists only once it has been locked at least once;
     * no row = open.
     */
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();
            $table->string('status', 8);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source', 12)->default('cbu_manual');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['currency', 'rate_date']);
        });

        SchemaChecks::add('fx_rates', ['fx_rates_rate_chk' => 'rate > 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('accounting_periods');
    }
};
