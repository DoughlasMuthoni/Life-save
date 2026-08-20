<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CLAUDE.md §12: a backup strategy is part of "done" for the finance
// module. Shared hosting needs exactly one cron entry —
// `* * * * * php artisan schedule:run` — for this (and any future
// scheduled work, e.g. a queue:work --stop-when-empty pass) to fire.
Schedule::command('app:backup-database')->daily()->at('02:00');
