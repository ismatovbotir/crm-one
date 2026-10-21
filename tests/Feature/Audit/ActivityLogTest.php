<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Spec 01 BR-14 / section 6.2: append-only log with causer and only the changed fields.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_records_only_changed_fields_with_causer(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create(['sort_order' => 10]);
        $this->actingAs($admin);

        $department->update(['sort_order' => 20]);

        $log = $department->activities()->first();
        $this->assertSame('updated', $log->event);
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertEquals(['old' => ['sort_order' => 10], 'new' => ['sort_order' => 20]], $log->properties);
    }

    public function test_secrets_are_never_logged(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => 'another-secret-1']);

        foreach (ActivityLog::query()->where('subject_type', 'user')->get() as $log) {
            $this->assertStringNotContainsString('password', (string) json_encode($log->properties));
        }

        $this->assertSame(1, ActivityLog::query()->where('subject_type', 'user')->count(), 'A password-only change writes no row.');
    }

    public function test_archive_and_restore_are_logged(): void
    {
        $department = Department::factory()->create();

        $department->delete();
        $department->restore();

        $this->assertSame(['restored', 'archived', 'created'], $department->activities()->pluck('event')->all());
    }

    public function test_rows_are_append_only(): void
    {
        $department = Department::factory()->create();
        $log = $department->activities()->firstOrFail();

        $this->expectException(LogicException::class);

        $log->update(['event' => 'tampered']);
    }

    public function test_rows_cannot_be_deleted(): void
    {
        $log = Department::factory()->create()->activities()->firstOrFail();

        $this->expectException(LogicException::class);

        $log->delete();
    }
}
