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

$configured = (string) config('database.connections.mysql.database');

if ($configured === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL') || config('database.connections.mysql.url') || $configured !== 'meetara_hrms_integrity_20261020') {
    fwrite(STDERR, "Refusing to confirm a payment outside the disposable integrity database.\n");
    exit(2);
}

$id = (int) ($argv[1] ?? 0);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$started = microtime(true);
$updated = DB::update('update payroll_payments set reference = ? where id = ? and status = ?', ['SECOND', $id, 'pending']);
fwrite(STDOUT, $updated."\n".number_format(microtime(true) - $started, 4, '.', ''));
fflush(STDOUT);
