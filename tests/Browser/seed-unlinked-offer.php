<?php

$allowedDatabases = ['meetara_hrms_browser', 'meetara_hrms_stage36_browser_20261020', 'meetara_hrms_stage37_browser_20261020'];
$configuredDatabase = getenv('DB_DATABASE');

if ($configuredDatabase === 'meetara_hrms') {
    fwrite(STDERR, "Refusing to seed because the live database meetara_hrms is prohibited.\n");
    exit(1);
}

if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to seed because DB_URL or DATABASE_URL is set.\n");
    exit(1);
}

if (! in_array($configuredDatabase, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to seed because DB_DATABASE is not an allowed disposable database.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($database === 'meetara_hrms' || ! in_array($database, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to seed because the application is not connected to an allowed disposable database.\n");
    exit(1);
}

if (! Illuminate\Support\Facades\Schema::hasTable('offer_letters')) {
    fwrite(STDERR, "Refusing to seed because offer_letters is missing.\n");
    exit(1);
}

$companyId = Illuminate\Support\Facades\DB::table('tenants')->where('slug', 'browser-north')->value('id');
$actorId = Illuminate\Support\Facades\DB::table('users')->where('email', 'hr@browser-north.test')->value('id');

if ($companyId === null || $actorId === null) {
    fwrite(STDERR, "Refusing to seed because the browser company is not prepared.\n");
    exit(1);
}

$exists = Illuminate\Support\Facades\DB::table('offer_letters')
    ->where('tenant_id', $companyId)
    ->whereNull('employee_id')
    ->where('employee_name', 'Unlinked Candidate')
    ->exists();

if (! $exists) {
    Illuminate\Support\Facades\DB::table('offer_letters')->insert([
        'tenant_id' => $companyId,
        'employee_id' => null,
        'position_code' => 'opt_recruiter',
        'employee_name' => 'Unlinked Candidate',
        'monthly_salary' => '45000.00',
        'annual_ctc' => '540000.00',
        'joining_date' => '2026-04-01',
        'notice_months' => 3,
        'hr_name' => 'North HR',
        'hr_signature' => 'North HR',
        'status' => 'draft',
        'version' => 1,
        'created_by' => $actorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

echo 'database='.$database.PHP_EOL;
