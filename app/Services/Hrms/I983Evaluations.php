<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class I983Evaluations
{
    public const FINAL = 'final';

    public const INITIAL = 'initial';

    public function __construct(
        private readonly I983Cases $cases,
        private readonly I983Pdf $pdf,
        private readonly I983StudentCertifications $certifications,
        private readonly I983Signatory $signatory,
        private readonly I983Documents $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, int $caseId): array
    {
        $case = $this->submittedCase($actor, $caseId);

        return $this->present($case);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveDraft(User $actor, int $caseId, array $input): void
    {
        $case = $this->submittedCase($actor, $caseId);

        if (trim((string) ($input['narrative'] ?? '')) !== '') {
            throw ValidationException::withMessages([
                'narrative' => 'The student writes the progress narrative. HR cannot enter it.',
            ]);
        }

        $from = $this->optionalDate($input['date_from'] ?? null, 'date_from');
        $to = $this->optionalDate($input['date_to'] ?? null, 'date_to');
        $student = DB::table('i983_students')->where('tenant_id', $case->tenant_id)->where('case_id', $caseId)->first();
        $this->assertRange($from, $to, $this->iso($student->opt_start ?? null), $this->iso($student->opt_end ?? null));

        DB::transaction(function () use ($actor, $case, $caseId, $from, $to): void {
            $row = $this->lockedRow((int) $case->tenant_id, $caseId);
            $now = now();
            $narrative = $row === null ? '' : trim((string) ($row->narrative ?? ''));
            $values = [
                'date_from' => $from,
                'date_to' => $to,
                'status' => $this->statusFromParts(
                    $from,
                    $to,
                    $narrative,
                    $row !== null && $this->filled($row->student_signature_path),
                    $row !== null && $this->filled($row->employer_signature_path),
                    $row === null ? null : $row->completed_at,
                    $row === null ? null : $row->document_id,
                ),
                'updated_at' => $now,
            ];

            if ($row === null) {
                $this->insertFinal($case, [
                    ...$values,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                ], 'date_from');
            } else {
                $updated = DB::table('i983_evaluations')
                    ->where('id', $row->id)
                    ->where('tenant_id', $case->tenant_id)
                    ->whereNull('student_signature_path')
                    ->whereNull('completed_at')
                    ->update($values);

                if ($updated !== 1) {
                    throw ValidationException::withMessages([
                        'date_from' => 'These evaluation dates are already signed. They were not changed.',
                    ]);
                }
            }

            DB::table('i983_events')->insert([
                'tenant_id' => $case->tenant_id,
                'case_id' => $caseId,
                'actor_id' => $actor->id,
                'actor_type' => 'hr',
                'action' => 'evaluation_draft_saved',
                'subject' => self::FINAL,
                'created_at' => $now,
            ]);
        });
    }

    public function confirmEmployer(User $actor, int $caseId): void
    {
        abort_unless($this->cases->canSign($actor), 403);
        $case = $this->submittedCase($actor, $caseId);
        $created = null;

        try {
            DB::transaction(function () use ($actor, $case, $caseId, &$created): void {
                $row = $this->lockedRow((int) $case->tenant_id, $caseId);
                $narrative = $row === null ? '' : trim((string) ($row->narrative ?? ''));
                $from = $row === null ? '' : $this->iso($row->date_from);
                $to = $row === null ? '' : $this->iso($row->date_to);
                $studentSigned = $row !== null && $this->filled($row->student_signature_path);

                if ($narrative === '' || $from === '' || $to === '' || ! $studentSigned) {
                    throw ValidationException::withMessages([
                        'signature' => 'The student narrative, both evaluation dates, and the student signature are required before the employer signs.',
                    ]);
                }

                if ($this->filled($row->employer_signature_path) || $row->completed_at !== null) {
                    throw ValidationException::withMessages([
                        'signature' => 'The employer signature is already stored. It was not replaced.',
                    ]);
                }

                $student = DB::table('i983_students')->where('tenant_id', $case->tenant_id)->where('case_id', $caseId)->first();
                $this->assertRange($from, $to, $this->iso($student->opt_start ?? null), $this->iso($student->opt_end ?? null));
                $ready = $this->signatory->ready((int) $case->tenant_id);
                $extension = strtolower(pathinfo($ready['stored'], PATHINFO_EXTENSION));

                if (! in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                    throw ValidationException::withMessages([
                        'signature' => 'The stored employer signature could not be copied.',
                    ]);
                }

                $bytes = file_get_contents($ready['path']);

                if (! is_string($bytes) || $bytes === '') {
                    throw ValidationException::withMessages([
                        'signature' => 'The stored employer signature could not be copied.',
                    ]);
                }

                $created = $this->signaturePath($case, 'employer', $extension);
                Storage::disk('local')->put($created, $bytes);
                $signedOn = now()->copy()->timezone($this->certifications->timezoneFor((int) $case->tenant_id))->toDateString();
                $now = now();
                $updated = DB::table('i983_evaluations')
                    ->where('id', $row->id)
                    ->where('tenant_id', $case->tenant_id)
                    ->whereNull('employer_signature_path')
                    ->whereNull('completed_at')
                    ->update([
                        'employer_printed_name' => $ready['name'],
                        'employer_title' => $ready['title'],
                        'employer_signature_path' => $created,
                        'employer_signed_on' => $signedOn,
                        'status' => $this->statusFromParts($from, $to, $narrative, true, true, null, $row->document_id),
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw ValidationException::withMessages([
                        'signature' => 'The employer signature is already stored. It was not replaced.',
                    ]);
                }

                DB::table('i983_events')->insert([
                    'tenant_id' => $case->tenant_id,
                    'case_id' => $caseId,
                    'actor_id' => $actor->id,
                    'actor_type' => 'signatory',
                    'action' => 'evaluation_employer_signed',
                    'subject' => self::FINAL,
                    'created_at' => $now,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->discardUnstoredSignature($created);

            throw $exception;
        }
    }

    /**
     * @return array{bytes: string, overlaid: bool}
     */
    public function applyToNewFinal(object $case, string $bytes): array
    {
        $payload = $this->drawPayload($case);

        if ($payload === null) {
            return ['bytes' => $bytes, 'overlaid' => false];
        }

        return [
            'bytes' => $this->pdf->overlayFinalEvaluation($bytes, $payload),
            'overlaid' => true,
        ];
    }

    public function markStored(object $case, int $documentId): void
    {
        DB::table('i983_evaluations')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->where('kind', self::FINAL)
            ->whereNull('document_id')
            ->update([
                'document_id' => $documentId,
                'completed_at' => now(),
                'status' => 'completed',
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function preview(User $actor, int $caseId): array
    {
        $case = $this->submittedCase($actor, $caseId);
        $payload = $this->drawPayload($case);

        if ($payload === null) {
            throw ValidationException::withMessages([
                'form' => 'The evaluation is not complete, so no preview was created.',
            ]);
        }

        return [
            'bytes' => $this->pdf->overlayFinalEvaluation($this->previewSource($case), $payload),
            'filename' => 'I983_Final_Evaluation_Preview.pdf',
        ];
    }

    public function storeSeparate(User $actor, int $caseId): void
    {
        $case = $this->submittedCase($actor, $caseId);
        $row = $this->row((int) $case->tenant_id, $caseId, self::FINAL);
        $existing = DB::table('i983_documents')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $caseId)
            ->where('kind', I983Documents::EVALUATION_FINAL)
            ->exists();

        if ($existing || ($row !== null && $row->document_id !== null)) {
            throw ValidationException::withMessages([
                'form' => 'The evaluation PDF is already stored. It was not replaced.',
            ]);
        }

        $final = DB::table('i983_documents')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $caseId)
            ->where('kind', I983Documents::FINAL_SIGNED)
            ->orderBy('id')
            ->first();

        if ($final === null) {
            throw ValidationException::withMessages([
                'form' => 'The evaluation is included when the final I-983 is first created. No separate PDF was stored.',
            ]);
        }

        $source = Storage::disk('local')->get((string) $final->file_path);
        $prepared = is_string($source) ? $this->applyToNewFinal($case, $source) : ['bytes' => '', 'overlaid' => false];

        if (! $prepared['overlaid']) {
            throw ValidationException::withMessages([
                'form' => 'The evaluation is not complete, so no PDF was stored.',
            ]);
        }

        $documentId = $this->documents->storeEvaluation($case, $prepared['bytes'], (int) $actor->id, (int) $final->id);
        $this->markStored($case, $documentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicView(object $case): array
    {
        $view = $this->present($case);

        return [
            'available' => true,
            'dateFrom' => $view['dateFrom'] === '' ? '' : Carbon::parse($view['dateFrom'])->format('m-d-Y'),
            'dateTo' => $view['dateTo'] === '' ? '' : Carbon::parse($view['dateTo'])->format('m-d-Y'),
            'narrative' => $view['narrative'],
            'employerName' => $view['employerPrintedName'],
            'employerTitle' => $view['employerTitle'],
            'employerSignedOn' => $view['employerSignedOn'],
            'studentName' => $view['studentName'],
            'studentSigned' => $view['studentSigned'],
            'studentSignedOn' => $this->signedOnLabel((int) $case->tenant_id, (int) $case->id),
            'employerSigned' => $view['employerSigned'],
            'datesReady' => $view['dateFrom'] !== '' && $view['dateTo'] !== '',
            'evaluationPdf' => $this->completedEvaluationDocument($case) !== null,
        ];
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function studentEvaluationFile(string $token): array
    {
        $link = $this->cases->openLink($token);
        abort_if($link === null, 404);
        $case = DB::table('i983_cases')->where('id', $link->case_id)->where('tenant_id', $link->tenant_id)->first();
        abort_if($case === null, 404);
        $document = $this->completedEvaluationDocument($case);
        abort_if($document === null, 404);
        $bytes = Storage::disk('local')->get((string) $document->file_path);
        abort_if(! is_string($bytes) || $bytes === '' || ! str_starts_with($bytes, '%PDF'), 404);
        $student = DB::table('i983_students')->where('tenant_id', $case->tenant_id)->where('case_id', $case->id)->first();
        $label = trim(trim((string) ($student->given_name ?? '')).' '.trim((string) ($student->surname ?? '')));

        if ($label === '') {
            $label = trim((string) $case->candidate_name);
        }

        $safe = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_');

        return [
            'bytes' => $bytes,
            'filename' => 'I983_'.($safe === '' ? 'Student' : $safe).'_Final_Evaluation.pdf',
        ];
    }

    public function saveStudent(string $token, array $input, ?UploadedFile $signature): void
    {
        $link = $this->cases->openLink($token);
        abort_if($link === null, 404);
        $case = DB::table('i983_cases')->where('id', $link->case_id)->where('tenant_id', $link->tenant_id)->first();
        abort_if($case === null || $case->student_submitted_at === null, 404);
        $narrative = trim((string) ($input['narrative'] ?? ''));

        if ($narrative === '') {
            throw ValidationException::withMessages([
                'narrative' => 'Write your own progress narrative. It is not generated for you.',
            ]);
        }

        $this->pdf->assertFinalNarrative($narrative);
        $created = null;

        try {
            DB::transaction(function () use ($case, $narrative, $signature, &$created): void {
                $row = $this->lockedRow((int) $case->tenant_id, (int) $case->id);

                if ($row !== null && ($this->filled($row->student_signature_path) || $row->completed_at !== null)) {
                    throw ValidationException::withMessages([
                        'narrative' => 'You already signed this evaluation. It was not changed.',
                    ]);
                }

                $student = DB::table('i983_students')->where('tenant_id', $case->tenant_id)->where('case_id', $case->id)->first();
                $from = $row === null ? '' : $this->iso($row->date_from);
                $to = $row === null ? '' : $this->iso($row->date_to);
                $prepared = null;

                if ($signature !== null) {
                    $printed = $this->sectionOneName($student);

                    if ($printed === '') {
                        throw ValidationException::withMessages([
                            'signature' => 'Your Section 1 name is not recorded, so the evaluation was not signed.',
                        ]);
                    }

                    if ($from === '' || $to === '') {
                        throw ValidationException::withMessages([
                            'signature' => 'Your employer has not saved both evaluation dates yet.',
                        ]);
                    }

                    $this->assertRange($from, $to, $this->iso($student->opt_start ?? null), $this->iso($student->opt_end ?? null));
                    $prepared = $this->certifications->privatePng($case, $signature);
                    $this->assertEvaluationSignatureUnused($case, $prepared['upload_sha256'], $prepared['png_sha256']);
                    $created = $this->signaturePath($case, 'student', 'png');
                    Storage::disk('local')->put($created, $prepared['png']);
                }

                $now = now();
                $values = [
                    'narrative' => $narrative,
                    'status' => $this->statusFromParts(
                        $from === '' ? null : $from,
                        $to === '' ? null : $to,
                        $narrative,
                        $prepared !== null,
                        $row !== null && $this->filled($row->employer_signature_path),
                        $row === null ? null : $row->completed_at,
                        $row === null ? null : $row->document_id,
                    ),
                    'updated_at' => $now,
                ];

                if ($prepared !== null) {
                    $values['student_printed_name'] = $this->sectionOneName($student);
                    $values['student_signature_path'] = $created;
                    $values['student_signed_on'] = now()->copy()->timezone($this->certifications->timezoneFor((int) $case->tenant_id))->toDateString();
                }

                if ($row === null) {
                    $this->insertFinal($case, [
                        ...$values,
                        'created_by' => null,
                        'created_at' => $now,
                    ], 'narrative');
                } else {
                    $updated = DB::table('i983_evaluations')
                        ->where('id', $row->id)
                        ->where('tenant_id', $case->tenant_id)
                        ->whereNull('student_signature_path')
                        ->whereNull('completed_at')
                        ->update($values);

                    if ($updated !== 1) {
                        throw ValidationException::withMessages([
                            'narrative' => 'You already signed this evaluation. It was not changed.',
                        ]);
                    }
                }

                DB::table('i983_events')->insert([
                    'tenant_id' => $case->tenant_id,
                    'case_id' => $case->id,
                    'actor_id' => null,
                    'actor_type' => 'student',
                    'action' => $prepared === null ? 'evaluation_narrative_saved' : 'evaluation_student_signed',
                    'subject' => self::FINAL,
                    'created_at' => $now,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->discardUnstoredSignature($created);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $case): array
    {
        $caseId = (int) $case->id;
        $tenantId = (int) $case->tenant_id;
        $student = DB::table('i983_students')->where('tenant_id', $tenantId)->where('case_id', $caseId)->first();
        $employer = DB::table('i983_employers')->where('tenant_id', $tenantId)->where('case_id', $caseId)->first();
        $training = DB::table('i983_training_plans')->where('tenant_id', $tenantId)->where('case_id', $caseId)->first();
        $row = $this->row($tenantId, $caseId, self::FINAL);
        $optEnd = $this->iso($student->opt_end ?? null);

        return [
            'caseId' => $caseId,
            'reference' => (string) $case->reference,
            'candidate' => (string) $case->candidate_name,
            'caseStatus' => (string) $case->status,
            'evaluationStatus' => $row === null ? 'pending' : $this->status($row),
            'studentName' => $this->sectionOneName($student),
            'employerName' => trim((string) ($employer->legal_name ?? '')),
            'supervisorName' => trim((string) ($training->official_name ?? '')),
            'supervisorTitle' => trim((string) ($training->official_title ?? '')),
            'optStart' => $this->iso($student->opt_start ?? null),
            'optEnd' => $optEnd,
            'employmentStart' => $this->iso($employer->start_date ?? null),
            'suggestedFrom' => $this->suggestedFrom($tenantId, $caseId),
            'suggestedTo' => $optEnd,
            'dateFrom' => $row === null ? '' : $this->iso($row->date_from),
            'dateTo' => $row === null ? '' : $this->iso($row->date_to),
            'narrative' => $row === null ? '' : trim((string) ($row->narrative ?? '')),
            'studentSigned' => $row !== null && $this->filled($row->student_signature_path),
            'employerSigned' => $row !== null && $this->filled($row->employer_signature_path),
            'documentId' => $row === null || $row->document_id === null ? null : (int) $row->document_id,
            'finalStored' => DB::table('i983_documents')->where('tenant_id', $tenantId)->where('case_id', $caseId)->where('kind', I983Documents::FINAL_SIGNED)->exists(),
            'employerPrintedName' => $row === null ? '' : trim((string) ($row->employer_printed_name ?? '')),
            'employerTitle' => $row === null ? '' : trim((string) ($row->employer_title ?? '')),
            'employerSignedOn' => $row === null || $row->employer_signed_on === null ? '' : Carbon::parse($row->employer_signed_on)->format('m-d-Y'),
        ];
    }

    private function submittedCase(User $actor, int $caseId): object
    {
        $case = $this->cases->managedCase($actor, $caseId);
        abort_if($case->student_submitted_at === null, 404);

        return $case;
    }

    private function row(int $tenantId, int $caseId, string $kind): ?object
    {
        return DB::table('i983_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('kind', $kind)
            ->first();
    }

    private function suggestedFrom(int $tenantId, int $caseId): string
    {
        $initial = DB::table('i983_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('kind', self::INITIAL)
            ->whereNotNull('completed_at')
            ->whereNotNull('date_to')
            ->first();

        if ($initial === null) {
            return '';
        }

        return Carbon::parse($initial->date_to)->addDay()->toDateString();
    }

    private function sectionOneName(?object $student): string
    {
        $surname = trim((string) ($student->surname ?? ''));
        $given = trim((string) ($student->given_name ?? ''));

        if ($surname === '') {
            return $given;
        }

        if ($given === '') {
            return $surname;
        }

        return $surname.', '.$given;
    }

    private function optionalDate(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withMessages([
                $key => 'Enter a real date.',
            ]);
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                $key => 'Enter a real date.',
            ]);
        }

        return $value;
    }

    private function assertRange(?string $from, ?string $to, string $optStart, string $optEnd): void
    {
        if ($from === null || $to === null || $from === '' || $to === '') {
            throw ValidationException::withMessages([
                'date_from' => 'Enter both the evaluation start and end dates.',
            ]);
        }

        if ($optStart === '' || $optEnd === '') {
            throw ValidationException::withMessages([
                'date_from' => 'Complete the candidate\'s STEM OPT start and end dates before saving an evaluation range.',
            ]);
        }

        $errors = [];

        if ($from < $optStart || $from > $optEnd) {
            $errors['date_from'] = 'The evaluation start must fall within the stored STEM OPT period, from '.$optStart.' through '.$optEnd.'.';
        }

        if ($to < $optStart || $to > $optEnd) {
            $errors['date_to'] = 'The evaluation end must fall within the stored STEM OPT period, from '.$optStart.' through '.$optEnd.'.';
        }

        if ($from > $to) {
            $errors['date_to'] = 'The evaluation end cannot be earlier than the start.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function status(object $row): string
    {
        return $this->statusFromParts(
            $this->iso($row->date_from ?? null) ?: null,
            $this->iso($row->date_to ?? null) ?: null,
            trim((string) ($row->narrative ?? '')),
            $this->filled($row->student_signature_path),
            $this->filled($row->employer_signature_path),
            $row->completed_at ?? null,
            $row->document_id ?? null,
        );
    }

    private function statusFromParts(?string $from, ?string $to, string $narrative, bool $studentSigned, bool $employerSigned, mixed $completedAt, mixed $documentId): string
    {
        if ($completedAt !== null && $documentId !== null) {
            return 'completed';
        }

        if ($narrative !== '' && $from !== null && $to !== null && $studentSigned) {
            return 'ready_for_signature';
        }

        if ($from !== null || $to !== null || $narrative !== '' || $studentSigned || $employerSigned) {
            return 'in_progress';
        }

        return 'pending';
    }

    private function iso(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->toDateString();
    }

    /**
     * @return array{date_from: string, date_to: string, narrative: string, student_name: string, student_date: string, student_image: string, employer_name: string, employer_date: string, employer_image: string}|null
     */
    private function drawPayload(object $case): ?array
    {
        $row = $this->row((int) $case->tenant_id, (int) $case->id, self::FINAL);

        if ($row === null) {
            return null;
        }

        $narrative = trim((string) ($row->narrative ?? ''));
        $from = $this->iso($row->date_from ?? null);
        $to = $this->iso($row->date_to ?? null);
        $studentName = trim((string) ($row->student_printed_name ?? ''));
        $employerName = trim((string) ($row->employer_printed_name ?? ''));
        $title = trim((string) ($row->employer_title ?? ''));

        if ($row === null || $narrative === '' || $from === '' || $to === '' || $studentName === '' || $employerName === '' || $row->student_signed_on === null || $row->employer_signed_on === null || ! $this->filled($row->student_signature_path) || ! $this->filled($row->employer_signature_path)) {
            return null;
        }

        $studentImage = Storage::disk('local')->path((string) $row->student_signature_path);
        $employerImage = Storage::disk('local')->path((string) $row->employer_signature_path);

        if (! is_file($studentImage) || ! is_file($employerImage)) {
            throw ValidationException::withMessages([
                'form' => 'A stored evaluation signature is missing, so no PDF was written.',
            ]);
        }

        return [
            'date_from' => Carbon::parse($from)->format('m-d-Y'),
            'date_to' => Carbon::parse($to)->format('m-d-Y'),
            'narrative' => $narrative,
            'student_name' => $studentName,
            'student_date' => Carbon::parse($row->student_signed_on)->format('m-d-Y'),
            'student_image' => $studentImage,
            'employer_name' => $title === '' ? $employerName : $employerName.', '.$title,
            'employer_date' => Carbon::parse($row->employer_signed_on)->format('m-d-Y'),
            'employer_image' => $employerImage,
        ];
    }

    private function previewSource(object $case): string
    {
        $final = DB::table('i983_documents')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->where('kind', I983Documents::FINAL_SIGNED)
            ->orderBy('id')
            ->value('file_path');
        $signed = $final === null
            ? DB::table('i983_documents')
                ->where('tenant_id', $case->tenant_id)
                ->where('case_id', $case->id)
                ->where('kind', I983Documents::EMPLOYER_SIGNED)
                ->orderByDesc('id')
                ->value('file_path')
            : $final;
        $bytes = is_string($signed) ? Storage::disk('local')->get($signed) : null;

        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            $official = file_get_contents(storage_path('app/private/i983/official-i983.pdf'));
            $bytes = is_string($official) ? $official : '';
        }

        if (! str_starts_with($bytes, '%PDF')) {
            throw ValidationException::withMessages([
                'form' => 'The official I-983 form is not available, so no preview was created.',
            ]);
        }

        return $bytes;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function signedOnLabel(int $tenantId, int $caseId): string
    {
        $signed = DB::table('i983_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('kind', self::FINAL)
            ->value('student_signed_on');

        return $signed === null || $signed === '' ? '' : Carbon::parse($signed)->format('m-d-Y');
    }

    private function assertEvaluationSignatureUnused(object $case, string $uploadHash, string $pngHash): void
    {
        $prefix = 'i983/'.$case->tenant_id.'/';
        $paths = DB::table('i983_evaluations')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', '!=', $case->id)
            ->whereNotNull('student_signature_path')
            ->pluck('student_signature_path');

        foreach ($paths as $path) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                continue;
            }

            $bytes = Storage::disk('local')->get($path);

            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            $hash = hash('sha256', $bytes);

            if (hash_equals($hash, $uploadHash) || hash_equals($hash, $pngHash)) {
                throw ValidationException::withMessages([
                    'signature' => 'This signature is already stored on another I-983. Draw your own signature.',
                ]);
            }
        }
    }

    private function lockedRow(int $tenantId, int $caseId): ?object
    {
        $query = DB::table('i983_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('case_id', $caseId)
            ->where('kind', self::FINAL);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertFinal(object $case, array $values, string $errorKey): void
    {
        try {
            DB::table('i983_evaluations')->insert([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'kind' => self::FINAL,
                ...$values,
            ]);
        } catch (QueryException $exception) {
            if (! $this->duplicateEvaluation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $errorKey => 'This evaluation was already saved. The existing record was not changed.',
            ]);
        }
    }

    private function duplicateEvaluation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');
        $message = $exception->getMessage();

        return in_array($state, ['23000', '23505'], true)
            && (str_contains($message, 'UNIQUE constraint failed') || str_contains($message, 'Duplicate entry'));
    }

    private function signaturePath(object $case, string $role, string $extension): string
    {
        return 'i983/'.$case->tenant_id.'/'.$case->id.'/evaluation-'.$role.'-'.bin2hex(random_bytes(16)).'.'.$extension;
    }

    private function discardUnstoredSignature(?string $path): void
    {
        if (! is_string($path) || preg_match('#^i983/\d+/\d+/evaluation-(student|employer)-[a-f0-9]{32}\.(png|jpe?g)$#', $path) !== 1) {
            return;
        }

        try {
            $stored = DB::table('i983_evaluations')
                ->where('student_signature_path', $path)
                ->orWhere('employer_signature_path', $path)
                ->exists();
        } catch (\Throwable) {
            $stored = false;
        }

        if (! $stored) {
            Storage::disk('local')->delete($path);
        }
    }

    private function completedEvaluationDocument(object $case): ?object
    {
        $row = $this->row((int) $case->tenant_id, (int) $case->id, self::FINAL);

        if ($row === null || $row->completed_at === null || $row->document_id === null || ! $this->filled($row->employer_signature_path)) {
            return null;
        }

        return DB::table('i983_documents')
            ->where('id', $row->document_id)
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->where('kind', I983Documents::EVALUATION_FINAL)
            ->first();
    }
}
