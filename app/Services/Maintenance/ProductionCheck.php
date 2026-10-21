<?php

namespace App\Services\Maintenance;

use App\Support\BusinessSettings;

/**
 * `.env` production checklist (roadmap step 10, `crm:prod-check`): each item is ok, warn or fail
 * with the setting to change. Fails block go-live; warnings are recommendations. Console output
 * for administrators (English, like other artisan output).
 */
class ProductionCheck
{
    public function __construct(protected BackupManager $backups, protected SetupChecklist $setup) {}

    /**
     * @return list<array{key: string, status: 'ok'|'warn'|'fail', message: string}>
     */
    public function run(): array
    {
        $url = (string) config('app.url');
        $logChannels = config('logging.default') === 'stack' ? (array) config('logging.channels.stack.channels') : [(string) config('logging.default')];
        $backupPath = (string) rescue(fn (): string => $this->backups->directory(), (string) config('operations.backups.path'), false);
        $publicPath = realpath(public_path()) ?: public_path();
        $backupReal = realpath($backupPath) ?: $backupPath;

        return [
            $this->item('app_env', app()->environment('production'), 'fail', 'APP_ENV=production'),
            $this->item('app_debug', config('app.debug') === false, 'fail', 'APP_DEBUG=false (debug pages leak internals; the dev login bypass needs debug)'),
            $this->item('app_key', filled(config('app.key')), 'fail', 'APP_KEY set (php artisan key:generate once, then keep it: encrypted 2FA secrets depend on it)'),
            $this->item('app_url', str_starts_with($url, 'https://'), 'fail', 'APP_URL uses https:// (links in e-mails, Telegram and prints)'),
            $this->item('session_secure', config('session.secure') === true, 'fail', 'SESSION_SECURE_COOKIE=true'),
            $this->item('session_driver', in_array(config('session.driver'), ['database', 'redis'], true), 'warn', 'SESSION_DRIVER=redis (or database)'),
            $this->item('cache_store', ! in_array(config('cache.default'), ['array', 'null'], true), 'fail', 'CACHE_STORE=redis (or file/database; never array)'),
            $this->item('queue', config('queue.default') !== 'sync', 'fail', 'QUEUE_CONNECTION=database or redis, with a supervised worker for queues "onec-inbound,default"'),
            $this->item('mail', ! in_array(config('mail.default'), ['log', 'array'], true), 'fail', 'MAIL_MAILER=smtp with MAIL_HOST/MAIL_USERNAME/MAIL_PASSWORD/MAIL_FROM_ADDRESS (portal login codes, agreement e-mails)'),
            $this->item('log_daily', in_array('daily', $logChannels, true), 'warn', 'LOG_STACK=daily and LOG_DAILY_DAYS=30 (rotated files instead of one growing laravel.log)'),
            $this->item('log_level', ! in_array(config('logging.channels.daily.level'), ['debug'], true), 'warn', 'LOG_LEVEL=warning (or info)'),
            $this->item('database', in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true), 'warn', 'DB_CONNECTION=mysql (MySQL 8, see docs/db-review-2026-10.md); SQLite is for development'),
            $this->item('backup_path', is_dir($backupPath) && is_writable($backupPath) && ! str_starts_with($backupReal, $publicPath), 'fail', 'BACKUP_PATH is a writable folder outside public/ (ideally another disk), BACKUP_KEEP=14'),
            $this->item('backup_recent', ($this->backups->latestAt() ?? 0) > now()->subHours(26)->timestamp, 'warn', 'A backup from the last 26 hours exists (php artisan crm:backup; scheduled daily)'),
            $this->item('health_token', filled(config('operations.health.token')), 'warn', 'HEALTH_TOKEN set (long random string) for detailed /api/health reports'),
            $this->item('two_factor', BusinessSettings::bool('security.enforce_two_factor') || (bool) config('security.enforce_two_factor'), 'warn', '2FA enforced for admin, accountant and finance (Settings > Setup or SECURITY_ENFORCE_TWO_FACTOR=true)'),
            $this->item('storage_link', is_link(public_path('storage')) || is_dir(public_path('storage')), 'warn', 'php artisan storage:link (company logo on public disk)'),
            $this->item('optimized', app()->configurationIsCached() && app()->routesAreCached(), 'warn', 'php artisan optimize (config, routes, views cached) after each deploy'),
            $this->item('setup', $this->setup->ready(), 'warn', 'Settings > Setup shows "Ready for go-live"'),
        ];
    }

    /**
     * @param  'warn'|'fail'  $severity
     * @return array{key: string, status: 'ok'|'warn'|'fail', message: string}
     */
    protected function item(string $key, bool $ok, string $severity, string $message): array
    {
        return ['key' => $key, 'status' => $ok ? 'ok' : $severity, 'message' => $message];
    }
}
