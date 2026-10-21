<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 02 BR-I.13a / BR-I.13b (rev. 3): accounting codes for the accountant and Uzbek
     * e-invoicing (IKPU/MXIK class code, package code) and the serial-tracking flag. All indexed,
     * none unique; shared between 1C (optional in the items payload) and the CRM item form.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('accountant_code', 64)->nullable()->index()->after('article');
            $table->string('class_code', 32)->nullable()->index()->after('accountant_code');
            $table->string('package_code', 32)->nullable()->index()->after('class_code');
            $table->boolean('is_serialized')->default(false)->index()->after('is_vat_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['accountant_code']);
            $table->dropIndex(['class_code']);
            $table->dropIndex(['package_code']);
            $table->dropIndex(['is_serialized']);
            $table->dropColumn(['accountant_code', 'class_code', 'package_code', 'is_serialized']);
        });
    }
};
