<?php

namespace App\Services;

use App\Models\BackupLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * SRS 30 - Database backups.
 *
 * Shells out to mysqldump rather than dumping through PHP: a 10,000-member
 * database with years of attendance is far past what a PHP process should be
 * asked to hold, and mysqldump already handles the escaping correctly.
 */
class BackupService
{
    /** Long enough for a large database, short enough to notice a hang. */
    private const TIMEOUT_SECONDS = 1800;

    public function run(string $type = 'MANUAL'): BackupLog
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        $directory = $this->directory();
        $filename = sprintf('gms-%s-%s.sql', strtolower($type), now()->format('Y-m-d_His'));
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $log = BackupLog::create([
            'type' => $type,
            'filename' => $filename,
            'path' => $path,
            'status' => 'RUNNING',
            'started_at' => now(),
            'triggered_by' => Auth::id(),
        ]);

        $process = new Process([
            config('gms.backup.mysqldump_path', 'mysqldump'),
            '--host='.$db['host'],
            '--port='.$db['port'],
            // Explicit, because the client otherwise negotiates between a
            // socket, a named pipe and TCP based on the host name and its own
            // defaults. Run from a web process rather than a console that
            // negotiation failed outright ("Can't create TCP/IP socket"),
            // which meant the backup failed and so did everything gated on it.
            '--protocol=TCP',
            '--user='.$db['username'],
            // Passed via argument rather than the environment because
            // MYSQL_PWD is visible to other processes on the machine. The
            // command line is visible too, so on a shared host prefer a
            // credentials file; documented in docs/DEPLOYMENT.md.
            '--password='.$db['password'],
            '--single-transaction',
            // Without this, mysqldump locks the whole database for the
            // duration and reception cannot check anyone in while it runs.
            '--quick',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            $db['database'],
        ]);

        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->setEnv($this->childEnvironment());

        try {
            $handle = fopen($path, 'w');

            if ($handle === false) {
                throw new \RuntimeException("Cannot write to {$path}.");
            }

            $process->run(function ($outputType, $buffer) use ($handle) {
                if ($outputType === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });

            fclose($handle);

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'mysqldump failed.');
            }

            $size = filesize($path) ?: 0;

            // An empty or near-empty dump means mysqldump "succeeded" without
            // writing anything - a silent backup failure is the worst kind.
            if ($size < 1024) {
                throw new \RuntimeException('The dump file is suspiciously small; treating the backup as failed.');
            }

            $log->forceFill([
                'size_bytes' => $size,
                'status' => 'SUCCESS',
                'message' => 'Backup completed.',
                'finished_at' => now(),
            ])->save();

            $this->prune();
        } catch (ProcessTimedOutException) {
            $this->fail($log, $path, 'mysqldump timed out after '.self::TIMEOUT_SECONDS.' seconds.');
        } catch (\Throwable $e) {
            $this->fail($log, $path, $e->getMessage());
        }

        return $log->refresh();
    }

    /** SRS 30 - Configurable retention. */
    public function prune(): int
    {
        $days = (int) config('gms.backup.retention_days', 30);

        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $removed = 0;

        BackupLog::query()
            ->where('started_at', '<', $cutoff)
            // Weekly copies are the ones kept for a real restore point; the
            // daily churn is what retention is meant to clear.
            ->where('type', '!=', 'WEEKLY')
            ->each(function (BackupLog $log) use (&$removed) {
                if ($log->path && file_exists($log->path)) {
                    @unlink($log->path);
                    $removed++;
                }

                $log->delete();
            });

        return $removed;
    }

    private function fail(BackupLog $log, string $path, string $message): void
    {
        // A truncated dump is worse than none: it looks like a backup.
        if (file_exists($path)) {
            @unlink($path);
        }

        $log->forceFill([
            'status' => 'FAILED',
            'message' => substr($message, 0, 1000),
            'finished_at' => now(),
        ])->save();

        Log::error('Database backup failed.', ['message' => $message]);
    }

    /**
     * The environment mysqldump needs in order to open a socket at all.
     *
     * On Windows, Winsock loads its provider DLLs by way of %SystemRoot%. A
     * child process that inherits an environment without it cannot initialise
     * Winsock and fails with "Got error: 2004: Can't create TCP/IP socket
     * (10106)" before it ever reaches the database.
     *
     * That is not hypothetical: `php artisan serve` filters the environment it
     * hands the PHP server down to an allowlist (Laravel's
     * ServeCommand::$passthroughVariables), and SystemRoot is not on it. So the
     * same backup succeeded from the console and failed from the browser. Some
     * FastCGI and scheduled-task setups scrub the environment the same way.
     *
     * Symfony merges these over the inherited environment rather than replacing
     * it, so this only fills gaps.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $environment = [];

        // SystemRoot is the one Winsock actually needs; the rest are here
        // because mysqldump writes temporary files and resolves its own DLLs.
        foreach (['SystemRoot', 'SystemDrive', 'windir', 'TEMP', 'TMP', 'PATH'] as $key) {
            $value = getenv($key) ?: ($_SERVER[$key] ?? null);

            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            }
        }

        // Last resort. If the environment has been scrubbed this thoroughly
        // there is nothing left to read the real value from, and a wrong guess
        // is no worse than the certain failure of leaving it unset.
        if (! isset($environment['SystemRoot'])) {
            $environment['SystemRoot'] = $environment['windir'] ?? 'C:\\Windows';
        }

        return $environment;
    }

    private function directory(): string
    {
        $configured = (string) config('gms.backup.path', 'backups');

        $directory = str_contains($configured, ':') || str_starts_with($configured, '/')
            ? $configured
            : storage_path('app'.DIRECTORY_SEPARATOR.$configured);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }
}
