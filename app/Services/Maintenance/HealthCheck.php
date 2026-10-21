<?php

namespace App\Services\Maintenance;

use App\Enums\OutboxStatus;
use App\Models\OnecOutboxEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health report (roadmap step 10, `GET /api/health`). Each check is ok / warn / fail; any fail
 * makes the whole status "fail" (HTTP 503). Informational values (1C outbox backlog) only warn,
 * because 1C pulls on its own schedule.
 */
class HealthCheck
{
    public function __construct(protected BackupManager $backups) {}

    /**
     * @return array{status: 'ok'|'fail', checks: array<string, array{status: 'ok'|'warn'|'fail', detail: string}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->guard(fn (): array => DB::select('select 1 as ok')[0]->ok == 1 ? ['ok', DB::connection()->getDriverName()] : ['fail', 'no result']),
            'cache' => $this->guard(function (): array {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $value = Cache::pull($key);

                return $value === 'ok' ? ['ok', (string) config('cache.default')] : ['fail', 'read-back failed'];
            }),
            'storage' => $this->guard(function (): array {
                $path = 'health/'.Str::random(8).'.txt';
                $written = Storage::disk('local')->put($path, 'ok');
                Storage::disk('local')->delete($path);

                return $written ? ['ok', 'writable'] : ['fail', 'not writable'];
            }),
            'scheduler' => $this->guard(function (): array {
                $beat = (int) Cache::get(SetupChecklist::HEARTBEAT_KEY, 0);
                $age = $beat > 0 ? (int) floor((now()->timestamp - $beat) / 60) : null;

                return $age !== null && $age <= (int) config('operations.health.scheduler_max_age_minutes') ? ['ok', $age.' min'] : ['fail', $age === null ? 'no heartbeat' : $age.' min'];
            }),
            'queue' => $this->guard(function (): array {
                if (config('queue.default') === 'sync' || ! Schema::hasTable('jobs')) {
                    return ['warn', 'sync queue'];
                }

                $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
                $age = $oldest !== null ? (int) floor((now()->timestamp - (int) $oldest) / 60) : 0;
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
                $status = $age > (int) config('operations.health.queue_max_pending_age_minutes') ? 'fail' : ($failed > (int) config('operations.health.failed_jobs_24h_max') ? 'warn' : 'ok');

                return [$status, "oldest pending {$age} min, failed 24h {$failed}"];
            }),
            'backup' => $this->guard(function (): array {
                $latest = $this->backups->latestAt();
                $hours = $latest !== null ? (int) floor((now()->timestamp - $latest) / 3600) : null;

                return $hours !== null && $hours <= (int) config('operations.health.backup_max_age_hours') ? ['ok', $hours.' h'] : ['fail', $hours === null ? 'no backup' : $hours.' h'];
            }),
            'onec_outbox' => $this->guard(function (): array {
                $oldest = OnecOutboxEvent::query()->where('status', OutboxStatus::Pending)->min('created_at');
                $count = OnecOutboxEvent::query()->where('status', OutboxStatus::Pending)->count();

                return [$count > 0 && $oldest !== null && now()->diffInHours($oldest, true) > 24 ? 'warn' : 'ok', "{$count} available for 1C to pull"];
            }),
        ];

        return [
            'status' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail') ? 'fail' : 'ok',
            'checks' => $checks,
        ];
    }

    /**
     * @param  callable(): array{0: 'ok'|'warn'|'fail', 1: string}  $check
     * @return array{status: 'ok'|'warn'|'fail', detail: string}
     */
    protected function guard(callable $check): array
    {
        try {
            [$status, $detail] = $check();

            return ['status' => $status, 'detail' => $detail];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'fail', 'detail' => class_basename($exception)];
        }
    }
}
