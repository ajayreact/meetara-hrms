<?php

use App\Services\Hrms\GpsCapture;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hrms:prune-gps-locations {--dry-run : Count expired records without deleting them}', function (GpsCapture $gpsCapture) {
    try {
        $result = $gpsCapture->prune((bool) $this->option('dry-run'));
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $total = array_sum($result['deleted']);
    $verb = $this->option('dry-run') ? 'would delete' : 'deleted';
    $timezone = (string) config('app.timezone');
    $this->info("Retention {$result['retentionDays']} days. Cutoff {$result['cutoff']} ({$timezone}). {$verb} {$total} location records across ".count($result['deleted']).' companies.');

    return 0;
})->purpose('Delete precise attendance locations older than the configured retention period');

Schedule::command('hrms:prune-gps-locations')->daily()->withoutOverlapping();
