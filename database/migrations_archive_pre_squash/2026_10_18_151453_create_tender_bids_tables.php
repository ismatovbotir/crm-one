<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 7.3 + Spec 08 10.2: sealed bids (versioned per tender, counterparty and round; sha256
     * receipt hash), the evidence of employee-entered bids (BR-T.14 / Spec 08 BR-B.2), bid lots
     * (variant_no reserved for Spec 08 variants), charge/price lines and committee scores.
     * Amounts live only here and are never written to the activity log before opening.
     */
    public function up(): void
    {
        Schema::create('tender_bids', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tender_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->constrained()->restrictOnDelete();
            $table->foreignId('invitation_id')->constrained('tender_invitations')->restrictOnDelete();
            $table->unsignedSmallInteger('round_no')->default(1);
            $table->unsignedSmallInteger('version');
            $table->string('status', 16);
            $table->string('channel', 10);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('currency', 3);
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->unsignedSmallInteger('prepayment_bp')->default(0);
            $table->boolean('subcontracting')->default(false);
            $table->string('subcontractor_name')->nullable();
            $table->text('notes')->nullable();
            $table->text('exclusions')->nullable();
            $table->boolean('conditions_accepted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->boolean('needs_confirmation')->default(false);
            $table->text('correction_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('disqualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('disqualification_reason')->nullable();
            $table->timestamps();

            $table->unique(['tender_id', 'counterparty_id', 'round_no', 'version']);
            $table->index(['tender_id', 'status']);
        });

        Schema::create('tender_bid_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->unique()->constrained('tender_bids')->cascadeOnDelete();
            $table->string('received_via', 12);
            $table->timestamp('received_at');
            $table->string('sender_name');
            $table->string('sender_contact')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('tender_bid_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('tender_bids')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('tender_lots')->restrictOnDelete();
            $table->unsignedTinyInteger('variant_no')->default(1);
            $table->string('variant_label')->nullable();
            $table->bigInteger('total_minor')->default(0);
            $table->unsignedSmallInteger('transit_days')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('route')->nullable();
            $table->string('border_crossing', 120)->nullable();
            $table->string('vehicle_offered', 120)->nullable();
            $table->bigInteger('downtime_rate_minor_per_day')->nullable();
            $table->string('payment_terms_offered')->nullable();
            $table->text('exceptions')->nullable();
            // Computed by the evaluation (evaluation currency); overwritten on each recompute.
            $table->bigInteger('landed_cost_minor')->nullable();
            $table->boolean('uses_estimates')->default(false);
            $table->decimal('score', 6, 3)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->json('knockout_reasons')->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['bid_id', 'lot_id', 'variant_no']);
            $table->index('lot_id');
        });

        Schema::create('tender_bid_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->cascadeOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained('tender_items')->nullOnDelete();
            $table->string('charge_code', 30)->nullable();
            $table->string('description')->nullable();
            $table->string('basis', 20)->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->bigInteger('unit_price_minor')->nullable();
            $table->bigInteger('amount_minor')->default(0);
            $table->string('inclusion', 14)->default('included');
            // Owner's estimate for a not-included component, in the tender evaluation currency.
            $table->bigInteger('estimate_minor')->nullable();
            $table->foreignId('estimate_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('offered_brand', 120)->nullable();
            $table->string('offered_model', 120)->nullable();
            $table->boolean('compliant')->default(true);
            $table->text('deviation')->nullable();
            $table->timestamps();

            $table->index('bid_lot_id');
        });

        Schema::create('tender_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_lot_id')->constrained('tender_bid_lots')->cascadeOnDelete();
            $table->foreignId('criterion_id')->constrained('tender_criteria')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 4, 2);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['bid_lot_id', 'criterion_id', 'user_id']);
        });

        SchemaChecks::add('tender_bid_lines', [
            'tender_bid_lines_amount_chk' => 'amount_minor >= 0 and (estimate_minor is null or estimate_minor >= 0)',
        ]);
        SchemaChecks::add('tender_scores', [
            'tender_scores_range_chk' => 'score >= 0 and score <= 10',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_scores');
        Schema::dropIfExists('tender_bid_lines');
        Schema::dropIfExists('tender_bid_lots');
        Schema::dropIfExists('tender_bid_sources');
        Schema::dropIfExists('tender_bids');
    }
};
