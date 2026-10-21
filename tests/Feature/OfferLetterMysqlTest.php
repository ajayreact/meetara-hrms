<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class OfferLetterMysqlTest extends TestCase
{
    private const DATABASE = 'meetara_hrms_offer_20261020';

    public function test_offer_letter_storage_is_innodb_and_row_locks_wait(): void
    {
        if (TestDatabaseGuard::integrationDecision(self::DATABASE) === 'skip') {
            $this->markTestSkipped('Offer-letter MySQL checks run only on '.self::DATABASE.'.');
        }

        $engine = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            ['offer_letters']
        );
        $this->assertSame('InnoDB', (string) ($engine->engine ?? $engine->ENGINE ?? ''));
        $this->assertTrue((bool) DB::selectOne("show columns from onboardings like 'welcomed_at'"));
        $this->assertTrue((bool) DB::selectOne("show columns from employee_documents like 'category'"));

        $companyId = DB::table('tenants')->insertGetId([
            'name' => 'Offer Lock Company',
            'slug' => 'offer-lock-'.uniqid(),
            'timezone' => 'Asia/Kolkata',
            'week_off' => json_encode(['sunday']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $letterId = DB::table('offer_letters')->insertGetId([
            'tenant_id' => $companyId,
            'position_code' => 'opt_recruiter',
            'employee_name' => 'Lock Person',
            'monthly_salary' => '45000.00',
            'annual_ctc' => '540000.00',
            'joining_date' => '2026-04-01',
            'notice_months' => 3,
            'hr_name' => 'HR',
            'hr_signature' => 'HR',
            'status' => 'issued',
            'version' => 1,
            'issued_body' => 'locked copy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->query('SELECT id FROM offer_letters WHERE id='.(int) $letterId.' FOR UPDATE');
        $environment = getenv() ?: [];
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_DATABASE'] = self::DATABASE;
        $child = proc_open(
            [PHP_BINARY, base_path('tests/Support/lock-offer-letter.php'), (string) $letterId],
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
            $holder->rollBack();
            $waited = (float) trim((string) fgets($pipes[1]));
            $this->assertGreaterThan(0.5, $waited);
            $this->assertSame('issued', DB::table('offer_letters')->where('id', $letterId)->value('status'));
            $this->assertSame('locked copy', DB::table('offer_letters')->where('id', $letterId)->value('issued_body'));
        } finally {
            if ($holder->inTransaction()) {
                $holder->rollBack();
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($child);
            DB::table('offer_letters')->where('id', $letterId)->delete();
            DB::table('tenants')->where('id', $companyId)->delete();
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

        return $pdo;
    }
}
