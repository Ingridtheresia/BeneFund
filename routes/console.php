<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('midtrans:sync --limit=50')->everyFiveMinutes()->withoutOverlapping()
    ->when(fn () => filled(config('midtrans.server_key')) && filled(config('midtrans.client_key')));
Schedule::command('midtrans:sync --all --limit=100')->hourly()->withoutOverlapping()
    ->when(fn () => filled(config('midtrans.server_key')) && filled(config('midtrans.client_key')));
