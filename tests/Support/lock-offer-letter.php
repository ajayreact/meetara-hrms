<?php

if (($argv[1] ?? '') === '' || getenv('DB_DATABASE') === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL') || getenv('DB_DATABASE') !== 'meetara_hrms_offer_20261020') {
    fwrite(STDERR, "refused\n");
    exit(1);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$letterId = (int) $argv[1];
echo "ready\n";
$started = microtime(true);
Illuminate\Support\Facades\DB::transaction(function () use ($letterId): void {
    Illuminate\Support\Facades\DB::table('offer_letters')->where('id', $letterId)->lockForUpdate()->first();
});
echo (microtime(true) - $started)."\n";
