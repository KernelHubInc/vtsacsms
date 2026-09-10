<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('charging:expire-operations')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:publish-outbox --limit=250')->everySecond()->withoutOverlapping();
Schedule::command('maintenance:run-automation')->everyFiveMinutes()->withoutOverlapping();
