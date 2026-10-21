<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 01 section 15 / Spec 05 section 16 (item pictures): CRM-owned pictures of an item
     * (position 0 = main, up to 5 more), re-encoded on the private disk with 96 px and 320 px
     * thumbnails; offer lines reference the picture and keep a snapshot of the thumbnail taken
     * when the offer is sent; order lines copy that snapshot.
     */
    public function up(): void
    {
        Schema::create('item_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('path');
            $table->string('medium_path');
            $table->string('thumb_path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'position']);
        });

        Schema::table('offer_lines', function (Blueprint $table) {
            $table->foreignId('item_image_id')->nullable()->constrained('item_images')->nullOnDelete();
            $table->string('image_path')->nullable();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('offer_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_image_id');
            $table->dropColumn('image_path');
        });

        Schema::dropIfExists('item_images');
    }
};
