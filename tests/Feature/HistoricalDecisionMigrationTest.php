<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class HistoricalDecisionMigrationTest extends TestCase
{
    public function test_note_column_can_be_added_removed_and_restored_without_changing_existing_rows(): void
    {
        if (TestDatabaseGuard::integrationDecision('meetara_hrms_decision_20261020') === 'skip') {
            $this->markTestSkipped('The decision-note migration rehearsal runs only on meetara_hrms_decision_20261020.');
        }

        $this->assertFalse(Schema::hasColumn('employee_record_events', 'note'));
        $before = $this->snapshot();

        Artisan::call('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_10_20_200000_add_employee_record_event_note.php',
        ]);
        $this->assertTrue(Schema::hasColumn('employee_record_events', 'note'));
        $this->assertSame($before, $this->snapshot());

        Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
        $this->assertFalse(Schema::hasColumn('employee_record_events', 'note'));
        $this->assertTrue(Schema::hasTable('offer_letters'));
        $this->assertTrue(Schema::hasColumn('onboardings', 'welcomed_at'));
        $this->assertSame($before, $this->snapshot());

        Artisan::call('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_10_20_200000_add_employee_record_event_note.php',
        ]);
        $this->assertTrue(Schema::hasColumn('employee_record_events', 'note'));
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_20_120000_add_company_lifecycle_events')->count());
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_20_140000_add_company_admin_invitations')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_20_200000_add_employee_record_event_note')->count());
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<string, int|string>
     */
    private function snapshot(): array
    {
        $tasks = DB::table('onboarding_tasks')->orderBy('id')->get(['id', 'title', 'status', 'description', 'code']);
        $descriptions = hash('sha256', $tasks->map(fn ($task) => $task->id.'|'.$task->title.'|'.$task->status.'|'.$task->code.'|'.$task->description)->implode("\n"));

        return [
            'migrations' => DB::table('migrations')->where('migration', '!=', '2026_10_20_200000_add_employee_record_event_note')->count(),
            'tasks' => $tasks->count(),
            'historical' => DB::table('onboarding_tasks')->whereNull('code')->count(),
            'events' => DB::table('employee_record_events')->count(),
            'offers' => Schema::hasTable('offer_letters') ? DB::table('offer_letters')->count() : -1,
            'descriptions' => $descriptions,
        ];
    }
}
