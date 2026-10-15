<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Exit people whose scheduled last working day has arrived.
Schedule::command('members:process-exits')->dailyAt('00:10');

// Daily reminders: tasks due tomorrow and overdue tasks.
Schedule::command('tasks:send-reminders')->dailyAt('08:00');

// Failed queue jobs are kept for a month so they can be inspected and retried (System Admin > Failed jobs).
Schedule::command('queue:prune-failed --hours=720')->daily();
