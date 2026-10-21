<?php

if (getenv('DB_DATABASE') === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to confirm a payment because the live database is prohibited or a database URL is set.\n");
    exit(2);
}

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expected = 'meetara_hrms_stage28_20261020';
$configured = (string) config('database.connections.mysql.database');

if ($configured === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL') || config('database.connections.mysql.url') || $configured !== $expected) {
    fwrite(STDERR, "Refusing to confirm a payment outside meetara_hrms_stage28_20261020.\n");
    exit(2);
}

$actual = (string) DB::select('select database() as db')[0]->db;

if ($actual !== $expected) {
    fwrite(STDERR, "Refusing to confirm a payment outside meetara_hrms_stage28_20261020.\n");
    exit(2);
}

$id = (int) ($argv[1] ?? 0);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$started = microtime(true);
$updated = DB::update('update payroll_payments set reference = ? where id = ? and status = ?', ['SECOND', $id, 'pending']);
fwrite(STDOUT, $updated."\n".number_format(microtime(true) - $started, 4, '.', ''));
fflush(STDOUT);
