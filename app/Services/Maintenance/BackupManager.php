<?php

namespace App\Services\Maintenance;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Backup and restore (roadmap step 10, `crm:backup` / `crm:restore`). One ZIP per run:
 *  - `database.sqlite` (SQLite: consistent copy via VACUUM INTO) or `database.sql` (MySQL:
 *    mysqldump --single-transaction; PostgreSQL: pg_dump), produced by the local client tools;
 *  - `storage/<root>/...` files of storage/app (private documents, pictures, logo), except
 *    temporary uploads;
 *  - `manifest.json` with driver, app name, creation time and a SHA-256 per entry.
 * Restore verifies every checksum before touching anything, refuses unsafe entry names, keeps a
 * safety copy of the current SQLite file and puts files back in place. Nothing is sent anywhere:
 * archives stay in the configured folder (copy them off-site with your own tooling).
 */
class BackupManager
{
    public const PREFIX = 'crm-backup-';

    public function directory(): string
    {
        $path = (string) config('operations.backups.path');
        File::ensureDirectoryExists($path, 0750);

        return $path;
    }

    /**
     * @return array{path: string, bytes: int, entries: int}
     */
    public function backup(): array
    {
        $stamp = now()->format('Ymd-His');
        $work = storage_path('framework/backup-'.$stamp.'-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($work, 0700);
        $target = $this->directory().DIRECTORY_SEPARATOR.self::PREFIX.$stamp.'.zip';

        try {
            $dump = $this->dumpDatabase($work);
            $zip = new ZipArchive;

            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Cannot create '.$target);
            }

            $checksums = [basename($dump) => hash_file('sha256', $dump)];
            $zip->addFile($dump, basename($dump));

            foreach ($this->storageFiles() as $relative => $absolute) {
                $entry = 'storage/'.$relative;
                $checksums[$entry] = hash_file('sha256', $absolute);
                $zip->addFile($absolute, $entry);
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'app' => config('app.name'),
                'driver' => DB::connection()->getDriverName(),
                'created_at' => now()->toIso8601String(),
                'database' => basename($dump),
                'checksums' => $checksums,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException('Cannot write '.$target);
            }
        } catch (\Throwable $exception) {
            File::delete($target);

            throw $exception;
        } finally {
            File::deleteDirectory($work);
        }

        $this->prune();

        return ['path' => $target, 'bytes' => (int) filesize($target), 'entries' => count($checksums)];
    }

    /**
     * Backups, newest first.
     *
     * @return list<array{name: string, path: string, bytes: int, created_at: int}>
     */
    public function list(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];
        rsort($files);

        return array_map(fn (string $path): array => ['name' => basename($path), 'path' => $path, 'bytes' => (int) filesize($path), 'created_at' => (int) filemtime($path)], $files);
    }

    public function latestAt(): ?int
    {
        return $this->list()[0]['created_at'] ?? null;
    }

    /**
     * Keeps the newest `operations.backups.keep` archives.
     *
     * @return int archives deleted
     */
    public function prune(): int
    {
        $old = array_slice($this->list(), max(1, (int) config('operations.backups.keep')));

        foreach ($old as $backup) {
            File::delete($backup['path']);
        }

        return count($old);
    }

    /**
     * Checks an archive without changing anything; returns its manifest.
     *
     * @return array{app: string, driver: string, created_at: string, database: string, checksums: array<string, string>}
     */
    public function verify(string $path): array
    {
        $zip = $this->open($path);

        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

            if (! is_array($manifest) || ! is_array($manifest['checksums'] ?? null) || ! is_string($manifest['database'] ?? null)) {
                throw new RuntimeException('The archive has no valid manifest.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if ($name === 'manifest.json') {
                    continue;
                }

                if (! $this->isSafeEntry($name) || ! isset($manifest['checksums'][$name])) {
                    throw new RuntimeException('Unexpected entry in the archive: '.$name);
                }

                if (! hash_equals((string) $manifest['checksums'][$name], hash('sha256', (string) $zip->getFromIndex($i)))) {
                    throw new RuntimeException('Checksum mismatch: '.$name);
                }
            }

            foreach (array_keys($manifest['checksums']) as $name) {
                if ($zip->locateName($name) === false) {
                    throw new RuntimeException('Missing entry: '.$name);
                }
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    /**
     * Restores database and files from a verified archive (the caller puts the app in maintenance).
     *
     * @return array{database: string, files: int, safety_copy: string|null}
     */
    public function restore(string $path): array
    {
        $manifest = $this->verify($path);
        $driver = DB::connection()->getDriverName();

        if ($manifest['driver'] !== $driver) {
            throw new RuntimeException("The archive is a {$manifest['driver']} backup; this installation uses {$driver}.");
        }

        $work = storage_path('framework/restore-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($work, 0700);
        $zip = $this->open($path);
        $safety = null;
        $files = 0;

        try {
            $dump = $work.DIRECTORY_SEPARATOR.basename($manifest['database']);
            file_put_contents($dump, (string) $zip->getFromName($manifest['database']));
            $safety = $this->restoreDatabase($driver, $dump);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (! str_starts_with($name, 'storage/') || str_ends_with($name, '/')) {
                    continue;
                }

                $destination = storage_path('app/'.substr($name, strlen('storage/')));
                File::ensureDirectoryExists(dirname($destination));
                file_put_contents($destination, (string) $zip->getFromIndex($i));
                $files++;
            }
        } finally {
            $zip->close();
            File::deleteDirectory($work);
        }

        return ['database' => $manifest['database'], 'files' => $files, 'safety_copy' => $safety];
    }

    protected function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open the archive '.$path);
        }

        return $zip;
    }

    /** Entry names allowed in an archive: the dump, or a relative path under storage/ without "..". */
    protected function isSafeEntry(string $name): bool
    {
        if (in_array($name, ['database.sqlite', 'database.sql'], true)) {
            return true;
        }

        $root = (string) strtok(substr($name, strlen('storage/')), '/');

        return str_starts_with($name, 'storage/')
            && in_array($root, (array) config('operations.backups.storage_roots'), true)
            && ! str_contains($name, '\\')
            && ! preg_match('#(^|/)\.\.?(/|$)#', $name)
            && ! preg_match('/[[:cntrl:]]/', $name);
    }

    /**
     * @return array<string, string> relative path (from storage/app, forward slashes) => absolute path
     */
    protected function storageFiles(): array
    {
        $files = [];
        $base = storage_path('app');
        $exclude = (array) config('operations.backups.exclude');

        foreach ((array) config('operations.backups.storage_roots') as $root) {
            if (! is_dir($base.DIRECTORY_SEPARATOR.$root)) {
                continue;
            }

            foreach (File::allFiles($base.DIRECTORY_SEPARATOR.$root, true) as $file) {
                $relative = $root.'/'.str_replace('\\', '/', $file->getRelativePathname());

                if (collect($exclude)->contains(fn (string $prefix): bool => str_starts_with($relative, $prefix.'/')) || str_ends_with($relative, '.gitignore')) {
                    continue;
                }

                $files[$relative] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    protected function dumpDatabase(string $work): string
    {
        $connection = DB::connection();
        $config = $connection->getConfig();

        switch ($connection->getDriverName()) {
            case 'sqlite':
                $target = $work.DIRECTORY_SEPARATOR.'database.sqlite';
                $connection->statement('VACUUM INTO ?', [$target]);

                return $target;

            case 'mysql':
            case 'mariadb':
                $target = $work.DIRECTORY_SEPARATOR.'database.sql';
                $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(3600)->run([
                    (string) config('operations.backups.mysqldump'), '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4',
                    '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--result-file='.$target, (string) $config['database'],
                ]);
                $this->assertProcess($result, 'mysqldump');

                return $target;

            case 'pgsql':
                $target = $work.DIRECTORY_SEPARATOR.'database.sql';
                $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->timeout(3600)->run([
                    (string) config('operations.backups.pg_dump'), '--clean', '--if-exists', '--no-owner', '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--file='.$target, (string) $config['database'],
                ]);
                $this->assertProcess($result, 'pg_dump');

                return $target;

            default:
                throw new RuntimeException('Backups are not supported for the '.$connection->getDriverName().' driver.');
        }
    }

    /**
     * @return string|null safety copy of the replaced SQLite file
     */
    protected function restoreDatabase(string $driver, string $dump): ?string
    {
        $config = DB::connection()->getConfig();

        if ($driver === 'sqlite') {
            $database = (string) $config['database'];
            $safety = $database.'.before-restore-'.now()->format('Ymd-His');
            DB::disconnect();
            File::copy($database, $safety);
            File::copy($dump, $database);
            DB::reconnect();

            return $safety;
        }

        $result = $driver === 'pgsql'
            ? Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->timeout(3600)->run([(string) config('operations.backups.psql'), '--quiet', '--set=ON_ERROR_STOP=1', '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'], '--dbname='.$config['database'], '--file='.$dump])
            : Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(3600)->input(fopen($dump, 'rb'))->run([(string) config('operations.backups.mysql'), '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], (string) $config['database']]);
        $this->assertProcess($result, $driver === 'pgsql' ? 'psql' : 'mysql');

        return null;
    }

    protected function assertProcess(ProcessResult $result, string $tool): void
    {
        if (! $result->successful()) {
            throw new RuntimeException($tool.' failed: '.mb_substr(trim($result->errorOutput()), 0, 500));
        }
    }
}
