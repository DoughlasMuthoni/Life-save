<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    public function test_it_runs_mysqldump_with_the_configured_credentials_and_reports_success(): void
    {
        Process::fake();

        $this->artisan('app:backup-database')->assertExitCode(0);

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'mysqldump')
                && str_contains($process->command, '--single-transaction')
                && str_contains($process->command, escapeshellarg(config('database.connections.mysql.database')));
        });
    }

    public function test_it_fails_gracefully_for_a_non_mysql_connection(): void
    {
        config(['database.default' => 'sqlite_unsupported']);
        config(['database.connections.sqlite_unsupported' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->artisan('app:backup-database')->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/backups'));

        parent::tearDown();
    }
}
