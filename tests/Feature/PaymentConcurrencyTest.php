<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    public function test_overlapping_confirmations_record_one_payment(): void
    {
        if (TestDatabaseGuard::integrationDecision('meetara_hrms_integrity_20261020') === 'skip') {
            $this->markTestSkipped('Overlapping payment locks are run on the disposable MySQL database meetara_hrms_integrity_20261020.');
        }

        $engine = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            ['payroll_payments']
        );
        $this->assertSame('InnoDB', (string) ($engine->engine ?? $engine->ENGINE ?? ''));

        $tenantId = (int) DB::table('tenants')->value('id');
        $employeeId = (int) DB::table('employees')->where('tenant_id', $tenantId)->value('id');
        $userId = (int) DB::table('employees')->where('id', $employeeId)->value('user_id');
        $runId = DB::table('payroll_runs')->insertGetId([
            'tenant_id' => $tenantId,
            'year' => 1999,
            'month' => 1,
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('payroll_items')->insertGetId([
            'tenant_id' => $tenantId,
            'payroll_run_id' => $runId,
            'employee_id' => $employeeId,
            'net' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payroll_payments')->insertGetId([
            'tenant_id' => $tenantId,
            'payroll_item_id' => $itemId,
            'amount' => 1,
            'status' => 'pending',
            'recorded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->query('SELECT id FROM payroll_payments WHERE id='.(int) $paymentId.' FOR UPDATE');

        $environment = getenv();
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_DATABASE'] = $database;
        $child = proc_open(
            [PHP_BINARY, base_path('tests/Support/confirm-payment-once.php'), (string) $paymentId],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment
        );
        $this->assertIsResource($child);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("ready\n", fgets($pipes[1]));
        sleep(1);
        try {
            $holder->prepare('UPDATE payroll_payments SET status="paid", reference="LOCKED" WHERE id=? AND status="pending"')->execute([$paymentId]);
            $holder->commit();
            $updated = trim((string) fgets($pipes[1]));
            $waited = (float) trim((string) fgets($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($child);
            $reference = DB::table('payroll_payments')->where('id', $paymentId)->value('reference');
            $this->assertSame(0, $exit, $error);
            $this->assertSame('0', $updated);
            $this->assertSame('LOCKED', $reference);
            $this->assertGreaterThan(0.8, $waited);
        } finally {
            if ($holder->inTransaction()) {
                $holder->rollBack();
            }
            DB::table('payroll_payments')->where('id', $paymentId)->delete();
            DB::table('payroll_items')->where('id', $itemId)->delete();
            DB::table('payroll_runs')->where('id', $runId)->delete();
        }
    }

    private function pdo(): PDO
    {
        $connection = config('database.connections.mysql');
        $pdo = new PDO(
            'mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset=utf8mb4',
            (string) $connection['username'],
            (string) $connection['password']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET innodb_lock_wait_timeout=5');

        return $pdo;
    }
}
