<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Lead;
use App\Models\LeadImportBatch;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LeadImport
{
    private const SHEET = 'Sheet1';

    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'date' => 'imported_on',
        'linkedin' => 'linkedin',
        'state' => 'state',
        'name' => 'name',
        'emaill' => 'email',
        'email' => 'email',
        'phone' => 'phone',
        'university' => 'university',
        'year' => 'graduation_term',
        'experience' => 'experience',
        'designations' => 'designations',
    ];

    /**
     * @var array<string, int>
     */
    private const LIMITS = [
        'name' => 255,
        'email' => 255,
        'phone' => 255,
        'linkedin' => 255,
        'state' => 255,
        'university' => 255,
        'graduation_term' => 255,
        'experience' => 20,
        'designations' => 65000,
    ];

    public function __construct(private readonly SpreadsheetTable $sheets) {}

    public function assertImporter(User $actor): void
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['leadsImport'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }
    }

    public function stage(User $actor, UploadedFile $file): LeadImportBatch
    {
        $this->assertImporter($actor);
        $tenantId = (int) $actor->companyId();
        $this->assertXlsx($file);
        $preview = $this->classify($tenantId, $this->table($file->getRealPath() ?: $file->getPathname()));
        $path = $file->storeAs('lead-imports/'.$tenantId, Str::uuid()->toString().'.xlsx', 'local');

        if (! is_string($path) || $path === '') {
            throw new LeadImportRejected('The uploaded file could not be stored.');
        }

        try {
            $batch = new LeadImportBatch;
            $batch->forceFill([
                'tenant_id' => $tenantId,
                'original_name' => $this->originalName($file),
                'stored_path' => $path,
                'uploaded_by' => $actor->id,
                'status' => 'preview',
                'row_count' => $preview['rowCount'],
                'created_count' => $preview['createdCount'],
                'duplicate_count' => $preview['duplicateCount'],
                'invalid_count' => $preview['invalidCount'],
            ])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        return $batch->refresh();
    }

    public function find(User $actor, int $batchId): LeadImportBatch
    {
        $this->assertImporter($actor);
        $batch = LeadImportBatch::query()->inCompany((int) $actor->companyId())->whereKey($batchId)->first();
        abort_if($batch === null, 404);

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $actor, LeadImportBatch $batch): array
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
            'invalidCount' => $stored ? (int) $batch->invalid_count : $preview['invalidCount'],
            'canConfirm' => $batch->status === 'preview',
            'truncated' => count($preview['rows']) > 150,
            'rows' => array_map(fn (array $row): array => [
                'line' => $row['line'],
                'outcome' => $row['outcome'],
                'reason' => $row['reason'],
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'linkedin' => $row['linkedin'],
                'state' => $row['state'],
                'university' => $row['university'],
                'graduationTerm' => $row['graduationTerm'],
                'experience' => $row['experience'],
                'designations' => $row['designations'],
                'importedOn' => $row['importedOn'],
            ], array_slice($this->previewRows($preview['rows']), 0, 150)),
        ];
    }

    public function confirm(User $actor, LeadImportBatch $batch): LeadImportBatch
    {
        $this->assertImporter($actor);
        $tenantId = (int) $actor->companyId();
        abort_unless((int) $batch->tenant_id === $tenantId, 404);

        DB::transaction(function () use ($actor, $batch, $tenantId): void {
            $locked = LeadImportBatch::query()->inCompany($tenantId)->whereKey($batch->id)->lockForUpdate()->first();

            if ($locked === null) {
                abort(404);
            }

            if ($locked->status !== 'preview') {
                throw new LeadImportRejected('This import was already confirmed.');
            }

            $preview = $this->classify($tenantId, $this->table($this->absolute($locked)), null, true);

            foreach ($preview['rows'] as $row) {
                if ($row['outcome'] !== 'new') {
                    continue;
                }

                $lead = new Lead;
                $lead->forceFill([
                    'tenant_id' => $tenantId,
                    'import_batch_id' => $locked->id,
                    'source_row' => $row['line'],
                    'imported_on' => $row['importedOn'],
                    'name' => $row['name'],
                    'email' => $row['email'] !== '' ? $row['email'] : null,
                    'email_normalized' => $row['emailNormalized'],
                    'phone' => $row['phone'] !== '' ? $row['phone'] : null,
                    'phone_digits' => $row['phoneDigits'],
                    'linkedin_url' => $row['linkedin'] !== '' ? $row['linkedin'] : null,
                    'linkedin_key' => $row['linkedinKey'],
                    'state' => $row['state'] !== '' ? $row['state'] : null,
                    'university' => $row['university'] !== '' ? $row['university'] : null,
                    'graduation_term' => $row['graduationTerm'] !== '' ? $row['graduationTerm'] : null,
                    'experience' => $row['experience'] !== '' ? $row['experience'] : null,
                    'designations' => $row['designations'] !== '' ? $row['designations'] : null,
                    'pipeline_status' => 'new',
                    'team_lead_employee_id' => null,
                    'assignee_employee_id' => null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ])->save();
            }

            $locked->forceFill([
                'status' => 'imported',
                'row_count' => $preview['rowCount'],
                'created_count' => $preview['createdCount'],
                'duplicate_count' => $preview['duplicateCount'],
                'invalid_count' => $preview['invalidCount'],
            ])->save();
        });

        return $batch->refresh();
    }

    /**
     * @param  array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}  $table
     * @return array{rowCount: int, createdCount: int, duplicateCount: int, invalidCount: int, rows: list<array<string, mixed>>}
     */
    private function classify(int $tenantId, array $table, ?int $ignoreBatchId = null, bool $lock = false): array
    {
        $columns = $this->columns($table['headers']);
        $existing = $this->existingKeys($tenantId, $ignoreBatchId, $lock);
        $seen = ['email' => [], 'phone' => [], 'linkedin' => []];
        $rows = [];
        $created = 0;
        $duplicates = 0;
        $invalid = 0;

        foreach ($table['rows'] as $source) {
            $row = $this->candidate($columns, $source['cells'], (int) $source['line']);
            $problem = $row['problem'];

            if ($problem !== null) {
                $row['outcome'] = 'invalid';
                $row['reason'] = $problem;
                $invalid++;
            } else {
                $reason = $this->duplicateReason($row, $seen, $existing);

                if ($reason !== null) {
                    $row['outcome'] = 'duplicate';
                    $row['reason'] = $reason;
                    $duplicates++;
                } else {
                    $row['outcome'] = 'new';
                    $row['reason'] = null;
                    $this->remember($seen, $row);
                    $created++;
                }
            }

            unset($row['problem']);
            $rows[] = $row;
        }

        return [
            'rowCount' => count($rows),
            'createdCount' => $created,
            'duplicateCount' => $duplicates,
            'invalidCount' => $invalid,
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

        if (! array_key_exists('name', $columns)) {
            throw new LeadImportRejected('Sheet1 must include a Name column. The Emaill column is read as Email and the Year column is read as Graduation Term.');
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
        $name = $value('name');
        $email = $value('email');
        $phone = $value('phone');
        $linkedin = $value('linkedin');
        $emailNormalized = $this->emailKey($email);
        $phoneDigits = $this->phoneKey($phone);
        $linkedinKey = $this->linkedinKey($linkedin);
        $problem = null;

        if ($name === '') {
            $problem = 'Candidate name is required.';
        } else {
            foreach ([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'linkedin' => $linkedin,
                'state' => $value('state'),
                'university' => $value('university'),
                'graduation_term' => $value('graduation_term'),
                'experience' => $value('experience'),
                'designations' => $value('designations'),
            ] as $field => $text) {
                if (mb_strlen($text) > self::LIMITS[$field]) {
                    $problem = match ($field) {
                        'graduation_term' => 'Graduation term is too long.',
                        'linkedin' => 'LinkedIn profile is too long.',
                        default => ucfirst(str_replace('_', ' ', $field)).' is too long.',
                    };
                    break;
                }
            }

            if ($problem === null && $phoneDigits !== null && strlen($phoneDigits) > 20) {
                $problem = 'Phone has too many digits.';
            }

            if ($problem === null && $linkedinKey !== null && strlen($linkedinKey) > 255) {
                $problem = 'LinkedIn profile is too long.';
            }
        }

        return [
            'line' => $line,
            'problem' => $problem,
            'name' => $name,
            'email' => $email,
            'emailNormalized' => $emailNormalized,
            'phone' => $phone,
            'phoneDigits' => $phoneDigits !== null && strlen($phoneDigits) <= 20 ? $phoneDigits : null,
            'linkedin' => $linkedin,
            'linkedinKey' => $linkedinKey !== null && strlen($linkedinKey) <= 255 ? $linkedinKey : null,
            'state' => $value('state'),
            'university' => $value('university'),
            'graduationTerm' => $value('graduation_term'),
            'experience' => $value('experience'),
            'designations' => $value('designations'),
            'importedOn' => $this->date($value('imported_on')),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{email: array<string, true>, phone: array<string, true>, linkedin: array<string, true>}  $seen
     * @param  array{email: array<string, true>, phone: array<string, true>, linkedin: array<string, true>}  $existing
     */
    private function duplicateReason(array $row, array $seen, array $existing): ?string
    {
        $email = $row['emailNormalized'];
        $phone = $row['phoneDigits'];
        $linkedin = $row['linkedinKey'];

        if (is_string($email) && $email !== '') {
            if (isset($seen['email'][$email])) {
                return 'Duplicate email in this file.';
            }

            if (isset($existing['email'][$email])) {
                return 'Email already exists for this company.';
            }
        }

        if (is_string($phone) && $phone !== '') {
            if (isset($seen['phone'][$phone])) {
                return 'Duplicate phone in this file.';
            }

            if (isset($existing['phone'][$phone])) {
                return 'Phone already exists for this company.';
            }
        }

        if (is_string($linkedin) && $linkedin !== '') {
            if (isset($seen['linkedin'][$linkedin])) {
                return 'Duplicate LinkedIn profile in this file.';
            }

            if (isset($existing['linkedin'][$linkedin])) {
                return 'LinkedIn profile already exists for this company.';
            }
        }

        return null;
    }

    /**
     * @param  array{email: array<string, true>, phone: array<string, true>, linkedin: array<string, true>}  $seen
     * @param  array<string, mixed>  $row
     */
    private function remember(array &$seen, array $row): void
    {
        foreach (['email' => 'emailNormalized', 'phone' => 'phoneDigits', 'linkedin' => 'linkedinKey'] as $bucket => $field) {
            $key = $row[$field];

            if (is_string($key) && $key !== '') {
                $seen[$bucket][$key] = true;
            }
        }
    }

    /**
     * @return array{email: array<string, true>, phone: array<string, true>, linkedin: array<string, true>}
     */
    private function existingKeys(int $tenantId, ?int $ignoreBatchId, bool $lock): array
    {
        $query = DB::table('leads')->where('tenant_id', $tenantId);

        if ($ignoreBatchId !== null) {
            $query->where(function ($inner) use ($ignoreBatchId): void {
                $inner->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $ignoreBatchId);
            });
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $keys = ['email' => [], 'phone' => [], 'linkedin' => []];

        foreach ($query->get(['email_normalized', 'phone_digits', 'linkedin_key']) as $lead) {
            if (is_string($lead->email_normalized) && $lead->email_normalized !== '') {
                $keys['email'][$lead->email_normalized] = true;
            }

            if (is_string($lead->phone_digits) && $lead->phone_digits !== '') {
                $keys['phone'][$lead->phone_digits] = true;
            }

            if (is_string($lead->linkedin_key) && $lead->linkedin_key !== '') {
                $keys['linkedin'][$lead->linkedin_key] = true;
            }
        }

        return $keys;
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
            throw new LeadImportRejected($exception->getMessage());
        }
    }

    private function absolute(LeadImportBatch $batch): string
    {
        $path = $batch->stored_path;
        $prefix = 'lead-imports/'.(int) $batch->tenant_id.'/';

        if (! is_string($path) || $path === '' || str_contains($path, '..') || ! str_starts_with($path, $prefix) || ! str_ends_with(strtolower($path), '.xlsx') || ! Storage::disk('local')->exists($path)) {
            throw new LeadImportRejected('The uploaded file could not be read.');
        }

        return Storage::disk('local')->path($path);
    }

    private function assertXlsx(UploadedFile $file): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            throw new LeadImportRejected('Upload an Excel .xlsx file.');
        }
    }

    private function originalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'leads.xlsx';
        }

        return mb_substr($name, 0, 255);
    }

    private function header(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return $header;
    }

    private function emailKey(string $email): ?string
    {
        $email = strtolower(trim($email));

        if (str_starts_with($email, 'mailto:')) {
            $email = trim(substr($email, 7));
        }

        return $email === '' ? null : $email;
    }

    private function phoneKey(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function linkedinKey(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $url = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url) ?? $url;
        $url = preg_replace('#^www\.#i', '', $url) ?? $url;
        $url = explode('?', $url, 2)[0];
        $url = explode('#', $url, 2)[0];
        $url = rtrim($url, '/');
        $url = strtolower($url);

        return $url === '' ? null : $url;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : null;
        }

        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $match) === 1) {
            $day = (int) $match[1];
            $month = (int) $match[2];
            $year = (int) $match[3];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (int) floor((float) $value);

            if ($serial < 18264 || $serial > 73415) {
                return null;
            }

            $timestamp = (int) round(($serial - 25569) * 86400);
            $parsed = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));

            return $parsed->format('Y-m-d');
        }

        return null;
    }
}
