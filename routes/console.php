<?php

use App\Console\Commands\CheckPaymentDeadlines;
use App\Jobs\StartNextRentCycleJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for expired 24-hour payment deadlines every 15 minutes
Schedule::command('app:check-payment-deadlines')
    ->everyFifteenMinutes()
    ->name('check-payment-deadlines')
    ->withoutOverlapping();

// Roll over completed rent cycles and start the next monthly cycle daily
Schedule::job(new StartNextRentCycleJob)
    ->daily()
    ->name('start-next-rent-cycle')
    ->withoutOverlapping();
