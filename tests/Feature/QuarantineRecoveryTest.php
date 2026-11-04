<?php

namespace Tests\Feature;

use App\Services\Hrms\StoredFileQuarantine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class QuarantineRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private int $quarantineNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    public function test_an_interrupted_quarantine_is_restored_when_the_record_exists(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-present');
        $offerId = $this->offer($company->id, 'Ambica');
        $caseId = $this->case($company->id, 'Manikanta');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $case = 'i983/'.$company->id.'/'.$caseId.'/student-v1.pdf';
        $letterhead = 'usa-offers/'.$company->id.'/stationery/letterhead.png';
        $official = 'i983/official-i983.pdf';
        Storage::disk('local')->put($letterhead, 'letterhead');
        Storage::disk('local')->put($official, 'official-form');
        $this->park($offer, 'offer-pdf');
        $this->park($case, 'case-pdf');
        $this->park($letterhead, 'quarantined-letterhead');
        $this->park($official, 'quarantined-official');

        $quarantine = new StoredFileQuarantine;
        $quarantine->recover($company->id);
        $quarantine->recover($company->id);

        $this->assertSame('offer-pdf', Storage::disk('local')->get($offer));
        $this->assertSame('case-pdf', Storage::disk('local')->get($case));
        $this->assertSame('letterhead', Storage::disk('local')->get($letterhead));
        $this->assertSame('official-form', Storage::disk('local')->get($official));
        $this->assertSame(['quarantined-letterhead', 'quarantined-official'], $this->quarantineContents());
    }

    public function test_an_interrupted_quarantine_is_removed_after_the_record_was_deleted(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-absent');
        $offerId = $this->offer($company->id, 'Ambica');
        $caseId = $this->case($company->id, 'Manikanta');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $case = 'i983/'.$company->id.'/'.$caseId.'/student-v1.pdf';
        $this->park($offer, 'offer-pdf');
        $this->park($case, 'case-pdf');
        DB::table('usa_offers')->where('id', $offerId)->delete();
        DB::table('i983_cases')->where('id', $caseId)->delete();

        $quarantine = new StoredFileQuarantine;
        $quarantine->recover($company->id);
        $quarantine->recover($company->id);

        Storage::disk('local')->assertMissing($offer);
        Storage::disk('local')->assertMissing($case);
        $this->assertSame([], $this->quarantineContents());
    }

    public function test_a_missing_quarantine_file_does_not_create_a_live_file(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-missing');
        $offerId = $this->offer($company->id, 'Ambica');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $prefix = 'quarantine/'.sprintf('%032x', 90);
        Storage::disk('local')->makeDirectory($prefix);

        (new StoredFileQuarantine)->recover($company->id);

        Storage::disk('local')->assertMissing($offer);
        $this->assertSame([], $this->quarantineContents());
    }

    public function test_recovery_for_one_company_does_not_change_another_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-north');
        $other = CompanyFixture::make('Other Company', 'recover-south');
        $offerId = $this->offer($company->id, 'Ambica');
        $otherId = $this->offer($other->id, 'Outside');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $outside = 'usa-offers/'.$other->id.'/'.$otherId.'.pdf';
        $this->park($offer, 'north-pdf');
        $this->park($outside, 'south-pdf');
        DB::table('usa_offers')->where('id', $otherId)->delete();

        (new StoredFileQuarantine)->recover($company->id);

        $this->assertSame('north-pdf', Storage::disk('local')->get($offer));
        Storage::disk('local')->assertMissing($outside);
        $this->assertSame(['south-pdf'], $this->quarantineContents());
    }

    public function test_recovery_does_not_overwrite_a_live_file(): void
    {
        $warnings = $this->loggedWarnings();
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-live');
        $offerId = $this->offer($company->id, 'Ambica');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        Storage::disk('local')->put($offer, 'live-pdf');
        $this->park($offer, 'quarantined-pdf');

        $quarantine = new StoredFileQuarantine;
        $quarantine->recover($company->id);
        $quarantine->recover($company->id);

        $this->assertSame('live-pdf', Storage::disk('local')->get($offer));
        $this->assertSame(['quarantined-pdf'], $this->quarantineContents());
        $this->assertContains('Quarantine file was left in place because the live file already exists.', $warnings);
    }

    public function test_a_failed_restore_leaves_the_quarantine_file_in_place(): void
    {
        $warnings = $this->loggedWarnings();
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'recover-fail');
        $offerId = $this->offer($company->id, 'Ambica');
        $offer = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $this->park($offer, 'offer-pdf');
        $quarantine = new class extends StoredFileQuarantine
        {
            protected function transfer(string $from, string $to): void
            {
                throw new \RuntimeException('restore failed');
            }
        };

        $quarantine->recover($company->id);
        $quarantine->recover($company->id);

        Storage::disk('local')->assertMissing($offer);
        $this->assertSame(['offer-pdf'], $this->quarantineContents());
        $this->assertNotNull(DB::table('usa_offers')->where('id', $offerId)->first());
        $this->assertContains('Quarantine file could not be restored.', $warnings);
    }

    private function loggedWarnings(): \ArrayObject
    {
        $warnings = new \ArrayObject();
        Log::listen(function (MessageLogged $event) use ($warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        return $warnings;
    }

    private function park(string $original, string $contents): void
    {
        $this->quarantineNumber++;
        Storage::disk('local')->put('quarantine/'.sprintf('%032x', $this->quarantineNumber).'/'.$original, $contents);
    }

    /**
     * @return list<string>
     */
    private function quarantineContents(): array
    {
        if (! Storage::disk('local')->exists('quarantine')) {
            return [];
        }

        $contents = array_map(
            fn (string $path): string => Storage::disk('local')->get($path),
            Storage::disk('local')->allFiles('quarantine'),
        );
        sort($contents);

        return $contents;
    }

    private function offer(int $companyId, string $candidate): int
    {
        return (int) DB::table('usa_offers')->insertGetId([
            'tenant_id' => $companyId,
            'designation_name' => 'Data Engineer',
            'responsibilities' => json_encode(['Build data pipelines']),
            'candidate_name' => $candidate,
            'candidate_email' => strtolower($candidate).'@example.test',
            'pay_type' => 'hourly',
            'pay_amount' => 20,
            'working_hours' => '25 hours per week',
            'work_arrangement' => 'Remote',
            'university' => 'Example University',
            'start_date' => '2026-11-05',
            'issue_date' => '2026-11-04',
            'template_version' => 'usa-veltrix-1',
            'status' => 'generated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function case(int $companyId, string $candidate): int
    {
        return (int) DB::table('i983_cases')->insertGetId([
            'tenant_id' => $companyId,
            'reference' => 'I983-'.strtoupper(substr($candidate, 0, 6)),
            'candidate_name' => $candidate,
            'candidate_email' => strtolower($candidate).'@example.test',
            'email_hash' => hash('sha256', strtolower($candidate).'@example.test'),
            'status' => 'awaiting_student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
