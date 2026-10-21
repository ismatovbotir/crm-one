<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Spec 09: customer equipment register (`customer_assets`, history `asset_events`) and service
     * tickets (parts, notes, photos). The sold serial in `item_serials` stays the source of truth of
     * ownership; assets add the customer-facing status. Adds the `service` department and backfills
     * assets for serials already sold (verified, source `sale`).
     */
    public function up(): void
    {
        Schema::create('customer_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('customers');
            $table->foreignId('item_serial_id')->nullable()->unique()->constrained('item_serials');
            $table->foreignId('item_id')->nullable()->index()->constrained('items');
            $table->string('model_name', 255)->nullable();
            $table->string('serial_raw', 100);
            $table->string('serial_normalized', 64)->index();
            $table->string('source', 30);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->string('status', 30)->default('in_use');
            $table->boolean('in_storage')->default(false);
            $table->string('location', 255)->nullable();
            $table->date('purchased_on')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('order_id')->nullable()->index()->constrained('orders');
            $table->foreignId('delivery_id')->nullable()->index()->constrained('deliveries');
            $table->date('warranty_until')->nullable()->index();
            $table->timestamp('warranty_reminded_at')->nullable();
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('organization_id')->index()->constrained('customers');
            $table->foreignId('customer_asset_id')->index()->constrained('customer_assets');
            $table->string('status', 30)->default('new')->index();
            $table->string('urgency', 20)->default('normal');
            $table->text('problem');
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('source', 20)->default('portal');
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users');
            $table->foreignId('assigned_to')->nullable()->index()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('respond_due_at')->nullable();
            $table->timestamp('repair_due_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('under_warranty')->default(false);
            $table->date('warranty_until')->nullable();
            $table->text('resolution')->nullable();
            $table->bigInteger('labor_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('replacement_note')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('onec_state', 20)->nullable();
            $table->uuid('onec_guid')->nullable();
            $table->string('onec_number', 40)->nullable();
            $table->timestamp('onec_acked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_asset_id')->index()->constrained('customer_assets')->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('service_ticket_id')->nullable()->index()->constrained('service_tickets');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('service_ticket_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->index()->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->index()->constrained('items');
            $table->string('description', 255);
            $table->decimal('quantity', 15, 3);
            $table->bigInteger('unit_price_minor')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('service_ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->index()->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('service_ticket_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->index()->constrained('service_tickets')->cascadeOnDelete();
            $table->string('path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
        });

        if (! DB::table('departments')->where('code', 'service')->exists()) {
            DB::table('departments')->insert(['code' => 'service', 'name' => null, 'is_active' => true, 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Backfill: every serial already sold becomes a verified sale asset of its customer.
        DB::table('item_serials')->whereNotNull('customer_id')->whereNotNull('sold_at')->where('status', 'sold')->orderBy('id')
            ->chunkById(500, function ($serials): void {
                $rows = [];

                foreach ($serials as $serial) {
                    $delivery = $serial->sold_delivery_line_id !== null ? DB::table('delivery_lines')->where('id', $serial->sold_delivery_line_id)->value('delivery_id') : null;
                    $order = $serial->sold_order_line_id !== null ? DB::table('order_lines')->where('id', $serial->sold_order_line_id)->value('order_id') : null;
                    $rows[] = [
                        'uuid' => (string) Str::uuid(), 'organization_id' => $serial->customer_id, 'item_serial_id' => $serial->id, 'item_id' => $serial->item_id,
                        'serial_raw' => $serial->serial_raw, 'serial_normalized' => $serial->serial_normalized, 'source' => 'sale', 'verified_at' => $serial->sold_at,
                        'status' => 'in_use', 'in_storage' => false, 'sold_at' => $serial->sold_at, 'order_id' => $order, 'delivery_id' => $delivery,
                        'warranty_until' => $serial->warranty_until, 'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                DB::table('customer_assets')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_photos');
        Schema::dropIfExists('service_ticket_notes');
        Schema::dropIfExists('service_ticket_parts');
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('customer_assets');
        DB::table('departments')->where('code', 'service')->whereNotExists(fn ($q) => $q->from('department_user')->whereColumn('department_user.department_id', 'departments.id'))->delete();
    }
};
