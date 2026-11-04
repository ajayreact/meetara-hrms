<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Hrms\StoredFileQuarantine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class MistakenRecordDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    public function test_head_of_operations_can_delete_a_usa_offer_and_its_pdf(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix-delete-offer');
        $other = CompanyFixture::make('Other Company', 'other-delete-offer');
        $owner = $this->owner($company);
        $offerId = $this->offer($company->id, 'Ambica', 'generated');
        $keptId = $this->offer($company->id, 'Kept Candidate', 'draft');
        $outsideId = $this->offer($other->id, 'Outside Candidate', 'generated');
        $pdf = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $keptPdf = 'usa-offers/'.$company->id.'/'.$keptId.'.pdf';
        $letterhead = 'usa-offers/'.$company->id.'/stationery/letterhead.png';
        $signature = 'usa-offers/'.$company->id.'/stationery/signature.png';
        Storage::disk('local')->put($pdf, 'offer-pdf');
        Storage::disk('local')->put($keptPdf, 'kept-pdf');
        Storage::disk('local')->put($letterhead, 'letterhead');
        Storage::disk('local')->put($signature, 'signature');
        DB::table('usa_offers')->where('id', $offerId)->update(['file_path' => $pdf]);
        DB::table('usa_offers')->where('id', $keptId)->update(['file_path' => $letterhead]);

        $this->actingAs($company->hr)->delete('/usa-offers/'.$offerId)->assertForbidden();
        $this->actingAs($company->lead)->delete('/usa-offers/'.$offerId)->assertForbidden();
        $this->actingAs($company->employee)->delete('/usa-offers/'.$offerId)->assertForbidden();
        $this->assertNotNull(DB::table('usa_offers')->where('id', $offerId)->first());

        $this->actingAs($owner)->delete('/usa-offers/'.$outsideId)->assertNotFound();
        $this->actingAs($owner)->delete('/usa-offers/'.$offerId)->assertRedirect('/usa-offers');
        $this->actingAs($owner)->delete('/usa-offers/'.$offerId)->assertNotFound();
        $this->actingAs($owner)->delete('/usa-offers/'.$keptId)->assertRedirect('/usa-offers');

        $this->assertNull(DB::table('usa_offers')->where('id', $offerId)->first());
        $this->assertNull(DB::table('usa_offers')->where('id', $keptId)->first());
        $this->assertNotNull(DB::table('usa_offers')->where('id', $outsideId)->first());
        Storage::disk('local')->assertMissing($pdf);
        Storage::disk('local')->assertExists($keptPdf);
        Storage::disk('local')->assertExists($letterhead);
        Storage::disk('local')->assertExists($signature);
        $this->assertQuarantineEmpty();
    }

    public function test_head_of_operations_can_delete_an_i983_case_and_its_files(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix-delete-i983');
        $other = CompanyFixture::make('Other Company', 'other-delete-i983');
        $owner = $this->owner($company);
        $caseId = $this->case($company, 'Manikanta Vinit Sai Shenkeshi');
        $keptId = $this->case($company, 'Kept Student');
        $outsideId = $this->case($other, 'Outside Student');
        $reference = (string) DB::table('i983_cases')->where('id', $caseId)->value('reference');
        $pdf = 'i983/'.$company->id.'/'.$caseId.'/student-v1.pdf';
        $keptPdf = 'i983/'.$company->id.'/'.$keptId.'/student-v1.pdf';
        $signatory = 'i983/'.$company->id.'/signatory.png';
        $official = 'i983/official-i983.pdf';
        Storage::disk('local')->put($pdf, 'case-pdf');
        Storage::disk('local')->put($keptPdf, 'kept-case-pdf');
        Storage::disk('local')->put($signatory, 'company-signature');
        Storage::disk('local')->put($official, 'official-form');
        DB::table('i983_documents')->insert([
            'tenant_id' => $company->id,
            'case_id' => $caseId,
            'kind' => 'student',
            'version' => 1,
            'file_path' => $pdf,
            'original_name' => 'student.pdf',
            'mime' => 'application/pdf',
            'size' => 8,
            'created_at' => now(),
        ]);

        $this->actingAs($company->hr)->delete('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($company->lead)->delete('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($company->employee)->delete('/i983/'.$caseId)->assertForbidden();
        $this->actingAs($owner)->delete('/i983/'.$outsideId)->assertNotFound();
        $this->actingAs($owner)->delete('/i983/'.$caseId)->assertRedirect('/i983');
        $this->actingAs($owner)->delete('/i983/'.$caseId)->assertNotFound();

        $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->first());
        $this->assertSame(0, DB::table('i983_links')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_documents')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_employers')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_students')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_training_plans')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_events')->where('case_id', $caseId)->count());
        $this->assertNotNull(DB::table('i983_cases')->where('id', $keptId)->first());
        $this->assertNotNull(DB::table('i983_employers')->where('case_id', $keptId)->first());
        $this->assertNotNull(DB::table('i983_cases')->where('id', $outsideId)->first());
        $this->assertSame(1, DB::table('i983_events')->where('tenant_id', $company->id)->where('action', 'case_deleted')->count());
        $this->assertSame($reference, DB::table('i983_events')->where('tenant_id', $company->id)->where('action', 'case_deleted')->value('subject'));
        $this->assertNull(DB::table('i983_events')->where('action', 'case_deleted')->value('case_id'));
        $this->assertSame($owner->id, (int) DB::table('i983_events')->where('action', 'case_deleted')->value('actor_id'));
        Storage::disk('local')->assertMissing($pdf);
        Storage::disk('local')->assertExists($keptPdf);
        Storage::disk('local')->assertExists($signatory);
        Storage::disk('local')->assertExists($official);
        $this->assertQuarantineEmpty();
    }

    public function test_a_quarantine_failure_keeps_the_record_and_its_files(): void
    {
        [$company, $owner, $offerId, $pdf, $letterhead, $caseId, $casePdf, $signatory, $official] = $this->deletionTargets('quarantine-fail');
        $this->bindQuarantine(new class extends StoredFileQuarantine
        {
            public function move(array $paths): void
            {
                throw new \RuntimeException('quarantine failed');
            }
        });

        $this->actingAs($owner)->from('/usa-offers')->delete('/usa-offers/'.$offerId)->assertSessionHasErrors('delete');
        $this->bindQuarantine(new class extends StoredFileQuarantine
        {
            public function move(array $paths): void
            {
                throw new \RuntimeException('quarantine failed');
            }
        });
        $this->actingAs($owner)->from('/i983')->delete('/i983/'.$caseId)->assertSessionHasErrors('delete');

        $this->assertNotNull(DB::table('usa_offers')->where('id', $offerId)->first());
        $this->assertNotNull(DB::table('i983_cases')->where('id', $caseId)->first());
        $this->assertNotNull(DB::table('i983_links')->where('case_id', $caseId)->first());
        $this->assertSame(0, DB::table('i983_events')->where('action', 'case_deleted')->count());
        $this->assertSame('offer-pdf', Storage::disk('local')->get($pdf));
        $this->assertSame('case-pdf', Storage::disk('local')->get($casePdf));
        Storage::disk('local')->assertExists($letterhead);
        Storage::disk('local')->assertExists($signatory);
        Storage::disk('local')->assertExists($official);
        $this->assertQuarantineEmpty();
    }

    public function test_a_database_failure_restores_quarantined_files(): void
    {
        [$company, $owner, $offerId, $pdf, $letterhead, $caseId, $casePdf, $signatory, $official] = $this->deletionTargets('database-fail');
        DB::beforeExecuting(function (string $query): void {
            if (preg_match('/delete\s+from\s+["`]?(usa_offers|i983_cases)["`]?/i', $query) === 1) {
                throw new \RuntimeException('forced database failure');
            }
        });

        $this->actingAs($owner)->from('/usa-offers')->delete('/usa-offers/'.$offerId)->assertSessionHasErrors('delete');
        $this->actingAs($owner)->from('/i983')->delete('/i983/'.$caseId)->assertSessionHasErrors('delete');

        $this->assertNotNull(DB::table('usa_offers')->where('id', $offerId)->first());
        $this->assertNotNull(DB::table('i983_cases')->where('id', $caseId)->first());
        $this->assertNotNull(DB::table('i983_employers')->where('case_id', $caseId)->first());
        $this->assertNotNull(DB::table('i983_links')->where('case_id', $caseId)->first());
        $this->assertSame(0, DB::table('i983_events')->where('action', 'case_deleted')->count());
        $this->assertSame('offer-pdf', Storage::disk('local')->get($pdf));
        $this->assertSame('case-pdf', Storage::disk('local')->get($casePdf));
        Storage::disk('local')->assertExists($letterhead);
        Storage::disk('local')->assertExists($signatory);
        Storage::disk('local')->assertExists($official);
        $this->assertQuarantineEmpty();
    }

    public function test_a_cleanup_failure_keeps_the_database_delete_and_leaves_files_quarantined(): void
    {
        [$company, $owner, $offerId, $pdf, $letterhead, $caseId, $casePdf, $signatory, $official] = $this->deletionTargets('cleanup-fail');
        $reference = (string) DB::table('i983_cases')->where('id', $caseId)->value('reference');
        $this->bindQuarantine(new class extends StoredFileQuarantine
        {
            public function purge(): void
            {
                throw new \RuntimeException('cleanup failed');
            }
        });

        $this->actingAs($owner)->delete('/usa-offers/'.$offerId)->assertRedirect('/usa-offers');
        $this->bindQuarantine(new class extends StoredFileQuarantine
        {
            public function purge(): void
            {
                throw new \RuntimeException('cleanup failed');
            }
        });
        $this->actingAs($owner)->delete('/i983/'.$caseId)->assertRedirect('/i983');

        $this->assertNull(DB::table('usa_offers')->where('id', $offerId)->first());
        $this->assertNull(DB::table('i983_cases')->where('id', $caseId)->first());
        $this->assertSame(0, DB::table('i983_links')->where('case_id', $caseId)->count());
        $this->assertSame($reference, DB::table('i983_events')->where('action', 'case_deleted')->value('subject'));
        Storage::disk('local')->assertMissing($pdf);
        Storage::disk('local')->assertMissing($casePdf);
        Storage::disk('local')->assertExists($letterhead);
        Storage::disk('local')->assertExists($signatory);
        Storage::disk('local')->assertExists($official);
        $quarantined = Storage::disk('local')->allFiles('quarantine');
        $this->assertCount(2, $quarantined);
        $contents = array_map(fn (string $path): string => Storage::disk('local')->get($path), $quarantined);
        sort($contents);
        $this->assertSame(['case-pdf', 'offer-pdf'], $contents);
    }

    private function bindQuarantine(StoredFileQuarantine $quarantine): void
    {
        $this->app->instance(StoredFileQuarantine::class, $quarantine);
    }

    /**
     * @return array{0: CompanyFixture, 1: User, 2: int, 3: string, 4: string, 5: int, 6: string, 7: string, 8: string}
     */
    private function deletionTargets(string $slug): array
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'veltrix-'.$slug);
        $owner = $this->owner($company);
        $offerId = $this->offer($company->id, 'Ambica', 'generated');
        $caseId = $this->case($company, 'Manikanta Vinit Sai Shenkeshi');
        $pdf = 'usa-offers/'.$company->id.'/'.$offerId.'.pdf';
        $letterhead = 'usa-offers/'.$company->id.'/stationery/letterhead.png';
        $casePdf = 'i983/'.$company->id.'/'.$caseId.'/student-v1.pdf';
        $signatory = 'i983/'.$company->id.'/signatory.png';
        $official = 'i983/official-i983.pdf';
        Storage::disk('local')->put($pdf, 'offer-pdf');
        Storage::disk('local')->put($letterhead, 'letterhead');
        Storage::disk('local')->put($casePdf, 'case-pdf');
        Storage::disk('local')->put($signatory, 'company-signature');
        Storage::disk('local')->put($official, 'official-form');
        DB::table('usa_offers')->where('id', $offerId)->update(['file_path' => $pdf]);

        return [$company, $owner, $offerId, $pdf, $letterhead, $caseId, $casePdf, $signatory, $official];
    }

    private function assertQuarantineEmpty(): void
    {
        if (! Storage::disk('local')->exists('quarantine')) {
            return;
        }

        $this->assertSame([], Storage::disk('local')->allFiles('quarantine'));
    }

    private function owner(CompanyFixture $company): User
    {
        $roleId = (int) DB::table('roles')->where('tenant_id', $company->id)->where('name', 'Owner')->value('id');
        $user = User::factory()->create();
        $user->forceFill([
            'tenant_id' => $company->id,
            'is_platform_admin' => false,
            'invite_accepted_at' => now(),
        ])->save();
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $user->id,
            'tenant_id' => $company->id,
        ]);

        return $user->refresh();
    }

    private function offer(int $companyId, string $candidate, string $status): int
    {
        return (int) DB::table('usa_offers')->insertGetId([
            'tenant_id' => $companyId,
            'designation_name' => 'Data Engineer',
            'responsibilities' => json_encode(['Build data pipelines']),
            'candidate_name' => $candidate,
            'candidate_email' => strtolower(str_replace(' ', '.', $candidate)).'@example.test',
            'pay_type' => 'hourly',
            'pay_amount' => 20,
            'working_hours' => '25 hours per week',
            'work_arrangement' => 'Remote',
            'university' => 'Example University',
            'start_date' => '2026-11-05',
            'issue_date' => '2026-11-04',
            'template_version' => 'usa-veltrix-1',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function case(CompanyFixture $company, string $candidate): int
    {
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => $candidate,
            'candidate_email' => strtolower(str_replace(' ', '.', $candidate)).'@example.test',
        ]);
        $created->assertRedirect();

        return (int) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_name', $candidate)->value('id');
    }
}
