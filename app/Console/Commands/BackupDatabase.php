<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * A scheduled mysqldump backup (CLAUDE.md §12: "a backup strategy for the
 * database MUST be considered part of 'done' for the finance module, even
 * if V1's implementation is simple"). Shared-hosting friendly: no
 * dependency beyond the `mysqldump` binary the host already provides for
 * a MySQL database, no permanently-running process — just a command the
 * Scheduler fires once a day (see routes/console.php).
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup-database {--keep=14 : How many days of backups to retain}';

    protected $description = 'Dump the database to storage/app/backups and prune old backups';

    public function handle(): int
    {
        $connectionName = config('database.default');
        $config = config("database.connections.{$connectionName}");

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->error("app:backup-database only supports the 'mysql' driver (connection '{$connectionName}' is '".($config['driver'] ?? 'unknown')."').");

            return self::FAILURE;
        }

        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/backup-'.now()->format('Y-m-d_His').'.sql.gz';

        // Password passed via MYSQL_PWD rather than --password=... so it
        // never appears in the command line itself (visible to `ps aux`
        // for the duration of the dump on a shared host).
        $command = sprintf(
            'mysqldump --user=%s --host=%s --port=%s --single-transaction --quick %s | gzip > %s',
            escapeshellarg($config['username']),
            escapeshellarg($config['host']),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg($config['database']),
            escapeshellarg($path),
        );

        $result = Process::env(['MYSQL_PWD' => $config['password']])->timeout(300)->run($command);

        if (! $result->successful()) {
            File::delete($path);
            $this->error('mysqldump failed: '.$result->errorOutput());

            return self::FAILURE;
        }

        $this->info("Backup written to {$path}");

        $this->pruneOldBackups($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $directory, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays);

        foreach (File::files($directory) as $file) {
            if (now()->createFromTimestamp($file->getMTime())->lt($cutoff)) {
                File::delete($file->getPathname());
            }
        }
    }
}
