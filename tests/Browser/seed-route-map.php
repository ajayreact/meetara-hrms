<?php

/**
 * Adds Route Map fixtures after prepare.php. Runs only against the Route Map browser database.
 * GPS capture is left disabled; the browser test enables it through the settings page.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const ROUTEMAP_BROWSER_DATABASE = 'meetara_hrms_routemap_browser_20261022';

if (getenv('DB_DATABASE') !== ROUTEMAP_BROWSER_DATABASE || getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to seed because DB_DATABASE is not the Route Map browser database.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.mysql.database') !== ROUTEMAP_BROWSER_DATABASE || (DB::selectOne('select database() as name')->name ?? null) !== ROUTEMAP_BROWSER_DATABASE) {
    fwrite(STDERR, "Refusing to seed because the connected database is not the Route Map browser database.\n");
    exit(1);
}

$now = now();
$north = (int) DB::table('tenants')->where('slug', 'browser-north')->value('id');
$south = (int) DB::table('tenants')->where('slug', 'browser-south')->value('id');
$northMain = (int) DB::table('locations')->where('tenant_id', $north)->value('id');
$southMain = (int) DB::table('locations')->where('tenant_id', $south)->value('id');

DB::table('locations')->where('id', $northMain)->update([
    'address' => '12 Park Street, Kolkata',
    'timezone' => 'Asia/Kolkata',
    'latitude' => 22.572645,
    'longitude' => 88.363895,
    'map_label' => 'North HQ',
    'verification_radius_meters' => 150,
    'gps_verification_enabled' => false,
]);
DB::table('locations')->where('id', $southMain)->update([
    'address' => 'South Company Road',
    'latitude' => 12.971599,
    'longitude' => 77.594566,
    'map_label' => 'South Campus',
]);
$annex = DB::table('locations')->insertGetId([
    'tenant_id' => $north,
    'name' => 'North Annex',
    'address' => '40 Lake Road, Kolkata',
    'timezone' => 'Asia/Kolkata',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$remote = DB::table('locations')->insertGetId([
    'tenant_id' => $north,
    'name' => 'North Remote Desk',
    'address' => '7 Salt Lake, Kolkata',
    'timezone' => 'Asia/Kolkata',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);

DB::table('location_networks')->insert([
    ['tenant_id' => $north, 'location_id' => $northMain, 'cidr' => '203.0.113.0/24', 'created_at' => $now, 'updated_at' => $now],
    ['tenant_id' => $north, 'location_id' => $remote, 'cidr' => '198.51.100.0/24', 'created_at' => $now, 'updated_at' => $now],
]);

$accountsUser = (int) DB::table('users')->where('email', 'accounts@browser-north.test')->value('id');
$accountsRole = DB::table('roles')->where('tenant_id', $north)->where('name', 'Accounts')->value('id')
    ?? DB::table('roles')->insertGetId(['tenant_id' => $north, 'name' => 'Accounts', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
DB::table('model_has_roles')->where('model_id', $accountsUser)->where('tenant_id', $north)->delete();
DB::table('model_has_roles')->insert(['role_id' => $accountsRole, 'model_type' => App\Models\User::class, 'model_id' => $accountsUser, 'tenant_id' => $north]);

$report = (int) DB::table('employees')->where('employee_code', 'BRN-REP')->value('id');
$outside = (int) DB::table('employees')->where('employee_code', 'BRN-OUT')->value('id');
DB::table('employees')->where('id', $outside)->update(['location_id' => $remote]);

$point = function (int $employeeId, string $date, float $lat, float $lng, int $accuracy) use ($north, $northMain): void {
    $logId = DB::table('attendance_logs')->insertGetId([
        'tenant_id' => $north,
        'employee_id' => $employeeId,
        'location_id' => $northMain,
        'work_date' => $date,
        'check_in' => $date.' 09:05:00',
        'check_out' => $date.' 18:00:00',
        'status' => 'present',
        'created_at' => $date.' 09:05:00',
        'updated_at' => $date.' 18:00:00',
    ]);
    DB::table('attendance_location_records')->insert([
        'tenant_id' => $north,
        'employee_id' => $employeeId,
        'attendance_log_id' => $logId,
        'event_type' => 'clock_in',
        'latitude' => $lat,
        'longitude' => $lng,
        'accuracy_meters' => $accuracy,
        'status' => 'recorded',
        'captured_at' => $date.' 03:35:00',
    ]);
};

$point($report, '2026-10-05', 22.571111, 88.361111, 21);
$point($report, '2026-10-15', 22.573333, 88.365555, 33);
$point($outside, '2026-10-06', 22.580001, 88.370001, 44);

echo 'annex='.$annex.PHP_EOL;
echo 'gps_settings_rows='.DB::table('company_attendance_settings')->count().PHP_EOL;
echo 'location_records='.DB::table('attendance_location_records')->count().PHP_EOL;
