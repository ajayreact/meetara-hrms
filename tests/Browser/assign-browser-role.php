<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$allowedDatabases = ['meetara_hrms_browser'];
$allowedEmails = ['accounts@browser-north.test'];
$allowedRoles = ['Accounts'];
$configuredDatabase = getenv('DB_DATABASE');
$email = (string) ($argv[1] ?? '');
$roleName = (string) ($argv[2] ?? '');

if ($configuredDatabase === 'meetara_hrms') {
    fwrite(STDERR, "Refusing to assign a role because the live database meetara_hrms is prohibited.\n");
    exit(1);
}

if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to assign a role because DB_URL or DATABASE_URL is set.\n");
    exit(1);
}

if (! in_array($configuredDatabase, $allowedDatabases, true) || ! in_array($email, $allowedEmails, true) || ! in_array($roleName, $allowedRoles, true)) {
    fwrite(STDERR, "Refusing to assign a role because the database, user, or role is not allowed.\n");
    exit(1);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();

if ($database !== 'meetara_hrms_browser') {
    fwrite(STDERR, "Refusing to assign a role because the application is not connected to meetara_hrms_browser.\n");
    exit(1);
}

$user = DB::table('users')->where('email', $email)->first(['id', 'tenant_id']);

if ($user === null || $user->tenant_id === null) {
    fwrite(STDERR, "Refusing to assign a role because the disposable user was not found.\n");
    exit(1);
}

$now = now();
$roleId = DB::table('roles')->where('tenant_id', $user->tenant_id)->where('name', $roleName)->where('guard_name', 'web')->value('id');

if ($roleId === null) {
    $roleId = DB::table('roles')->insertGetId([
        'tenant_id' => $user->tenant_id,
        'name' => $roleName,
        'guard_name' => 'web',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

DB::table('model_has_roles')
    ->where('model_type', User::class)
    ->where('model_id', $user->id)
    ->where('tenant_id', $user->tenant_id)
    ->delete();

DB::table('model_has_roles')->insert([
    'role_id' => $roleId,
    'model_type' => User::class,
    'model_id' => $user->id,
    'tenant_id' => $user->tenant_id,
]);

echo 'assigned='.$roleName.PHP_EOL;
