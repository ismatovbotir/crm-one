<?php

use App\Actions\Departments\BackfillDepartmentMemberships;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 03 section 7.2: departments and memberships, seeded with the standard departments
     * and backfilled from the existing global roles (non-breaking, section 4.9).
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });

        $now = now();

        foreach (BackfillDepartmentMemberships::STANDARD_DEPARTMENTS as $order => $code) {
            DB::table('departments')->insert([
                'code' => $code,
                'name' => null,
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        (new BackfillDepartmentMemberships)->handle();
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
