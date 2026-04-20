<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expire pending orders every minute — cancels on 5sim and refunds wallets
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();

// Poll 5sim for OTP codes and status changes on pending orders every 30 seconds
Schedule::command('orders:sync-provider')->everyThirtySeconds()->withoutOverlapping();
