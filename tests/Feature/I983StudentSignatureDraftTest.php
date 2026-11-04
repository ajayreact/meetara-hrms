<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\Support\I983StudentSignature;
use Tests\TestCase;

class I983StudentSignatureDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePrivateDisk();
    }

    protected function tearDown(): void
    {
        foreach (['i983_documents' => 'file_path', 'i983_student_certifications' => 'signature_path'] as $table => $column) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                foreach (DB::table($table)->pluck($column) as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }
            }
        }

        parent::tearDown();
    }

    public function test_a_student_signature_survives_draft_save_refresh_and_is_stamped_on_submission(): void
    {
        $this->withoutVite();
        $company = CompanyFixture::make('Acme People', 'i983-student-draft-signature');
        $created = $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.quinn@example.test',
        ]);
        $created->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');
        $drawing = I983StudentSignature::fields()['signature'];
        $this->assertInstanceOf(UploadedFile::class, $drawing);

        $this->post($link, [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'signature' => $drawing,
            'submit' => 0,
        ])->assertRedirect();

        $draftPath = 'i983/'.$company->id.'/'.$caseId.'/student-signature-draft.png';
        $draftBytes = Storage::disk('local')->get($draftPath);
        $this->assertIsString($draftBytes);
        $this->assertStringStartsWith("\x89PNG", $draftBytes);
        $this->assertSame(hash('sha256', $draftBytes), trim((string) Storage::disk('local')->get($draftPath.'.sha256')));
        $this->assertFalse(is_file(public_path($draftPath)));
        $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());
        $this->assertSame(0, DB::table('i983_certifications')->where('case_id', $caseId)->count());

        $this->get($link)->assertOk()
            ->assertDontSee('student-signature-draft')
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.signatureOnFile', true)
                ->where('form.locked', false)
            );
        $preview = $this->get($link.'/signature')->assertOk();
        $this->assertSame('image/png', $preview->headers->get('content-type'));
        $this->assertSame($draftBytes, $preview->getContent());
        $this->get($link.'/'.str_repeat('a', 64).'/signature')->assertNotFound();
        $this->get('/i983/s/'.str_repeat('b', 64).'/signature')->assertNotFound();

        $this->post($link, [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'goals' => 'Keep the saved drawing',
            'submit' => 0,
        ])->assertRedirect();
        $this->assertSame($draftBytes, Storage::disk('local')->get($draftPath));
        $this->assertSame($draftBytes, $this->get($link.'/signature')->assertOk()->getContent());

        Storage::disk('local')->put($draftPath.'.sha256', str_repeat('0', 64));
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->where('form.signatureOnFile', false));
        $this->get($link.'/signature')->assertNotFound();
        Storage::disk('local')->put($draftPath.'.sha256', hash('sha256', $draftBytes));

        $this->post($link, ['clear_signature' => '1', 'submit' => 0])->assertRedirect();
        $this->assertFalse(Storage::disk('local')->exists($draftPath));
        $this->get($link.'/signature')->assertNotFound();

        $replacement = I983StudentSignature::fields()['signature'];
        $this->post($link, $this->sectionOne(['signature' => $replacement], false))->assertRedirect();
        $stored = Storage::disk('local')->get($draftPath);
        $this->assertIsString($stored);
        $this->assertNotSame($draftBytes, $stored);

        $this->post($link, $this->sectionOne([], true))->assertRedirect();
        $this->assertSame('student_submitted', DB::table('i983_cases')->where('id', $caseId)->value('status'));
        $this->assertFalse(Storage::disk('local')->exists($draftPath));
        $certification = DB::table('i983_student_certifications')->where('case_id', $caseId)->first();
        $this->assertNotNull($certification);
        $this->assertSame($stored, Storage::disk('local')->get($certification->signature_path));
        $this->assertSame(0, DB::table('i983_certifications')->where('case_id', $caseId)->count());
        $snapshot = Storage::disk('local')->get(
            (string) DB::table('i983_documents')->where('case_id', $caseId)->where('kind', 'student_snapshot')->value('file_path')
        );
        $this->assertIsString($snapshot);
        $this->assertSame(5, preg_match_all('/\/Type\s*\/Page(?!s)/', $snapshot));
        $this->assertStringContainsString('/Subtype /Image', $snapshot);
        $this->assertStringContainsString('144.00 173.90 Td (Quinn, Riley)', $snapshot);
        $this->assertStringNotContainsString('320.00 97.80 Td', $snapshot);
        $this->assertStringNotContainsString('320.00 451.80 Td', $snapshot);
        $this->assertSame($stored, $this->get($link.'/signature')->assertOk()->getContent());
    }

    public function test_a_blank_or_typed_draft_signature_is_not_stored(): void
    {
        $company = CompanyFixture::make('Acme People', 'i983-student-draft-blank');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Riley Quinn',
            'candidate_email' => 'riley.blank@example.test',
        ])->assertRedirect();
        $link = (string) session('i983_link');
        $caseId = (int) DB::table('i983_cases')->where('tenant_id', $company->id)->value('id');

        $this->post($link, [
            'surname' => 'Quinn',
            'signature' => $this->blankSignature(),
            'submit' => 0,
        ])->assertSessionHasErrors('signature');
        $this->post($link, [
            'surname' => 'Quinn',
            'signature' => 'Riley Quinn',
            'submit' => 0,
        ])->assertRedirect();

        $this->assertFalse(Storage::disk('local')->exists('i983/'.$company->id.'/'.$caseId.'/student-signature-draft.png'));
        $this->assertSame(0, DB::table('i983_student_certifications')->where('case_id', $caseId)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sectionOne(array $overrides, bool $submit): array
    {
        return [
            'surname' => 'Quinn',
            'given_name' => 'Riley',
            'middle_name' => '',
            'email' => 'riley.quinn@example.test',
            'school_recommending' => 'Example State University',
            'school_degree' => 'Example State University',
            'sevis_school_code' => 'DET214F00001000',
            'dso_name' => 'Avery Cole',
            'dso_email' => 'dso@ex.edu',
            'dso_phone' => '+1 (248) 555-0199',
            'sevis_id' => 'N0000000001',
            'opt_start' => '2026-04-06',
            'opt_end' => '2028-04-05',
            'major_cip' => 'Computer Science 11.0701',
            'degree_level' => 'Master',
            'degree_awarded_on' => '2026-05-01',
            'prior_degree' => 'no',
            'ead' => 'IOE0000000001',
            'goals' => '',
            'submit' => $submit ? 1 : 0,
            'confirm_certification' => $submit ? '1' : '0',
            'confirm_name' => $submit ? '1' : '0',
            ...$overrides,
        ];
    }

    private function blankSignature(): UploadedFile
    {
        $image = imagecreatetruecolor(680, 48);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $path = $directory.'/blank-draft-signature.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'blank-draft-signature.png', 'image/png', null, true);
    }
}
