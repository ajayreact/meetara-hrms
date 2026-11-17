<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\IdCardCompanyProfile;
use App\Models\StandaloneIdCard;
use App\Models\StandaloneIdCardImportBatch;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StandaloneIdCardImport
{
    private const SHEET = 'Sheet1';

    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'full name' => 'full_name',
        'name' => 'full_name',
        'card number' => 'card_number',
        'card no' => 'card_number',
        'designation' => 'designation',
        'department' => 'department',
        'email' => 'email',
        'phone number' => 'phone',
        'phone' => 'phone',
        'blood group' => 'blood_group',
        'emergency contact' => 'emergency_contact_number',
        'emergency contact number' => 'emergency_contact_number',
        'country' => 'country',
    ];

    public function __construct(
        private readonly SpreadsheetTable $sheets,
        private readonly StandaloneIdCardIssuer $issuer,
        private readonly StandaloneIdCardImportTemplate $template,
    ) {}

    public function assertImporter(User $actor): void
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['idCardsManage'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        if (! Schema::hasTable('standalone_id_card_import_batches') || ! Schema::hasTable('standalone_id_cards')) {
            abort(404);
        }
    }

    /**
     * @return array{name: string, type: string, body: string}
     */
    public function templateDownload(User $actor): array
    {
        $this->assertImporter($actor);

        return $this->template->download();
    }

    public function stage(User $actor, UploadedFile $file): StandaloneIdCardImportBatch
    {
        $this->assertImporter($actor);
        $tenantId = (int) $actor->companyId();
        $this->assertXlsx($file);
        $preview = $this->classify($tenantId, $this->table($file->getRealPath() ?: $file->getPathname()));
        $path = $file->storeAs('id-cards/'.$tenantId.'/standalone/imports', Str::uuid()->toString().'.xlsx', 'local');

        if (! is_string($path) || $path === '') {
            throw new StandaloneIdCardImportRejected('The uploaded file could not be stored.');
        }

        try {
            $batch = new StandaloneIdCardImportBatch;
            $batch->forceFill([
                'tenant_id' => $tenantId,
                'original_name' => $this->originalName($file),
                'stored_path' => $path,
                'uploaded_by' => $actor->id,
                'status' => 'preview',
                'row_count' => $preview['rowCount'],
                'created_count' => $preview['createdCount'],
                'duplicate_count' => $preview['duplicateCount'],
                'failed_count' => $preview['failedCount'],
            ])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return $batch->refresh();
    }

    public function find(User $actor, int $batchId): StandaloneIdCardImportBatch
    {
        $this->assertImporter($actor);
        $batch = StandaloneIdCardImportBatch::query()->inCompany((int) $actor->companyId())->whereKey($batchId)->first();
        abort_if($batch === null, 404);

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $actor, StandaloneIdCardImportBatch $batch): array
    {
        $this->assertImporter($actor);
        abort_unless((int) $batch->tenant_id === (int) $actor->companyId(), 404);
        $preview = $this->classify(
            (int) $batch->tenant_id,
            $this->table($this->absolute($batch)),
            $batch->status === 'imported' ? (int) $batch->id : null,
        );
        $stored = $batch->status === 'imported';
        $uploader = $batch->uploader;
        $uploaderName = $uploader !== null && (int) $uploader->tenant_id === (int) $batch->tenant_id ? $uploader->name : null;

        return [
            'id' => (int) $batch->id,
            'fileName' => $batch->original_name,
            'status' => $stored ? 'Imported' : 'Ready to import',
            'rawStatus' => $batch->status,
            'uploadedBy' => $uploaderName,
            'uploadedAt' => $batch->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            'rowCount' => $stored ? (int) $batch->row_count : $preview['rowCount'],
            'createdCount' => $stored ? (int) $batch->created_count : $preview['createdCount'],
            'duplicateCount' => $stored ? (int) $batch->duplicate_count : $preview['duplicateCount'],
            'failedCount' => $stored ? (int) $batch->failed_count : $preview['failedCount'],
            'canConfirm' => $batch->status === 'preview' && $preview['createdCount'] > 0,
            'truncated' => count($preview['rows']) > 150,
            'rows' => array_map(fn (array $row): array => [
                'line' => $row['line'],
                'outcome' => $row['outcome'],
                'reason' => $row['reason'],
                'fullName' => $row['fullName'],
                'cardNumber' => $row['cardNumber'],
                'designation' => $row['designation'],
                'department' => $row['department'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'bloodGroup' => $row['bloodGroup'],
                'emergencyContactNumber' => $row['emergencyContactNumber'],
                'country' => $row['country'],
            ], array_slice($this->previewRows($preview['rows']), 0, 150)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(User $actor): array
    {
        $this->assertImporter($actor);

        return StandaloneIdCardImportBatch::query()
            ->inCompany((int) $actor->companyId())
            ->with('uploader')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function (StandaloneIdCardImportBatch $batch): array {
                $uploader = $batch->uploader;
                $uploaderName = $uploader !== null && (int) $uploader->tenant_id === (int) $batch->tenant_id ? $uploader->name : null;

                return [
                    'id' => (int) $batch->id,
                    'fileName' => $batch->original_name,
                    'status' => $batch->status === 'imported' ? 'Imported' : 'Preview',
                    'rawStatus' => $batch->status,
                    'uploadedBy' => $uploaderName,
                    'uploadedAt' => $batch->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
                    'rowCount' => (int) $batch->row_count,
                    'createdCount' => (int) $batch->created_count,
                    'duplicateCount' => (int) $batch->duplicate_count,
                    'failedCount' => (int) $batch->failed_count,
                ];
            })
            ->values()
            ->all();
    }

    public function confirm(User $actor, StandaloneIdCardImportBatch $batch): StandaloneIdCardImportBatch
    {
        $this->assertImporter($actor);
        $tenantId = (int) $actor->companyId();
        abort_unless((int) $batch->tenant_id === $tenantId, 404);

        try {
            DB::transaction(function () use ($actor, $batch, $tenantId): void {
                // Serialize concurrent confirms for this company so unique card numbers cannot race to 500s.
                StandaloneIdCardImportBatch::query()
                    ->inCompany($tenantId)
                    ->where('status', 'preview')
                    ->lockForUpdate()
                    ->get();
                StandaloneIdCard::query()
                    ->inCompany($tenantId)
                    ->lockForUpdate()
                    ->get();

                $locked = StandaloneIdCardImportBatch::query()->inCompany($tenantId)->whereKey($batch->id)->first();

                if ($locked === null) {
                    abort(404);
                }

                if ($locked->status !== 'preview') {
                    throw new StandaloneIdCardImportRejected('This import was already confirmed.');
                }

                $preview = $this->classify($tenantId, $this->table($this->absolute($locked)), null, true);

                if ($preview['createdCount'] < 1) {
                    throw new StandaloneIdCardImportRejected('There are no valid new rows to import.');
                }

                $usedNumbers = [];

                foreach ($preview['rows'] as $row) {
                    if ($row['outcome'] !== 'new') {
                        continue;
                    }

                    $cardNumber = $row['cardNumber'] !== ''
                        ? $row['cardNumber']
                        : $this->generateCardNumber($tenantId, $usedNumbers);
                    $usedNumbers[mb_strtolower($cardNumber)] = true;

                    $profileId = IdCardCompanyProfile::query()
                        ->inCompany($tenantId)
                        ->where('country', $row['country'])
                        ->value('id');

                    DB::table('standalone_id_cards')->insert([
                        'tenant_id' => $tenantId,
                        'country' => $row['country'],
                        'company_profile_id' => $profileId !== null ? (int) $profileId : null,
                        'full_name' => $row['fullName'],
                        'card_number' => $cardNumber,
                        'designation' => $row['designation'] !== '' ? $row['designation'] : null,
                        'department' => $row['department'] !== '' ? $row['department'] : null,
                        'email' => $row['email'] !== '' ? $row['email'] : null,
                        'phone' => $row['phone'] !== '' ? $row['phone'] : null,
                        'blood_group' => $row['bloodGroup'] !== '' ? $row['bloodGroup'] : null,
                        'emergency_contact_number' => $row['emergencyContactNumber'] !== '' ? $row['emergencyContactNumber'] : null,
                        'photo_path' => null,
                        'photo_mime' => null,
                        'status' => 'draft',
                        'qr_token' => $this->issuer->freshQrToken(),
                        'issued_at' => null,
                        'deactivated_at' => null,
                        'reissued_from_id' => null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                        'import_batch_id' => $locked->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $locked->forceFill([
                    'status' => 'imported',
                    'row_count' => $preview['rowCount'],
                    'created_count' => $preview['createdCount'],
                    'duplicate_count' => $preview['duplicateCount'],
                    'failed_count' => $preview['failedCount'],
                ])->save();
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'standalone_id_cards_tenant_card_number_unique')
                || (str_contains($message, 'unique') && str_contains($message, 'card_number'))) {
                throw new StandaloneIdCardImportRejected(
                    'Another import created one or more of these card numbers. Review the preview and try again.'
                );
            }

            throw $exception;
        }

        return $batch->refresh();
    }

    /**
     * @param  array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}  $table
     * @return array{rowCount: int, createdCount: int, duplicateCount: int, failedCount: int, rows: list<array<string, mixed>>}
     */
    private function classify(int $tenantId, array $table, ?int $ignoreBatchId = null, bool $lock = false): array
    {
        $columns = $this->columns($table['headers']);
        $existingNumbers = $this->existingCardNumbers($tenantId, $ignoreBatchId, $lock);
        $seenNumbers = [];
        $rows = [];
        $created = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($table['rows'] as $source) {
            $row = $this->candidate($columns, $source['cells'], (int) $source['line']);
            $problem = $row['problem'];

            if ($problem !== null) {
                $row['outcome'] = 'invalid';
                $row['reason'] = $problem;
                $failed++;
            } elseif ($row['cardNumber'] !== '') {
                $numberKey = mb_strtolower($row['cardNumber']);

                if (isset($seenNumbers[$numberKey])) {
                    $row['outcome'] = 'duplicate';
                    $row['reason'] = 'Duplicate card number in this file.';
                    $duplicates++;
                } elseif (isset($existingNumbers[$numberKey])) {
                    $row['outcome'] = 'duplicate';
                    $row['reason'] = 'Card number already exists for this company.';
                    $duplicates++;
                } else {
                    $row['outcome'] = 'new';
                    $row['reason'] = null;
                    $seenNumbers[$numberKey] = true;
                    $created++;
                }
            } else {
                $row['outcome'] = 'new';
                $row['reason'] = 'Card number will be generated on import.';
                $created++;
            }

            unset($row['problem']);
            $rows[] = $row;
        }

        return [
            'rowCount' => count($rows),
            'createdCount' => $created,
            'duplicateCount' => $duplicates,
            'failedCount' => $failed,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function columns(array $headers): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $key = self::HEADERS[$this->header($header)] ?? null;

            if ($key !== null && ! array_key_exists($key, $columns)) {
                $columns[$key] = $index;
            }
        }

        foreach (['full_name', 'designation', 'department', 'blood_group', 'emergency_contact_number', 'country'] as $required) {
            if (! array_key_exists($required, $columns)) {
                throw new StandaloneIdCardImportRejected(
                    'Sheet1 must include Full Name, Designation, Department, Blood Group, Emergency Contact, and Country columns. Card Number is optional.'
                );
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    private function candidate(array $columns, array $cells, int $line): array
    {
        $value = function (string $key) use ($columns, $cells): string {
            $index = $columns[$key] ?? null;

            return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
        };

        $fullName = $value('full_name');
        $cardNumber = $value('card_number');
        $designation = $value('designation');
        $department = $value('department');
        $email = $value('email');
        $phone = $value('phone');
        $bloodGroup = $value('blood_group');
        $emergency = $value('emergency_contact_number');
        $countryRaw = $value('country');
        $country = $this->country($countryRaw);
        $problem = null;

        if ($fullName === '') {
            $problem = 'Full name is required.';
        } elseif (mb_strlen($fullName) > 120) {
            $problem = 'Full name is too long.';
        } elseif ($designation === '') {
            $problem = 'Designation is required.';
        } elseif (mb_strlen($designation) > 120) {
            $problem = 'Designation is too long.';
        } elseif ($department === '') {
            $problem = 'Department is required.';
        } elseif (mb_strlen($department) > 120) {
            $problem = 'Department is too long.';
        } elseif ($bloodGroup === '') {
            $problem = 'Blood group is required.';
        } elseif (! in_array($bloodGroup, StandaloneIdCard::BLOOD_GROUPS, true)) {
            $problem = 'Blood group must be one of: '.implode(', ', StandaloneIdCard::BLOOD_GROUPS).'.';
        } elseif ($emergency === '') {
            $problem = 'Emergency contact is required.';
        } elseif (mb_strlen($emergency) > 40) {
            $problem = 'Emergency contact is too long.';
        } elseif ($country === null) {
            $problem = 'Country must be India or USA.';
        } elseif ($cardNumber !== '' && mb_strlen($cardNumber) > 60) {
            $problem = 'Card number is too long.';
        } elseif ($email !== '' && (mb_strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $problem = 'Email is invalid.';
        } elseif ($phone !== '' && mb_strlen($phone) > 40) {
            $problem = 'Phone number is too long.';
        }

        return [
            'line' => $line,
            'problem' => $problem,
            'fullName' => $fullName,
            'cardNumber' => $cardNumber,
            'designation' => $designation,
            'department' => $department,
            'email' => $email,
            'phone' => $phone,
            'bloodGroup' => $bloodGroup,
            'emergencyContactNumber' => $emergency,
            'country' => $country ?? strtolower($countryRaw),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function existingCardNumbers(int $tenantId, ?int $ignoreBatchId, bool $lock): array
    {
        $query = DB::table('standalone_id_cards')->where('tenant_id', $tenantId);

        if ($ignoreBatchId !== null) {
            $query->where(function ($inner) use ($ignoreBatchId): void {
                $inner->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $ignoreBatchId);
            });
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $keys = [];

        foreach ($query->pluck('card_number') as $number) {
            if (is_string($number) && $number !== '') {
                $keys[mb_strtolower($number)] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $reserved
     */
    private function generateCardNumber(int $tenantId, array $reserved = []): string
    {
        do {
            $candidate = 'STC-'.strtoupper(Str::random(8));
            $key = mb_strtolower($candidate);
            $exists = isset($reserved[$key]) || DB::table('standalone_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('card_number', $candidate)
                ->exists();
        } while ($exists);

        return $candidate;
    }

    private function country(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'india', 'in' => 'india',
            'usa', 'us', 'united states', 'united states of america' => 'usa',
            default => null,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function previewRows(array $rows): array
    {
        $problems = [];
        $fresh = [];

        foreach ($rows as $row) {
            if ($row['outcome'] === 'new') {
                $fresh[] = $row;
            } else {
                $problems[] = $row;
            }
        }

        return [...$problems, ...$fresh];
    }

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    private function table(string $path): array
    {
        try {
            return $this->sheets->readWorksheet($path, self::SHEET);
        } catch (RuntimeException $exception) {
            throw new StandaloneIdCardImportRejected($exception->getMessage());
        }
    }

    private function absolute(StandaloneIdCardImportBatch $batch): string
    {
        $path = $batch->stored_path;
        $prefix = 'id-cards/'.(int) $batch->tenant_id.'/standalone/imports/';

        if (! is_string($path) || $path === '' || str_contains($path, '..') || ! str_starts_with($path, $prefix) || ! str_ends_with(strtolower($path), '.xlsx') || ! Storage::disk('local')->exists($path)) {
            throw new StandaloneIdCardImportRejected('The uploaded file could not be read.');
        }

        return Storage::disk('local')->path($path);
    }

    private function assertXlsx(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            throw new StandaloneIdCardImportRejected('Upload an Excel .xlsx file.');
        }
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'standalone-id-cards.xlsx';
        }

        return mb_substr($name, 0, 255);
    }

    private function header(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return $header;
    }
}
