<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$allowedDatabases = ['meetara_hrms_browser'];
$allowedEmails = ['owner@browser-north.test', 'accounts@browser-north.test'];
$configuredDatabase = getenv('DB_DATABASE');
$runId = (int) ($argv[1] ?? 0);
$email = (string) ($argv[2] ?? '');

if ($configuredDatabase === 'meetara_hrms') {
    fwrite(STDERR, "Refusing to update payroll because the live database meetara_hrms is prohibited.\n");
    exit(1);
}

if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to update payroll because DB_URL or DATABASE_URL is set.\n");
    exit(1);
}

if (! in_array($configuredDatabase, $allowedDatabases, true) || $runId < 1 || ! in_array($email, $allowedEmails, true)) {
    fwrite(STDERR, "Refusing to update payroll because the database, run, or user is not allowed.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();

if ($database !== 'meetara_hrms_browser') {
    fwrite(STDERR, "Refusing to update payroll because the application is not connected to meetara_hrms_browser.\n");
    exit(1);
}

$user = DB::table('users')->where('email', $email)->first(['id', 'tenant_id']);
$run = DB::table('payroll_runs')->where('id', $runId)->first(['id', 'tenant_id']);

if ($user === null || $run === null || (int) $user->tenant_id !== (int) $run->tenant_id) {
    fwrite(STDERR, "Refusing to update payroll because the run and user are not in the same disposable company.\n");
    exit(1);
}

DB::table('payroll_runs')->where('id', $runId)->update([
    'calculated_by' => $user->id,
]);

echo "updated\n";
