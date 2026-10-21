<?php

namespace Tests\Feature;

use App\Services\Hrms\CompanyAdminInvitation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class CompanyAdminInvitationMysqlTest extends TestCase
{
    public function test_overlapping_acceptance_allows_one_account(): void
    {
        if (TestDatabaseGuard::integrationDecision('meetara_hrms_invite_20261020') === 'skip') {
            $this->markTestSkipped('Invitation row locks are run on the disposable MySQL database meetara_hrms_invite_20261020.');
        }

        $engine = DB::selectOne(
            'select engine from information_schema.tables where table_schema = database() and table_name = ?',
            ['company_admin_invitations']
        );
        $this->assertSame('InnoDB', (string) ($engine->engine ?? $engine->ENGINE ?? ''));

        $now = now();
        $companyId = DB::table('tenants')->insertGetId([
            'name' => 'Lock Company',
            'slug' => 'lock-company-'.uniqid(),
            'timezone' => 'Asia/Kolkata',
            'status' => 'active',
            'week_off' => json_encode(['sunday']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $actorId = DB::table('users')->insertGetId([
            'name' => 'Lock Platform',
            'email' => 'lock-platform-'.uniqid().'@example.test',
            'password' => 'hashed-placeholder',
            'is_platform_admin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = bin2hex(random_bytes(32));
        $invitationId = DB::table('company_admin_invitations')->insertGetId([
            'tenant_id' => $companyId,
            'email' => 'lock-admin-'.uniqid().'@example.test',
            'token_hash' => hash('sha256', $token),
            'invited_by' => $actorId,
            'role_name' => 'Owner',
            'expires_at' => now()->addDay(),
            'sent_at' => $now,
            'active_key' => $companyId.'|lock',
            'company_open_key' => (string) $companyId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $holder = $this->pdo();
        $holder->beginTransaction();
        $holder->query('SELECT id FROM company_admin_invitations WHERE id='.(int) $invitationId.' FOR UPDATE');
        $environment = getenv() ?: [];
        $environment['DB_CONNECTION'] = 'mysql';
        $environment['DB_DATABASE'] = $database;
        $child = proc_open(
            [PHP_BINARY, base_path('tests/Support/accept-invitation-once.php'), $token],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment
        );
        $this->assertIsResource($child);
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("ready\n", fgets($pipes[1]));
        sleep(1);
        $email = '';

        try {
            $holder->rollBack();
            $result = trim((string) fgets($pipes[1]));
            $waited = (float) trim((string) fgets($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($child);
            $this->assertSame(0, $exit, $error);
            $this->assertSame('accepted', $result, $error);
            $this->assertGreaterThan(0.8, $waited);
            $email = (string) DB::table('company_admin_invitations')->where('id', $invitationId)->value('email');
            $this->assertSame(1, DB::table('users')->where('email', $email)->count());
            $this->assertSame(1, DB::table('model_has_roles')->where('tenant_id', $companyId)->count());
            app(CompanyAdminInvitation::class)->accept($token, 'Locked Admin', 'Password1');
            $this->fail('A replayed invitation should not create a second account.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(1, DB::table('users')->where('email', $email)->count());
        } finally {
            if ($holder->inTransaction()) {
                $holder->rollBack();
            }
            $userIds = DB::table('users')->where('email', $email)->pluck('id');
            DB::table('model_has_roles')->where('tenant_id', $companyId)->delete();
            DB::table('role_assignment_events')->where('tenant_id', $companyId)->delete();
            DB::table('company_lifecycle_events')->where('tenant_id', $companyId)->delete();
            DB::table('roles')->where('tenant_id', $companyId)->delete();
            DB::table('company_admin_invitations')->where('tenant_id', $companyId)->delete();
            DB::table('users')->whereIn('id', $userIds->push($actorId))->delete();
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
