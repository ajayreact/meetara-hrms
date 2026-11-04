<?php

namespace App\Services\Hrms;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class I983StudentCertifications
{
    /**
     * The five statements already printed on Form I-983 (7/16) Section 2.
     *
     * @var list<string>
     */
    public const STATEMENTS = [
        'I have reviewed, understand, and will adhere to this Training Plan for STEM OPT Students ("Plan");',
        'I will notify the DSO at the earliest available opportunity if I believe that my employer is not providing me with appropriate training as delineated on this Plan;',
        'I understand that the Department of Homeland Security (DHS) may deny, revoke, or terminate the STEM OPT of students whom DHS determines are not engaging in OPT in compliance with the law, including the STEM OPT of students who are not, or whose employers are not, complying with this Plan;',
        'My practical training opportunity is directly related to the STEM degree that qualifies me for the STEM OPT extension; and',
        'I will notify the DSO at the earliest available opportunity regarding any material changes to or deviations from this Plan, including but not limited to, any change of Employer Identification Number resulting from a corporate restructuring, any nontrivial reduction in compensation from the amount previously submitted on the Plan that is not tied to a reduction in hours worked, any significant decrease in hours per week that I engage in a STEM training opportunity, and any decrease in hours below the 20-hours-per-week minimum required under this rule.',
    ];

    public function __construct(private readonly I983Pdf $pdf) {}

    public static function statementsHash(): string
    {
        return hash('sha256', implode("\n", self::STATEMENTS));
    }

    public function timezoneFor(int $tenantId): string
    {
        $timezone = DB::table('tenants')->where('id', $tenantId)->value('timezone');

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        $fallback = (string) config('app.timezone');

        return in_array($fallback, timezone_identifiers_list(), true) ? $fallback : 'UTC';
    }

    public function localDate(int $tenantId, ?Carbon $instant = null): string
    {
        return ($instant ?? now())->copy()->timezone($this->timezoneFor($tenantId))->format('m-d-Y');
    }

    public function rememberDraft(object $case, UploadedFile $file): void
    {
        $upload = $this->png($file);
        $this->assertReusable($case, $upload['upload_sha256'], $upload['png_sha256']);
        $path = $this->draftPath($case);

        try {
            Storage::disk('local')->put($path, $upload['png']);
            Storage::disk('local')->put($this->draftHashPath($case), $upload['png_sha256']);
        } catch (\Throwable $exception) {
            $this->forgetDraft($case);

            throw $exception;
        }
    }

    public function forgetDraft(object $case): void
    {
        Storage::disk('local')->delete([$this->draftPath($case), $this->draftHashPath($case)]);
    }

    public function draftUpload(object $case): ?UploadedFile
    {
        if ($this->verifiedDraft($case) === null) {
            return null;
        }

        return new UploadedFile(Storage::disk('local')->path($this->draftPath($case)), 'student-signature.png', 'image/png', null, true);
    }

    public function storedSignatureBytes(object $case): ?string
    {
        if ($case->student_submitted_at !== null) {
            return $this->certificationBytes($case) ?? $this->verifiedDraft($case);
        }

        return $this->verifiedDraft($case);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{path: string, version: int}
     */
    public function record(object $case, object $link, string $printedName, string $studentEmail, array $input): array
    {
        $printedName = trim($printedName);

        if ($printedName === '') {
            throw ValidationException::withMessages([
                'student_printed_name' => 'Confirm the printed name from Section 1 before certifying.',
            ]);
        }

        if (! $this->accepted($input['confirm_certification'] ?? null) || ! $this->accepted($input['confirm_name'] ?? null)) {
            throw ValidationException::withMessages([
                'confirm_certification' => 'Confirm the Section 2 statements and the printed name before submitting.',
            ]);
        }

        $file = $input['signature'] ?? null;

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'signature' => 'Draw your signature before submitting. A typed name is not accepted.',
            ]);
        }

        $upload = $this->png($file);
        $this->assertReusable($case, $upload['upload_sha256'], $upload['png_sha256']);
        $version = ((int) DB::table('i983_student_certifications')->where('case_id', $case->id)->max('version')) + 1;
        $path = 'i983/'.$case->tenant_id.'/'.$case->id.'/student-signature-v'.$version.'.png';
        Storage::disk('local')->put($path, $upload['png']);

        try {
            $instant = now();
            $timezone = $this->timezoneFor((int) $case->tenant_id);
            $certifiedOn = $instant->copy()->timezone($timezone)->toDateString();
            $payload = [
                'image' => Storage::disk('local')->path($path),
                'printed_name' => $printedName,
                'date' => Carbon::parse($certifiedOn)->format('m-d-Y'),
            ];
            $this->pdf->assertStudentCertificationFits($payload);
            DB::table('i983_student_certifications')->insert([
                'tenant_id' => $case->tenant_id,
                'case_id' => $case->id,
                'link_id' => $link->id,
                'version' => $version,
                'printed_name' => $printedName,
                'student_email' => $studentEmail,
                'acknowledged' => true,
                'statements_sha256' => self::statementsHash(),
                'certified_on' => $certifiedOn,
                'certified_at' => $instant,
                'timezone' => $timezone,
                'signature_path' => $path,
                'signature_sha256' => $upload['png_sha256'],
                'upload_sha256' => $upload['upload_sha256'],
                'created_at' => $instant,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return ['path' => $path, 'version' => $version];
    }

    /**
     * @return array{image: string, printed_name: string, date: string}|null
     */
    public function pdfPayload(object $case): ?array
    {
        if (! Schema::hasTable('i983_student_certifications')) {
            return null;
        }

        $row = DB::table('i983_student_certifications')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            return null;
        }

        $expected = 'i983/'.$case->tenant_id.'/'.$case->id.'/student-signature-v'.$row->version.'.png';
        $path = (string) $row->signature_path;
        $bytes = $path === $expected ? Storage::disk('local')->get($path) : null;

        if (! is_string($bytes) || $bytes === '' || hash('sha256', $bytes) !== (string) $row->signature_sha256) {
            throw ValidationException::withMessages([
                'signature' => 'The stored student signature could not be read, so a new PDF was not generated. Earlier PDFs were not changed.',
            ]);
        }

        return [
            'image' => Storage::disk('local')->path($path),
            'printed_name' => (string) $row->printed_name,
            'date' => Carbon::parse((string) $row->certified_on)->format('m-d-Y'),
        ];
    }

    /**
     * @return array{latest: array<string, mixed>|null, history: list<array<string, mixed>>}
     */
    public function present(int $caseId): array
    {
        if (! Schema::hasTable('i983_student_certifications')) {
            return ['latest' => null, 'history' => []];
        }

        $history = DB::table('i983_student_certifications')
            ->where('case_id', $caseId)
            ->orderBy('version')
            ->get()
            ->map(fn (object $row): array => [
                'version' => (int) $row->version,
                'printedName' => (string) $row->printed_name,
                'certifiedOn' => Carbon::parse((string) $row->certified_on)->format('m-d-Y'),
                'certifiedAt' => (string) $row->certified_at,
                'timezone' => (string) $row->timezone,
                'acknowledged' => (bool) $row->acknowledged,
                'linkId' => (int) $row->link_id,
            ])
            ->all();

        return [
            'latest' => $history === [] ? null : $history[array_key_last($history)],
            'history' => $history,
        ];
    }

    /**
     * @return array{png: string, png_sha256: string, upload_sha256: string}
     */
    private function png(UploadedFile $file): array
    {
        if ($file->getSize() === false || $file->getSize() > 1024 * 1024) {
            throw ValidationException::withMessages([
                'signature' => 'The signature image must be a PNG or JPEG under 1 MB.',
            ]);
        }

        $source = $file->getRealPath();
        $info = is_string($source) ? @getimagesize($source) : false;

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages([
                'signature' => 'Draw your signature in the box. A typed name is not accepted.',
            ]);
        }

        if ($info[0] < 20 || $info[1] < 10 || $info[0] > 2000 || $info[1] > 600) {
            throw ValidationException::withMessages([
                'signature' => 'Draw the signature inside the box.',
            ]);
        }

        $bytes = is_string($source) ? file_get_contents($source) : false;
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;

        if (! is_string($bytes) || $bytes === '' || $image === false) {
            throw ValidationException::withMessages([
                'signature' => 'Draw your signature before submitting. A typed name is not accepted.',
            ]);
        }

        if (! $this->hasInk($image)) {
            imagedestroy($image);

            throw ValidationException::withMessages([
                'signature' => 'Draw your signature before submitting. A blank box is not accepted.',
            ]);
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        if (! is_string($png) || $png === '') {
            throw ValidationException::withMessages([
                'signature' => 'The signature could not be stored. Draw it again.',
            ]);
        }

        return [
            'png' => $png,
            'png_sha256' => hash('sha256', $png),
            'upload_sha256' => hash('sha256', $bytes),
        ];
    }

    private function hasInk(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ink = 0;
        $stepX = max(1, (int) floor($width / 200));
        $stepY = max(1, (int) floor($height / 80));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $colors = imagecolorsforindex($image, imagecolorat($image, $x, $y));

                if (($colors['alpha'] ?? 0) > 100) {
                    continue;
                }

                if ($colors['red'] < 245 || $colors['green'] < 245 || $colors['blue'] < 245) {
                    $ink++;
                }
            }
        }

        return $ink >= 8;
    }

    private function assertReusable(object $case, string $uploadHash, string $pngHash): void
    {
        foreach ($this->employerHashes((int) $case->tenant_id) as $hash) {
            if (hash_equals($hash, $uploadHash) || hash_equals($hash, $pngHash)) {
                throw ValidationException::withMessages([
                    'signature' => 'Use your own signature. The employer signature cannot be reused here.',
                ]);
            }
        }

        $reused = DB::table('i983_student_certifications')
            ->where('case_id', '!=', $case->id)
            ->where(function ($query) use ($uploadHash, $pngHash): void {
                $query->whereIn('upload_sha256', [$uploadHash, $pngHash])
                    ->orWhereIn('signature_sha256', [$uploadHash, $pngHash]);
            })
            ->exists();

        if ($reused) {
            throw ValidationException::withMessages([
                'signature' => 'This signature is already stored on another I-983. Draw your own signature.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function employerHashes(int $tenantId): array
    {
        $hashes = [];
        $paths = [];
        $signatory = Schema::hasTable('i983_signatories')
            ? DB::table('i983_signatories')->where('tenant_id', $tenantId)->value('image_path')
            : null;

        if (is_string($signatory) && $signatory !== '') {
            $paths[] = $signatory;
        }

        if (Schema::hasTable('i983_certifications')) {
            foreach (DB::table('i983_certifications')->where('tenant_id', $tenantId)->pluck('signature_path') as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }
        }

        foreach ($paths as $path) {
            $bytes = Storage::disk('local')->get($path);

            if (is_string($bytes) && $bytes !== '') {
                $hashes[] = hash('sha256', $bytes);
            }
        }

        return $hashes;
    }

    private function accepted(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }

    private function draftPath(object $case): string
    {
        return 'i983/'.$case->tenant_id.'/'.$case->id.'/student-signature-draft.png';
    }

    private function draftHashPath(object $case): string
    {
        return $this->draftPath($case).'.sha256';
    }

    private function verifiedDraft(object $case): ?string
    {
        $path = $this->draftPath($case);
        $bytes = Storage::disk('local')->get($path);
        $hash = Storage::disk('local')->get($this->draftHashPath($case));

        if (! is_string($bytes) || $bytes === '' || ! is_string($hash) || ! hash_equals(trim($hash), hash('sha256', $bytes))) {
            return null;
        }

        if (! str_starts_with($bytes, "\x89PNG")) {
            return null;
        }

        return $bytes;
    }

    private function certificationBytes(object $case): ?string
    {
        if (! Schema::hasTable('i983_student_certifications')) {
            return null;
        }

        $row = DB::table('i983_student_certifications')
            ->where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            return null;
        }

        $expected = 'i983/'.$case->tenant_id.'/'.$case->id.'/student-signature-v'.$row->version.'.png';
        $path = (string) $row->signature_path;
        $bytes = $path === $expected ? Storage::disk('local')->get($path) : null;

        if (! is_string($bytes) || $bytes === '' || ! hash_equals((string) $row->signature_sha256, hash('sha256', $bytes))) {
            return null;
        }

        return $bytes;
    }
}
