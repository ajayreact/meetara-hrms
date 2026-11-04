<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UsaOfferLetters
{
    public function __construct(
        private readonly UsaDesignationCatalog $catalog,
        private readonly UsaOfferPdf $pdf,
        private readonly StoredFileQuarantine $quarantine,
    ) {}

    /**
     * @return array{letterhead: bool, letterheadName: string, signature: bool, signatureName: string}
     */
    public function stationery(int $companyId): array
    {
        $this->ensureProfile($companyId);
        $row = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->first();

        if ($row === null) {
            return ['letterhead' => false, 'letterheadName' => '', 'signature' => false, 'signatureName' => ''];
        }

        return [
            'letterhead' => $this->privateFile($companyId, $row->letterhead_path ?? null),
            'letterheadName' => (string) ($row->letterhead_name ?? ''),
            'signature' => $this->privateFile($companyId, $row->signature_path ?? null),
            'signatureName' => (string) ($row->signature_name ?? ''),
        ];
    }

    public function saveStationery(int $companyId, string $kind, UploadedFile $file): void
    {
        $this->ensureProfile($companyId);
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = file_get_contents($file->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([$kind => 'The file could not be read.']);
        }

        if ($extension === 'pdf') {
            if (! class_exists(\Imagick::class)) {
                throw ValidationException::withMessages([
                    'letterhead' => 'Save the letterhead as a PNG or JPEG. This server cannot paint a PDF letterhead.',
                ]);
            }

            $image = new \Imagick;
            $image->readImageBlob($contents);
            $image->setIteratorIndex(0);
            $image->setImageFormat('png');
            $contents = $image->getImageBlob();
            $extension = 'png';
        }

        $path = 'usa-offers/'.$companyId.'/stationery/'.$kind.'.'.$extension;
        $previous = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->value($kind.'_path');
        Storage::disk('local')->put($path, $contents);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->update([
            $kind.'_path' => $path,
            $kind.'_name' => $file->getClientOriginalName(),
            'updated_at' => now(),
        ]);
    }

    public function stationeryFile(int $companyId, string $kind): string
    {
        $column = $kind.'_path';
        $path = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->value($column);
        abort_unless($this->privateFile($companyId, $path), 404);

        return (string) Storage::disk('local')->get((string) $path);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function requireStationery(int $companyId): array
    {
        $letterhead = $this->absoluteStationery($companyId, 'letterhead_path');
        $signature = $this->absoluteStationery($companyId, 'signature_path');

        if ($letterhead === null || $signature === null) {
            throw ValidationException::withMessages([
                'stationery' => 'Upload a company letterhead and an HR signature before previewing or generating the PDF.',
            ]);
        }

        return [$letterhead, $signature];
    }

    private function absoluteStationery(int $companyId, string $column): ?string
    {
        $path = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->value($column);

        if (! $this->privateFile($companyId, $path)) {
            return null;
        }

        return Storage::disk('local')->path((string) $path);
    }

    private function privateFile(int $companyId, mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'usa-offers/'.$companyId.'/stationery/')
            && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(int $companyId): array
    {
        $this->ensureProfile($companyId);
        $row = DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->first();

        return $this->profileArray($row);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveProfile(int $companyId, array $input): void
    {
        $this->ensureProfile($companyId);
        DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->update([
            ...$this->profileColumns($input),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $companyId): array
    {
        if (! Schema::hasTable('usa_offers')) {
            return [];
        }

        return DB::table('usa_offers')
            ->where('tenant_id', $companyId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'candidate' => (string) $row->candidate_name,
                'email' => (string) ($row->candidate_email ?? ''),
                'designation' => (string) $row->designation_name,
                'payType' => (string) $row->pay_type,
                'payAmount' => number_format((float) $row->pay_amount, 2, '.', ''),
                'workingHours' => (string) $row->working_hours,
                'startDate' => (string) $row->start_date,
                'issueDate' => (string) $row->issue_date,
                'status' => (string) $row->status,
                'createdAt' => substr((string) $row->created_at, 0, 10),
                'templateVersion' => (string) $row->template_version,
            ])
            ->all();
    }

    public function remove(User $actor, int $offerId): void
    {
        abort_unless($actor->applicationRole() === 'company_admin', 403);
        $companyId = (int) $actor->companyId();
        $offer = DB::table('usa_offers')->where('tenant_id', $companyId)->where('id', $offerId)->first();
        abort_if($offer === null, 404);

        $path = 'usa-offers/'.$companyId.'/'.$offerId.'.pdf';
        $paths = (string) ($offer->file_path ?? '') === $path ? [$path] : [];

        try {
            $this->quarantine->commit($paths, function () use ($companyId, $offerId): void {
                $deleted = DB::table('usa_offers')->where('tenant_id', $companyId)->where('id', $offerId)->delete();
                abort_if($deleted !== 1, 404);
            });
        } catch (\Throwable $exception) {
            if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'delete' => 'The USA offer was not deleted. Its PDF is unchanged.',
            ]);
        }
    }

    public function create(User $actor, array $input): int
    {
        $companyId = (int) $actor->companyId();
        $payload = $this->payload($companyId, $input);

        return (int) DB::table('usa_offers')->insertGetId([
            ...$payload,
            'tenant_id' => $companyId,
            'profile_snapshot' => null,
            'template_version' => UsaDesignationCatalog::TEMPLATE_VERSION,
            'status' => 'draft',
            'file_path' => null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, int $offerId, array $input): void
    {
        $offer = $this->offer((int) $actor->companyId(), $offerId);
        $this->assertDraft($offer);
        DB::table('usa_offers')->where('id', $offer->id)->update([
            ...$this->payload((int) $offer->tenant_id, $input),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(int $companyId, int $offerId): array
    {
        $offer = $this->offer($companyId, $offerId);

        return [
            'id' => (int) $offer->id,
            'designationId' => $offer->designation_id === null ? '' : (string) $offer->designation_id,
            'designationName' => (string) $offer->designation_name,
            'responsibilities' => $this->items($offer->responsibilities),
            'candidateName' => (string) $offer->candidate_name,
            'candidateEmail' => (string) ($offer->candidate_email ?? ''),
            'candidatePhone' => (string) ($offer->candidate_phone ?? ''),
            'candidateAddress' => (string) ($offer->candidate_address ?? ''),
            'employmentType' => (string) ($offer->employment_type ?? ''),
            'payType' => (string) $offer->pay_type,
            'payAmount' => (string) $offer->pay_amount,
            'workingHours' => (string) $offer->working_hours,
            'workArrangement' => (string) $offer->work_arrangement,
            'university' => (string) $offer->university,
            'startDate' => (string) $offer->start_date,
            'issueDate' => (string) $offer->issue_date,
            'status' => (string) $offer->status,
            'templateVersion' => (string) $offer->template_version,
            'locked' => $offer->status === 'generated',
        ];
    }

    public function preview(int $companyId, int $offerId): string
    {
        $offer = $this->offer($companyId, $offerId);

        if ($offer->status === 'generated' && is_string($offer->file_path) && $offer->file_path !== '') {
            return $this->stored($offer->file_path);
        }

        return $this->pdf->render($this->document($offer), $this->profile($companyId), ...$this->requireStationery($companyId));
    }

    public function generate(User $actor, int $offerId): void
    {
        $companyId = (int) $actor->companyId();
        $offer = $this->offer($companyId, $offerId);
        $this->assertDraft($offer);
        $profile = $this->profile($companyId);
        $document = $this->document($offer);
        $bytes = $this->pdf->render($document, $profile, ...$this->requireStationery($companyId));
        $path = 'usa-offers/'.$companyId.'/'.$offer->id.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        try {
            DB::transaction(function () use ($offer, $profile, $path): void {
                $updated = DB::table('usa_offers')
                    ->where('id', $offer->id)
                    ->where('status', 'draft')
                    ->update([
                        'profile_snapshot' => json_encode($profile, JSON_THROW_ON_ERROR),
                        'template_version' => UsaDesignationCatalog::TEMPLATE_VERSION,
                        'status' => 'generated',
                        'file_path' => $path,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    throw ValidationException::withMessages(['offer' => 'This offer was already generated.']);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    public function download(int $companyId, int $offerId): string
    {
        $offer = $this->offer($companyId, $offerId);

        if ($offer->status !== 'generated' || ! is_string($offer->file_path) || $offer->file_path === '') {
            abort(404);
        }

        return $this->stored($offer->file_path);
    }

    private function offer(int $companyId, int $offerId): object
    {
        abort_unless(Schema::hasTable('usa_offers'), 404);
        $offer = DB::table('usa_offers')->where('tenant_id', $companyId)->where('id', $offerId)->first();
        abort_if($offer === null, 404);

        return $offer;
    }

    private function assertDraft(object $offer): void
    {
        if ($offer->status !== 'draft') {
            throw ValidationException::withMessages(['offer' => 'A generated offer keeps its saved responsibilities and cannot be changed.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(int $companyId, array $input): array
    {
        $designation = $this->catalog->active($companyId, (int) $input['designation_id']);
        $responsibilities = $this->lines($input['responsibilities'] ?? []);

        if ($responsibilities === []) {
            throw ValidationException::withMessages(['responsibilities' => 'Enter at least one responsibility.']);
        }

        return [
            'designation_id' => (int) $designation->id,
            'designation_name' => (string) $designation->name,
            'responsibilities' => json_encode($responsibilities, JSON_THROW_ON_ERROR),
            'candidate_name' => trim((string) $input['candidate_name']),
            'candidate_email' => $this->nullable($input['candidate_email'] ?? null),
            'candidate_phone' => $this->nullable($input['candidate_phone'] ?? null),
            'candidate_address' => $this->nullable($input['candidate_address'] ?? null),
            'pay_type' => (string) $input['pay_type'],
            'pay_amount' => number_format((float) $input['pay_amount'], 2, '.', ''),
            'working_hours' => trim((string) $input['working_hours']),
            'work_arrangement' => trim((string) $input['work_arrangement']),
            'university' => trim((string) $input['university']),
            'start_date' => (string) $input['start_date'],
            'issue_date' => (string) $input['issue_date'],
        ] + (Schema::hasColumn('usa_offers', 'employment_type') ? [
            'employment_type' => $this->nullable($input['employment_type'] ?? null),
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(object $offer): array
    {
        return [
            'designation_name' => (string) $offer->designation_name,
            'responsibilities' => $this->items($offer->responsibilities),
            'candidate_name' => (string) $offer->candidate_name,
            'candidate_email' => (string) ($offer->candidate_email ?? ''),
            'candidate_phone' => (string) ($offer->candidate_phone ?? ''),
            'candidate_address' => (string) ($offer->candidate_address ?? ''),
            'employment_type' => (string) ($offer->employment_type ?? ''),
            'pay_type' => (string) $offer->pay_type,
            'pay_amount' => (string) $offer->pay_amount,
            'working_hours' => (string) $offer->working_hours,
            'work_arrangement' => (string) $offer->work_arrangement,
            'university' => (string) $offer->university,
            'start_date' => (string) $offer->start_date,
            'issue_date' => (string) $offer->issue_date,
        ];
    }

    /**
     * @return list<string>
     */
    private function items(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @return list<string>
     */
    private function lines(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $line) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $line) ?? '');

            if ($text !== '') {
                $lines[] = mb_substr($text, 0, 500);
            }
        }

        return array_slice($lines, 0, 40);
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function ensureProfile(int $companyId): void
    {
        if (! Schema::hasTable('usa_offer_profiles')) {
            return;
        }

        if (DB::table('usa_offer_profiles')->where('tenant_id', $companyId)->exists()) {
            return;
        }

        $name = (string) (DB::table('tenants')->where('id', $companyId)->value('name') ?: 'Company');
        DB::table('usa_offer_profiles')->insert([
            'tenant_id' => $companyId,
            'company_name' => $name,
            'country_line' => 'United States',
            'template_version' => UsaDesignationCatalog::TEMPLATE_VERSION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    private function profileColumns(array $input): array
    {
        $columns = [];

        foreach ([
            'company_name', 'address_line', 'city_line', 'country_line', 'website', 'email', 'phone',
            'e_verify', 'federal_id', 'manager_name', 'manager_title', 'manager_email', 'manager_phone',
            'hr_name', 'hr_title', 'hr_phone',
        ] as $column) {
            $columns[$column] = $this->nullable($input[$column] ?? null);
        }

        $columns['company_name'] = $columns['company_name'] ?: 'Company';
        $columns['country_line'] = $columns['country_line'] ?: 'United States';

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileArray(?object $row): array
    {
        if ($row === null) {
            return ['company_name' => '', 'country_line' => 'United States', 'template_version' => UsaDesignationCatalog::TEMPLATE_VERSION];
        }

        return [
            'company_name' => (string) $row->company_name,
            'address_line' => (string) ($row->address_line ?? ''),
            'city_line' => (string) ($row->city_line ?? ''),
            'country_line' => (string) ($row->country_line ?? ''),
            'website' => (string) ($row->website ?? ''),
            'email' => (string) ($row->email ?? ''),
            'phone' => (string) ($row->phone ?? ''),
            'e_verify' => (string) ($row->e_verify ?? ''),
            'federal_id' => (string) ($row->federal_id ?? ''),
            'manager_name' => (string) ($row->manager_name ?? ''),
            'manager_title' => (string) ($row->manager_title ?? ''),
            'manager_email' => (string) ($row->manager_email ?? ''),
            'manager_phone' => (string) ($row->manager_phone ?? ''),
            'hr_name' => (string) ($row->hr_name ?? ''),
            'hr_title' => (string) ($row->hr_title ?? ''),
            'hr_phone' => (string) ($row->hr_phone ?? ''),
            'template_version' => (string) $row->template_version,
        ];
    }

    private function stored(string $path): string
    {
        abort_unless(Storage::disk('local')->exists($path), 404);
        abort_if(str_contains($path, '..'), 404);

        return (string) Storage::disk('local')->get($path);
    }
}
