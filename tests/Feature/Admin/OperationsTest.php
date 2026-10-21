<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Services\Maintenance\BackupManager;
use App\Services\Maintenance\SetupChecklist;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;
use ZipArchive;

/**
 * Production readiness (roadmap step 10): backup / verify / restore, retention, health endpoint,
 * production checklist and notification retention.
 */
class OperationsTest extends TestCase
{
    /** No wrapping transaction: SQLite cannot VACUUM INTO inside one. */
    use DatabaseMigrations;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = storage_path('framework/testing/backups-'.uniqid());
        config(['operations.backups.path' => $this->backupDir, 'operations.backups.storage_roots' => ['private']]);
        File::ensureDirectoryExists(storage_path('app/private/test-ops'));
        File::put(storage_path('app/private/test-ops/doc.txt'), 'original');
        File::ensureDirectoryExists(storage_path('app/private/livewire-tmp'));
        File::put(storage_path('app/private/livewire-tmp/upload.tmp'), 'temp');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDir);
        File::deleteDirectory(storage_path('app/private/test-ops'));
        File::delete(storage_path('app/private/livewire-tmp/upload.tmp'));
        parent::tearDown();
    }

    /**
     * Backups of MySQL / PostgreSQL need the client dump tool; skip when it is not installed
     * (SQLite copies the file and always runs).
     */
    private function requireDumpTool(): void
    {
        $driver = DB::getDriverName();
        $tool = match ($driver) {
            'mysql', 'mariadb' => (string) config('operations.backups.mysqldump'),
            'pgsql' => (string) config('operations.backups.pg_dump', 'pg_dump'),
            default => null,
        };

        if ($tool !== null && (new ExecutableFinder)->find($tool) === null && ! is_file($tool)) {
            $this->markTestSkipped("{$tool} is not on PATH for the {$driver} backup test.");
        }
    }

    public function test_backup_contains_database_files_and_manifest_and_verifies(): void
    {
        $this->requireDumpTool();
        $this->artisan('crm:backup')->assertSuccessful();

        $backups = app(BackupManager::class)->list();
        $this->assertCount(1, $backups);
        $zip = new ZipArchive;
        $zip->open($backups[0]['path']);
        $this->assertNotFalse($zip->locateName(DB::getDriverName() === 'sqlite' ? 'database.sqlite' : 'database.sql'));
        $this->assertSame('original', $zip->getFromName('storage/private/test-ops/doc.txt'));
        $this->assertFalse($zip->locateName('storage/private/livewire-tmp/upload.tmp'), 'Temporary uploads are not backed up.');
        $this->assertSame(DB::getDriverName(), json_decode((string) $zip->getFromName('manifest.json'), true)['driver']);
        $zip->close();

        $this->artisan('crm:backup', ['--verify' => $backups[0]['path']])->assertSuccessful();
    }

    public function test_tampered_or_unsafe_archives_are_refused(): void
    {
        $this->requireDumpTool();
        $path = app(BackupManager::class)->backup()['path'];
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('storage/private/test-ops/doc.txt', 'changed');
        $zip->close();

        try {
            app(BackupManager::class)->verify($path);
            $this->fail('A changed file must fail the checksum.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Checksum mismatch', $exception->getMessage());
        }

        $evil = $this->backupDir.'/crm-backup-evil.zip';
        $zip = new ZipArchive;
        $zip->open($evil, ZipArchive::CREATE);
        $zip->addFromString('../../public/shell.php', '<?php');
        $zip->addFromString('manifest.json', (string) json_encode(['driver' => 'sqlite', 'database' => 'database.sqlite', 'checksums' => ['../../public/shell.php' => hash('sha256', '<?php')]]));
        $zip->close();

        $this->expectExceptionMessage('Unexpected entry');
        app(BackupManager::class)->restore($evil);
    }

    public function test_only_the_newest_backups_are_kept(): void
    {
        $this->requireDumpTool();
        config(['operations.backups.keep' => 2]);
        File::ensureDirectoryExists($this->backupDir);

        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            File::put($this->backupDir.'/'.BackupManager::PREFIX.$stamp.'.zip', 'x');
        }

        $this->assertSame(1, app(BackupManager::class)->prune());
        $this->assertSame([BackupManager::PREFIX.'20260103-000000.zip', BackupManager::PREFIX.'20260102-000000.zip'], array_column(app(BackupManager::class)->list(), 'name'));
    }

    public function test_restore_brings_back_database_and_files(): void
    {
        $this->requireDumpTool();
        $file = storage_path('framework/testing/restore-'.uniqid().'.sqlite');
        touch($file);
        config(['database.connections.restore_probe' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $default = config('database.default');
        config(['database.default' => 'restore_probe']);
        DB::purge('restore_probe');

        try {
            DB::statement('create table probe (value text)');
            DB::table('probe')->insert(['value' => 'before']);
            $path = app(BackupManager::class)->backup()['path'];

            DB::table('probe')->update(['value' => 'after']);
            File::put(storage_path('app/private/test-ops/doc.txt'), 'changed');

            $this->artisan('crm:restore', ['archive' => $path])->expectsConfirmation('Replace the current database and stored files with this backup? Users are logged out of open forms.', 'yes')->assertSuccessful();

            $this->assertSame('before', DB::table('probe')->value('value'));
            $this->assertSame('original', File::get(storage_path('app/private/test-ops/doc.txt')));
            $this->assertNotEmpty(glob($file.'.before-restore-*'), 'A safety copy of the replaced database is kept.');
        } finally {
            DB::disconnect('restore_probe');
            config(['database.default' => $default]);
            File::delete([$file, ...(glob($file.'.before-restore-*') ?: [])]);
        }
    }

    public function test_restore_needs_force_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('crm:restore', ['archive' => 'missing.zip'])->assertFailed();
        $this->app['env'] = 'testing';
    }

    public function test_health_endpoint_reports_status_and_details_only_with_token(): void
    {
        config(['operations.health.token' => 'secret-token']);
        Storage::fake('local');

        $this->getJson(route('health'))->assertStatus(503)->assertExactJson(['status' => 'fail']);

        Cache::put(SetupChecklist::HEARTBEAT_KEY, now()->timestamp, 600);
        app(BackupManager::class)->backup();

        $this->getJson(route('health'), ['X-Health-Token' => 'wrong'])->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson(route('health'), ['X-Health-Token' => 'secret-token'])->assertOk()
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.scheduler.status', 'ok')
            ->assertJsonPath('checks.backup.status', 'ok')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_production_check_flags_blocking_settings(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://crm.local', 'mail.default' => 'log']);

        $this->artisan('crm:prod-check')->assertFailed();
    }

    public function test_read_notifications_past_retention_are_pruned(): void
    {
        $user = User::factory()->manager()->create();
        $user->notify(new ApprovalRequested('offer', 'OF-1', 'Sam', '/offers'));
        $user->notify(new ApprovalRequested('offer', 'OF-2', 'Sam', '/offers'));
        $user->notify(new ApprovalRequested('offer', 'OF-3', 'Sam', '/offers'));
        [$old, $recent] = $user->notifications()->get();
        $old->forceFill(['read_at' => now()->subDays(100)])->save();
        $recent->forceFill(['read_at' => now()->subDays(5)])->save();

        $this->artisan('crm:prune-notifications')->assertSuccessful();

        $this->assertSame(2, $user->notifications()->count(), 'Unread and recently read notifications stay.');
    }
}
