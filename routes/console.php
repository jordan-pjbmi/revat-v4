<?php

use App\Jobs\AggregateDailyUsage;
use App\Jobs\CheckExpiringSubscriptions;
use App\Jobs\Extraction\ExtractIntegration;
use App\Jobs\Summarization\SummarizeAllWorkspaces;
use App\Jobs\TransformExtractionBatches;
use App\Models\Integration;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(AggregateDailyUsage::class)
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::job(CheckExpiringSubscriptions::class)
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::call(function () {
    Integration::query()
        ->dueForSync()
        ->each(fn ($integration) => ExtractIntegration::dispatch($integration));
})->everyFiveMinutes()->name('extract-due-integrations')->withoutOverlapping();

Schedule::job(TransformExtractionBatches::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::job(SummarizeAllWorkspaces::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('extraction:prune')
    ->hourly()
    ->withoutOverlapping()
    ->name('prune-extraction-records');

Schedule::command('dashboard:prune-snapshots')
    ->daily()
    ->withoutOverlapping()
    ->name('prune-dashboard-snapshots');
