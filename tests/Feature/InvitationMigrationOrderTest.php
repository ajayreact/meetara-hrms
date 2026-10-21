<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvitationMigrationOrderTest extends TestCase
{
    public function test_lifecycle_then_invitation_creates_the_required_schema(): void
    {
        $this->parents();
        $migrator = $this->migrator();

        $migrator->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);
        $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);

        $this->assertContains('2026_10_20_120000_add_company_lifecycle_events', $migrator->getRepository()->getRan());
        $this->assertContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
        $this->assertTrue(Schema::hasTable('company_lifecycle_events'));
        $this->assertTrue(Schema::hasTable('company_admin_invitations'));

        $email = collect(Schema::getColumns('company_lifecycle_events'))->firstWhere('name', 'subject_email');
        $this->assertIsArray($email);
        $this->assertTrue($email['nullable']);
        $this->assertContains('tenant_id', Schema::getColumnListing('company_admin_invitations'));
        $this->assertContains('token_hash', Schema::getColumnListing('company_admin_invitations'));
        $this->assertContains('invited_by', Schema::getColumnListing('company_admin_invitations'));

        $indexes = collect(Schema::getIndexes('company_admin_invitations'))->pluck('columns');
        $this->assertTrue($indexes->contains(fn (array $columns): bool => $columns === ['tenant_id', 'email']));
        $this->assertTrue($indexes->contains(fn (array $columns): bool => $columns === ['token_hash']));

        $foreignTables = collect(Schema::getForeignKeys('company_lifecycle_events'))->pluck('foreign_table')->all();
        $this->assertContains('tenants', $foreignTables);
        $this->assertContains('users', $foreignTables);
    }

    public function test_invitation_migration_refuses_without_the_lifecycle_table(): void
    {
        $this->parents();
        $migrator = $this->migrator();

        try {
            $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);
            $this->fail('The invitation migration should refuse to run without company_lifecycle_events.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('requires company_lifecycle_events', $exception->getMessage());
            $this->assertStringContainsString('2026_10_20_120000_add_company_lifecycle_events', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('company_admin_invitations'));
        $this->assertFalse(Schema::hasTable('company_lifecycle_events'));
        $this->assertNotContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
    }

    public function test_invitation_migration_refuses_when_subject_email_is_absent(): void
    {
        $this->parents();
        $migrator = $this->migrator();
        $migrator->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);
        $this->assertFalse(Schema::hasColumn('company_lifecycle_events', 'subject_email'));

        $original = Schema::getFacadeRoot();
        Schema::swap(new class(Schema::getConnection()) extends Builder
        {
            public function hasColumn($table, $column)
            {
                if ($table === 'company_lifecycle_events' && $column === 'subject_email') {
                    return false;
                }

                return parent::hasColumn($table, $column);
            }

            public function table($table, \Closure $callback)
            {
                if ($table === 'company_lifecycle_events') {
                    return;
                }

                parent::table($table, $callback);
            }
        });

        try {
            $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);
            $this->fail('The invitation migration should refuse to finish while subject_email is absent.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('subject_email', $exception->getMessage());
        } finally {
            Schema::swap($original);
        }

        $this->assertFalse(Schema::hasTable('company_admin_invitations'));
        $this->assertFalse(Schema::hasColumn('company_lifecycle_events', 'subject_email'));
        $this->assertNotContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
    }

    public function test_invitation_migration_refuses_without_parent_tables(): void
    {
        $migrator = $this->migrator();

        try {
            $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);
            $this->fail('The invitation migration should refuse to run without tenants and users.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tenants and users', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('company_admin_invitations'));
        $this->assertNotContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
    }

    public function test_incomplete_sqlite_history_does_not_create_a_partial_invitation_schema(): void
    {
        $migrator = $this->migrator();
        $migrator->run([$this->path('2026_10_20_120000_add_company_lifecycle_events.php')]);
        $migrator->run([$this->path('2026_10_20_140000_add_company_admin_invitations.php')]);

        $this->assertContains('2026_10_20_120000_add_company_lifecycle_events', $migrator->getRepository()->getRan());
        $this->assertContains('2026_10_20_140000_add_company_admin_invitations', $migrator->getRepository()->getRan());
        $this->assertFalse(Schema::hasTable('tenants'));
        $this->assertFalse(Schema::hasTable('company_lifecycle_events'));
        $this->assertFalse(Schema::hasTable('company_admin_invitations'));
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

    private function migrator(): Migrator
    {
        $migrator = app('migrator');
        $migrator->getRepository()->createRepository();

        return $migrator;
    }

    private function path(string $file): string
    {
        return database_path('migrations/'.$file);
    }
}
