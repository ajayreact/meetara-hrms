<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubjectEmailRepairTest extends TestCase
{
    public function test_repair_fails_when_the_lifecycle_table_is_missing(): void
    {
        $this->parents();
        Schema::create('company_admin_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('email', 150);
        });
        $migrator = $this->migrator();

        try {
            $migrator->run([$this->repairPath()]);
            $this->fail('The repair should refuse to run without company_lifecycle_events.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('company_lifecycle_events', $exception->getMessage());
            $this->assertStringContainsString('does not create either table', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('company_lifecycle_events'));
        $this->assertTrue(Schema::hasTable('company_admin_invitations'));
        $this->assertNotContains('2026_10_21_120000_repair_company_lifecycle_subject_email', $migrator->getRepository()->getRan());
    }

    public function test_repair_fails_when_the_invitation_table_is_missing(): void
    {
        $this->parents();
        $migrator = $this->migrator();
        $migrator->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);

        try {
            $migrator->run([$this->repairPath()]);
            $this->fail('The repair should refuse to run without company_admin_invitations.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('company_admin_invitations', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('company_lifecycle_events'));
        $this->assertFalse(Schema::hasColumn('company_lifecycle_events', 'subject_email'));
        $this->assertFalse(Schema::hasTable('company_admin_invitations'));
        $this->assertNotContains('2026_10_21_120000_repair_company_lifecycle_subject_email', $migrator->getRepository()->getRan());
    }

    public function test_repair_adds_the_missing_column_and_preserves_rows(): void
    {
        $this->bothTablesWithoutColumn();
        DB::table('tenants')->insert(['id' => 1]);
        DB::table('users')->insert(['id' => 1]);
        DB::table('company_lifecycle_events')->insert([
            'tenant_id' => 1,
            'actor_id' => 1,
            'action' => 'opened',
            'created_at' => '2026-10-21 00:00:00',
        ]);
        DB::table('company_admin_invitations')->insert([
            'tenant_id' => 1,
            'email' => 'admin@northwind.test',
        ]);
        $migrator = $this->migrator();
        DB::table('migrations')->insert([
            'migration' => '2026_10_20_140000_add_company_admin_invitations',
            'batch' => 4,
        ]);
        $before = DB::table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->map(fn ($row) => (array) $row)->all();

        $repair = require $this->repairPath();
        $repair->up();

        $email = collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email');
        $this->assertIsArray($email);
        $this->assertTrue($email['nullable']);
        $this->assertSame('varchar', strtolower($email['type_name']));
        $this->assertSame('opened', DB::table('company_lifecycle_events')->value('action'));
        $this->assertNull(DB::table('company_lifecycle_events')->value('subject_email'));
        $this->assertSame('admin@northwind.test', DB::table('company_admin_invitations')->value('email'));
        $this->assertSame($before, DB::table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->map(fn ($row) => (array) $row)->all());
        $this->assertNotContains('2026_10_21_120000_repair_company_lifecycle_subject_email', $migrator->getRepository()->getRan());
    }

    public function test_repair_leaves_a_correct_column_unchanged(): void
    {
        $this->bothTablesWithoutColumn();
        DB::table('tenants')->insert(['id' => 1]);
        DB::table('users')->insert(['id' => 1]);
        Schema::table('company_lifecycle_events', function (Blueprint $table): void {
            $table->string('subject_email', 191)->nullable();
        });
        DB::table('company_lifecycle_events')->insert([
            'tenant_id' => 1,
            'actor_id' => 1,
            'action' => 'opened',
            'subject_email' => 'kept@northwind.test',
            'created_at' => '2026-10-21 00:00:00',
        ]);

        $repair = require $this->repairPath();
        $repair->up();
        $repair->up();

        $this->assertSame('kept@northwind.test', DB::table('company_lifecycle_events')->value('subject_email'));
        $this->assertSame('opened', DB::table('company_lifecycle_events')->value('action'));
        $this->assertTrue(collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email')['nullable']);
    }

    public function test_repair_refuses_an_incompatible_column(): void
    {
        $this->bothTablesWithoutColumn();
        DB::table('tenants')->insert(['id' => 1]);
        DB::table('users')->insert(['id' => 1]);
        Schema::table('company_lifecycle_events', function (Blueprint $table): void {
            $table->integer('subject_email')->nullable();
        });
        DB::table('company_lifecycle_events')->insert([
            'tenant_id' => 1,
            'actor_id' => 1,
            'action' => 'opened',
            'subject_email' => 7,
            'created_at' => '2026-10-21 00:00:00',
        ]);
        $migrator = $this->migrator();

        try {
            $migrator->run([$this->repairPath()]);
            $this->fail('The repair should refuse an incompatible subject_email column.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('incompatible definition', $exception->getMessage());
        }

        $column = collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email');
        $this->assertSame('integer', strtolower($column['type_name']));
        $this->assertSame(7, (int) DB::table('company_lifecycle_events')->value('subject_email'));
        $this->assertNotContains('2026_10_21_120000_repair_company_lifecycle_subject_email', $migrator->getRepository()->getRan());
    }

    public function test_lifecycle_then_invitation_still_satisfies_the_repair(): void
    {
        $this->parents();
        $migrator = $this->migrator();
        $migrator->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);
        $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);
        $migrator->run([$this->repairPath()]);

        $email = collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email');
        $this->assertTrue($email['nullable']);
        $this->assertSame('varchar', strtolower($email['type_name']));
        $this->assertContains('2026_10_20_120000_add_company_lifecycle_events', $migrator->getRepository()->getRan());
        $this->assertContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
        $this->assertContains('2026_10_21_120000_repair_company_lifecycle_subject_email', $migrator->getRepository()->getRan());
    }

    private function parents(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    private function bothTablesWithoutColumn(): void
    {
        $this->parents();
        $this->migrator()->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);
        Schema::create('company_admin_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('email', 150);
            $table->timestamps();
        });
    }

    private function migrator(): Migrator
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    private function path(string $file): string
    {
        return database_path('migrations/'.$file);
    }

    private function repairPath(): string
    {
        return $this->path('2026_10_21_120000_repair_company_lifecycle_subject_email.php');
    }
}
