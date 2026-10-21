<?php

if (getenv('DB_DATABASE') === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL')) {
    fwrite(STDERR, "Refusing to accept an invitation because the live database is prohibited or a database URL is set.\n");
    exit(2);
}

use App\Services\Hrms\CompanyAdminInvitation;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$configured = (string) config('database.connections.mysql.database');

if ($configured === 'meetara_hrms' || getenv('DB_URL') || getenv('DATABASE_URL') || config('database.connections.mysql.url') || $configured !== 'meetara_hrms_invite_20261020') {
    fwrite(STDERR, "Refusing to accept an invitation outside the disposable invitation database.\n");
    exit(2);
}

$token = (string) ($argv[1] ?? '');
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$started = microtime(true);

try {
    app(CompanyAdminInvitation::class)->accept($token, 'Locked Admin', 'Password1');
    $result = 'accepted';
} catch (Throwable $exception) {
    $result = 'rejected';
}

fwrite(STDOUT, $result."\n".number_format(microtime(true) - $started, 4, '.', '')."\n");
fflush(STDOUT);
