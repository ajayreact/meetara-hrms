<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$allowedDatabases = ['meetara_hrms_browser', 'meetara_hrms_stage28_20261020', 'meetara_hrms_stage36_browser_20261020', 'meetara_hrms_stage37_browser_20261020', 'meetara_hrms_stage38_browser_20261020', 'meetara_hrms_routemap_browser_20261022'];
$configuredDatabase = getenv('DB_DATABASE');

if ($configuredDatabase === 'meetara_hrms') {
    fwrite(STDERR, "Refusing to prepare because the live database meetara_hrms is prohibited.\n");
    exit(1);
}

if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to prepare because DB_URL or DATABASE_URL is set.\n");
    exit(1);
}

if (! in_array($configuredDatabase, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to prepare because DB_DATABASE is not an allowed disposable database.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.mysql.database');

if ($database === 'meetara_hrms' || config('database.connections.mysql.url')) {
    fwrite(STDERR, "Refusing to prepare because the live database is prohibited or a database URL is configured.\n");
    exit(1);
}

if (! in_array($database, $allowedDatabases, true)) {
    fwrite(STDERR, "Refusing to prepare because the application is not connected to an allowed disposable database.\n");
    exit(1);
}

$password = bin2hex(random_bytes(12));
$now = now();
$weekOff = json_encode(['saturday', 'sunday']);

DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach (DB::select('SHOW TABLES') as $table) {
    $name = array_values((array) $table)[0];
    if ($name !== 'migrations') {
        DB::table($name)->delete();
    }
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$north = company('Browser North', 'browser-north', $weekOff, $now);
$south = company('Browser South', 'browser-south', $weekOff, $now);

$accounts = [
    'platform' => user('Platform Admin', 'platform@browser.test', $password, null, true, $now),
    'owner' => user('North Owner', 'owner@browser-north.test', $password, $north, false, $now),
    'hr' => user('North HR', 'hr@browser-north.test', $password, $north, false, $now),
    'lead' => user('North Lead', 'lead@browser-north.test', $password, $north, false, $now),
    'report' => user('North Report', 'report@browser-north.test', $password, $north, false, $now),
    'outside' => user('North Outside', 'outside@browser-north.test', $password, $north, false, $now),
    'accounts' => user('North Accounts', 'accounts@browser-north.test', $password, $north, false, $now),
    'southHr' => user('South HR', 'hr@browser-south.test', $password, $south, false, $now),
    'southReport' => user('South Report', 'report@browser-south.test', $password, $south, false, $now),
];

role($north, $accounts['owner'], 'Owner', $now);
role($north, $accounts['hr'], 'HR', $now);
role($north, $accounts['lead'], 'Manager', $now);
role($north, $accounts['report'], 'Employee', $now);
role($north, $accounts['outside'], 'Employee', $now);
role($north, $accounts['accounts'], 'Employee', $now);
role($south, $accounts['southHr'], 'HR', $now);
role($south, $accounts['southReport'], 'Employee', $now);

$northLead = employee($north, $accounts['lead'], 'BRN-LEAD', 'lead@browser-north.test', null, $now);
$northReport = employee($north, $accounts['report'], 'BRN-REP', 'report@browser-north.test', $northLead, $now);
$northOutside = employee($north, $accounts['outside'], 'BRN-OUT', 'outside@browser-north.test', null, $now);
employee($north, $accounts['owner'], 'BRN-OWN', 'owner@browser-north.test', null, $now);
employee($north, $accounts['accounts'], 'BRN-ACC', 'accounts@browser-north.test', null, $now);
$southReport = employee($south, $accounts['southReport'], 'BRS-REP', 'report@browser-south.test', null, $now);

$recorded = leaveType($north, 'Recorded Casual', 'BRN-CL', $now);
leaveType($north, 'Unrecorded Leave', 'BRN-UL', $now);
balance($north, $northReport, $recorded, $now);
balance($north, $northLead, $recorded, $now);

file_put_contents(__DIR__.'/../../storage/framework/browser-credentials.json', json_encode([
    'database' => $database,
    'password' => $password,
    'emails' => array_map(fn (int $id): string => (string) DB::table('users')->where('id', $id)->value('email'), $accounts),
    'ids' => [
        'northReport' => $northReport,
        'northOutside' => $northOutside,
        'southReport' => $southReport,
    ],
], JSON_THROW_ON_ERROR));

echo 'database='.$database.PHP_EOL;
echo 'companies='.DB::table('tenants')->count().PHP_EOL;
echo 'employees='.DB::table('employees')->count().PHP_EOL;

function company(string $name, string $slug, string $weekOff, mixed $now): int
{
    return DB::table('tenants')->insertGetId([
        'name' => $name,
        'slug' => $slug,
        'timezone' => 'Asia/Kolkata',
        'week_off' => $weekOff,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function user(string $name, string $email, string $password, ?int $companyId, bool $platform, mixed $now): int
{
    return DB::table('users')->insertGetId([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'tenant_id' => $companyId,
        'is_platform_admin' => $platform,
        'invite_accepted_at' => $now,
        'email_verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function role(int $companyId, int $userId, string $name, mixed $now): void
{
    $roleId = DB::table('roles')->where('tenant_id', $companyId)->where('name', $name)->value('id');

    if ($roleId === null) {
        $roleId = DB::table('roles')->insertGetId([
            'tenant_id' => $companyId,
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => User::class,
        'model_id' => $userId,
        'tenant_id' => $companyId,
    ]);
}

function employee(int $companyId, int $userId, string $code, string $email, ?int $managerId, mixed $now): int
{
    $departmentId = DB::table('departments')->where('tenant_id', $companyId)->value('id')
        ?? DB::table('departments')->insertGetId([
            'tenant_id' => $companyId,
            'name' => 'Operations',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    $designationId = DB::table('designations')->where('tenant_id', $companyId)->value('id')
        ?? DB::table('designations')->insertGetId([
            'tenant_id' => $companyId,
            'name' => 'Associate',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    $officeId = DB::table('locations')->where('tenant_id', $companyId)->value('id')
        ?? DB::table('locations')->insertGetId([
            'tenant_id' => $companyId,
            'name' => 'Main office',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

    return DB::table('employees')->insertGetId([
        'tenant_id' => $companyId,
        'user_id' => $userId,
        'employee_code' => $code,
        'first_name' => $code,
        'last_name' => 'Person',
        'email' => $email,
        'department_id' => $departmentId,
        'designation_id' => $designationId,
        'location_id' => $officeId,
        'manager_id' => $managerId,
        'employment_type' => 'full_time',
        'work_mode' => 'office',
        'holiday_calendar' => 'india',
        'date_of_joining' => '2026-01-15',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function leaveType(int $companyId, string $name, string $code, mixed $now): int
{
    $typeId = DB::table('leave_types')->insertGetId([
        'tenant_id' => $companyId,
        'name' => $name,
        'code' => $code,
        'days_per_year' => 8,
        'is_paid' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('leave_policies')->insert([
        'tenant_id' => $companyId,
        'leave_type_id' => $typeId,
        'name' => $name.' yearly',
        'accrual' => 'yearly',
        'applicable_after_days' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $typeId;
}

function balance(int $companyId, int $employeeId, int $typeId, mixed $now): void
{
    DB::table('leave_balances')->insert([
        'tenant_id' => $companyId,
        'employee_id' => $employeeId,
        'leave_type_id' => $typeId,
        'year' => 2026,
        'allocated' => 8,
        'used' => 0,
        'pending' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
