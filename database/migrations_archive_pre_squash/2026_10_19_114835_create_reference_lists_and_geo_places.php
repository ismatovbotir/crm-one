<?php

use App\Support\ReferenceSeed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 24.7 (New partner form decisions):
     *  - `reference_items`: managed lists (industry, currency, contact position, supplier category)
     *    with names in uz/ru/en, active flag, order and source (seed | admin | user | legacy);
     *  - `geo_places`: regions and cities/districts of Uzbekistan;
     *  - partners get industry_id, region_id, city_id; counterparties get supplier_category_id;
     *  - existing free-text industries become list entries (matching names reuse the seeded entry,
     *    others become inactive "legacy" entries, nothing is lost); free-text cities of UZ partners
     *    that match a city name get region/city ids; the text columns stay as they are.
     * legal_type is a string(20) column: the new values `individual` and `government` need no schema change.
     */
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table) {
            $table->id();
            $table->string('list', 30);
            $table->string('code', 60);
            $table->json('names');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source', 10)->default('admin');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['list', 'code']);
            $table->index(['list', 'is_active', 'sort_order']);
        });

        Schema::create('geo_places', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('code', 80)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('geo_places')->nullOnDelete();
            $table->char('country', 2)->default('UZ');
            $table->json('names');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['kind', 'parent_id']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->foreignId('industry_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('geo_places')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('geo_places')->nullOnDelete();
        });

        Schema::table('counterparties', function (Blueprint $table) {
            $table->foreignId('supplier_category_id')->nullable()->constrained('reference_items')->nullOnDelete();
        });

        ReferenceSeed::install();

        // Free-text industries -> list entries (nothing is lost).
        $industries = DB::table('reference_items')->where('list', 'industry')->get();
        $legacy = 0;

        foreach (DB::table('partners')->whereNotNull('industry')->where('industry', '!=', '')->distinct()->pluck('industry') as $text) {
            $needle = mb_strtolower(trim((string) $text));
            $match = $industries->first(fn ($row): bool => in_array($needle, array_map('mb_strtolower', array_values((array) json_decode($row->names, true))), true) || $needle === $row->code);

            if ($match === null) {
                $legacy++;
                $id = DB::table('reference_items')->insertGetId([
                    'list' => 'industry', 'code' => 'legacy_'.$legacy.'_'.substr(md5($needle), 0, 6),
                    'names' => json_encode(['uz' => trim((string) $text), 'ru' => trim((string) $text), 'en' => trim((string) $text)], JSON_UNESCAPED_UNICODE),
                    'is_active' => false, 'sort_order' => 1000 + $legacy, 'source' => 'legacy', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $id = $match->id;
            }

            DB::table('partners')->where('industry', $text)->update(['industry_id' => $id]);
        }

        // Free-text cities of UZ partners -> region/city where a city name matches.
        $cities = DB::table('geo_places')->where('kind', 'city')->get();

        foreach (DB::table('partners')->where('country', 'UZ')->whereNotNull('city')->distinct()->pluck('city') as $text) {
            $needle = mb_strtolower(trim((string) $text));
            $match = $cities->first(fn ($row): bool => in_array($needle, array_map('mb_strtolower', array_values((array) json_decode($row->names, true))), true));

            if ($match !== null) {
                DB::table('partners')->where('country', 'UZ')->where('city', $text)->update(['city_id' => $match->id, 'region_id' => $match->parent_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropForeign(['supplier_category_id']);
        });
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropColumn('supplier_category_id');
        });
        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['industry_id']);
            $table->dropForeign(['region_id']);
            $table->dropForeign(['city_id']);
        });
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['industry_id', 'region_id', 'city_id']);
        });
        Schema::dropIfExists('geo_places');
        Schema::dropIfExists('reference_items');
    }
};
