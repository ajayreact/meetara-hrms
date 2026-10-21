<?php

$allowedDatabases = ['meetara_hrms_stage36_browser_20261020', 'meetara_hrms_stage37_browser_20261020'];
$configuredDatabase = getenv('DB_DATABASE');

if ($configuredDatabase === 'meetara_hrms') {
    fwrite(STDERR, "Refusing to seed historical tasks because the live database meetara_hrms is prohibited.\n");
    exit(1);
}

if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to seed historical tasks because DB_URL or DATABASE_URL is set.\n");
    exit(1);
}

if (! in_array($configuredDatabase, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to seed historical tasks because DB_DATABASE is not the Stage 36 browser database.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\EmployeeOnboarding;
use Illuminate\Support\Facades\DB;

$database = DB::connection()->getDatabaseName();

if ($database === 'meetara_hrms' || ! in_array($database, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to seed historical tasks because the application is not connected to the Stage 36 browser database.\n");
    exit(1);
}

if (! Illuminate\Support\Facades\Schema::hasColumn('employee_record_events', 'note')) {
    fwrite(STDERR, "Refusing to seed historical tasks because the note column is missing.\n");
    exit(1);
}

$companyId = (int) DB::table('tenants')->where('slug', 'browser-north')->value('id');
$employeeId = (int) DB::table('employees')->where('tenant_id', $companyId)->where('employee_code', 'BRN-REP')->value('id');
$hrId = (int) DB::table('users')->where('email', 'hr@browser-north.test')->value('id');

if ($companyId === 0 || $employeeId === 0 || $hrId === 0) {
    fwrite(STDERR, "Refusing to seed historical tasks because the synthetic browser employee is missing.\n");
    exit(1);
}

$onboarding = app(EmployeeOnboarding::class);
$onboarding->open(User::query()->findOrFail($hrId), Employee::query()->findOrFail($employeeId));
$onboardingId = (int) DB::table('onboardings')->where('employee_id', $employeeId)->value('id');
$titles = [
    'Share personal documents',
    'Introduce the team',
    'Issue laptop and access',
    'Walk through company policies',
];

DB::table('onboarding_tasks')
    ->where('onboarding_id', $onboardingId)
    ->whereNull('code')
    ->whereIn('title', $titles)
    ->delete();
DB::table('employee_record_events')
    ->where('employee_id', $employeeId)
    ->where('action', 'historical_decision')
    ->delete();

$taskIds = [];

foreach ($titles as $index => $title) {
    $taskIds[$title] = (int) DB::table('onboarding_tasks')->insertGetId([
        'tenant_id' => $companyId,
        'onboarding_id' => $onboardingId,
        'title' => $title,
        'description' => 'Synthetic historical description '.($index + 1).'.',
        'assignee_role' => 'hr',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$panId = (int) DB::table('onboarding_tasks')->where('onboarding_id', $onboardingId)->where('code', 'pan')->value('id');
file_put_contents(__DIR__.'/../../storage/framework/stage36-historical.json', json_encode([
    'database' => $database,
    'employeeId' => $employeeId,
    'panTaskId' => $panId,
    'tasks' => $taskIds,
], JSON_THROW_ON_ERROR));

echo 'database='.$database.PHP_EOL;
echo 'historical='.count($taskIds).PHP_EOL;
echo 'pan='.$panId.PHP_EOL;
