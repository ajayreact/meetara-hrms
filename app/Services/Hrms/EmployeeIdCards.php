<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Employee;
use App\Models\EmployeeIdCard;
use App\Models\IdCardCompanyProfile;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeIdCards
{
    public function __construct(private readonly EmployeeIdCardIssuer $issuer) {}

    /**
     * @return array{
     *     employees: list<array<string, mixed>>,
     *     selectedEmployeeId: int|null,
     *     card: array<string, mixed>,
     *     employee: array<string, mixed>|null,
     *     countries: list<array{value: string, label: string}>,
     *     bloodGroups: list<string>,
     *     issue: array<string, mixed>
     * }
     */
    public function page(User $actor, ?int $employeeId = null): array
    {
        $context = $this->context($actor);
        $employees = $this->employees($context['tenantId']);
        $selected = null;
        $card = $this->emptyCard();
        $row = null;

        if ($employeeId !== null) {
            $selected = collect($employees)->firstWhere('id', $employeeId);

            if ($selected === null) {
                abort(404);
            }

            $card = is_array($selected['card'] ?? null) ? $selected['card'] : $this->emptyCard();
            $row = $this->currentCard($context['tenantId'], $employeeId);
        }

        return [
            'employees' => $employees,
            'selectedEmployeeId' => $selected['id'] ?? null,
            'employee' => $selected,
            'card' => $card,
            'countries' => [
                ['value' => 'india', 'label' => 'India'],
                ['value' => 'usa', 'label' => 'USA'],
            ],
            'bloodGroups' => EmployeeIdCard::BLOOD_GROUPS,
            'issue' => $this->issuer->presentIssueState($context['tenantId'], $row),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{message: string, card: array<string, mixed>}
     */
    public function save(User $actor, array $input, ?UploadedFile $photo = null): array
    {
        $context = $this->context($actor);

        if (! Schema::hasTable('employee_id_cards')) {
            throw ValidationException::withMessages(['card' => 'Employee ID cards are not available yet.']);
        }

        $employeeId = (int) ($input['employee_id'] ?? 0);
        $employee = Employee::query()
            ->inCompany($context['tenantId'])
            ->with(['department', 'designation'])
            ->whereKey($employeeId)
            ->first();

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Select an employee from this company.']);
        }

        $country = $this->country((string) ($input['country'] ?? 'india'));
        $profileId = IdCardCompanyProfile::query()
            ->inCompany($context['tenantId'])
            ->where('country', $country)
            ->value('id');

        return DB::transaction(function () use ($context, $employee, $employeeId, $country, $profileId, $input, $photo): array {
            // Lock the employee row so two concurrent first-saves cannot both insert open drafts.
            Employee::query()
                ->inCompany($context['tenantId'])
                ->whereKey($employeeId)
                ->lockForUpdate()
                ->first();

            $openCards = EmployeeIdCard::query()
                ->inCompany($context['tenantId'])
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['draft', 'active'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $row = $openCards->first();

            // Collapse accidental duplicate open cards onto the newest row.
            foreach ($openCards->skip(1) as $duplicate) {
                DB::table('employee_id_cards')
                    ->where('tenant_id', $context['tenantId'])
                    ->where('id', $duplicate->id)
                    ->update([
                        'status' => 'inactive',
                        'deactivated_at' => now(),
                        'updated_by' => $context['userId'],
                        'updated_at' => now(),
                    ]);
            }

            $photoPath = $row?->photo_path;
            $photoMime = $row?->photo_mime;

            if ($photo !== null) {
                [$photoPath, $photoMime] = $this->storePhoto(
                    $context['tenantId'],
                    $employeeId,
                    $photo,
                    is_string($photoPath) ? $photoPath : null,
                    $row?->id !== null ? (int) $row->id : null,
                );
            }

            $payload = [
                'country' => $country,
                'company_profile_id' => $profileId !== null ? (int) $profileId : null,
                'blood_group' => $this->nullableString($input['blood_group'] ?? null),
                'emergency_contact_number' => $this->nullableString($input['emergency_contact_number'] ?? null),
                'photo_path' => $photoPath,
                'photo_mime' => $photoMime,
                'updated_by' => $context['userId'],
                'updated_at' => now(),
            ];

            if ($this->hasCardRoleColumns()) {
                $designation = $this->requiredRoleField($input['designation'] ?? null, 'designation');
                $department = $this->requiredRoleField($input['department'] ?? null, 'department');

                // First save can seed from the employee profile when the form omits values.
                if ($row === null) {
                    $designation = $designation !== '' ? $designation : (string) ($employee->designation?->name ?? '');
                    $department = $department !== '' ? $department : (string) ($employee->department?->name ?? '');
                    $designation = $this->requiredRoleField($designation, 'designation');
                    $department = $this->requiredRoleField($department, 'department');
                }

                $payload['designation'] = $designation;
                $payload['department'] = $department;
            }

            if ($row === null) {
                $payload['tenant_id'] = $context['tenantId'];
                $payload['employee_id'] = $employeeId;
                $payload['status'] = 'draft';
                $payload['qr_token'] = $this->qrToken();
                $payload['created_by'] = $context['userId'];
                $payload['created_at'] = now();
                $id = DB::table('employee_id_cards')->insertGetId($payload);
                $row = EmployeeIdCard::query()->inCompany($context['tenantId'])->whereKey($id)->firstOrFail();
                $message = 'Employee ID card details saved.';
            } else {
                DB::table('employee_id_cards')
                    ->where('tenant_id', $context['tenantId'])
                    ->where('id', $row->id)
                    ->update($payload);
                $row = $row->fresh();
                $message = 'Employee ID card details updated.';
            }

            if ($row->status === 'active' && $this->hasCardRoleColumns()) {
                $this->issuer->refreshIssuedPdf($context['tenantId'], $row);
            }

            return [
                'message' => $message,
                'card' => $this->presentCard($context['tenantId'], $row, $employee),
            ];
        });
    }

    public function photoResponse(User $actor, EmployeeIdCard $card): StreamedResponse
    {
        $context = $this->context($actor);
        abort_unless((int) $card->tenant_id === $context['tenantId'], 404);
        abort_unless($this->privatePhoto($context['tenantId'], $card->photo_path), 404);

        return Storage::disk('local')->response((string) $card->photo_path, basename((string) $card->photo_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => (string) ($card->photo_mime ?: 'application/octet-stream'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(int $tenantId): array
    {
        $cards = EmployeeIdCard::query()
            ->inCompany($tenantId)
            ->whereIn('status', ['draft', 'active'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        return Employee::query()
            ->inCompany($tenantId)
            ->with(['department', 'designation'])
            ->orderBy('employee_code')
            ->get()
            ->map(function (Employee $employee) use ($tenantId, $cards): array {
                $name = trim($employee->first_name.' '.$employee->last_name);
                $card = $cards->get($employee->id)?->first();
                $presented = $this->presentCard($tenantId, $card, $employee);

                return [
                    'id' => (int) $employee->id,
                    'code' => (string) $employee->employee_code,
                    'name' => $name,
                    'email' => (string) ($employee->email ?? ''),
                    'department' => (string) ($employee->department?->name ?? ''),
                    'designation' => (string) ($employee->designation?->name ?? ''),
                    'card' => $presented,
                ];
            })
            ->values()
            ->all();
    }

    private function currentCard(int $tenantId, int $employeeId): ?EmployeeIdCard
    {
        return EmployeeIdCard::query()
            ->inCompany($tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['draft', 'active'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCard(int $tenantId, ?EmployeeIdCard $row, ?Employee $employee = null): array
    {
        if ($row === null) {
            return $this->emptyCard($employee);
        }

        $hasPhoto = $this->privatePhoto($tenantId, $row->photo_path);
        [$designation, $department] = $this->cardRoleLabels($row, $employee);

        return [
            'id' => (int) $row->id,
            'country' => (string) $row->country,
            'bloodGroup' => (string) ($row->blood_group ?? ''),
            'emergencyContactNumber' => (string) ($row->emergency_contact_number ?? ''),
            'designation' => $designation,
            'department' => $department,
            'hasPhoto' => $hasPhoto,
            'photoUrl' => $hasPhoto ? '/id-cards/'.$row->id.'/photo' : null,
            'status' => (string) $row->status,
            'updatedAt' => $row->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCard(?Employee $employee = null): array
    {
        return [
            'id' => null,
            'country' => 'india',
            'bloodGroup' => '',
            'emergencyContactNumber' => '',
            'designation' => (string) ($employee?->designation?->name ?? ''),
            'department' => (string) ($employee?->department?->name ?? ''),
            'hasPhoto' => false,
            'photoUrl' => null,
            'status' => 'draft',
            'updatedAt' => null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function cardRoleLabels(EmployeeIdCard $row, ?Employee $employee = null): array
    {
        $designation = '';
        $department = '';

        if ($this->hasCardRoleColumns()) {
            $designation = trim((string) ($row->designation ?? ''));
            $department = trim((string) ($row->department ?? ''));
        }

        if ($designation === '') {
            $designation = (string) ($employee?->designation?->name ?? '');
        }

        if ($department === '') {
            $department = (string) ($employee?->department?->name ?? '');
        }

        return [$designation, $department];
    }

    private function hasCardRoleColumns(): bool
    {
        return Schema::hasColumn('employee_id_cards', 'designation')
            && Schema::hasColumn('employee_id_cards', 'department');
    }

    private function requiredRoleField(mixed $value, string $field): string
    {
        $trimmed = trim((string) ($value ?? ''));

        if ($trimmed === '') {
            throw ValidationException::withMessages([
                $field => ucfirst($field).' is required for the ID card.',
            ]);
        }

        if (mb_strlen($trimmed) > 120) {
            throw ValidationException::withMessages([
                $field => ucfirst($field).' may not be greater than 120 characters.',
            ]);
        }

        return $trimmed;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storePhoto(int $tenantId, int $employeeId, UploadedFile $photo, ?string $previous, ?int $cardId = null): array
    {
        $extension = strtolower((string) $photo->getClientOriginalExtension());
        $mime = (string) ($photo->getMimeType() ?: '');
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

        if (! array_key_exists($extension, $allowed) || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'photo' => 'Upload a PNG or JPEG photo up to 2 MB.',
            ]);
        }

        $contents = file_get_contents($photo->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['photo' => 'The employee photo could not be read.']);
        }

        $path = 'id-cards/'.$tenantId.'/photos/'.$employeeId.'-'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        if (is_string($previous) && $previous !== '' && $previous !== $path && $this->privatePhoto($tenantId, $previous)) {
            $stillUsed = DB::table('employee_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('photo_path', $previous)
                ->when($cardId !== null, fn ($query) => $query->where('id', '!=', $cardId))
                ->exists();

            if (! $stillUsed) {
                Storage::disk('local')->delete($previous);
            }
        }

        return [$path, $allowed[$extension]];
    }

    private function privatePhoto(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/photos/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    private function qrToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $employeeHit = EmployeeIdCard::query()->where('qr_token', $token)->exists();
            $standaloneHit = Schema::hasTable('standalone_id_cards')
                && DB::table('standalone_id_cards')->where('qr_token', $token)->exists();
        } while ($employeeHit || $standaloneHit);

        return $token;
    }

    /**
     * @return array{tenantId: int, userId: int}
     */
    private function context(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['idCardsManage'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        if (! Schema::hasTable('employee_id_cards')) {
            abort(404);
        }

        return [
            'tenantId' => (int) $actor->companyId(),
            'userId' => (int) $actor->id,
        ];
    }

    private function country(string $country): string
    {
        $value = strtolower(trim($country));

        if (! in_array($value, IdCardCompanyProfile::COUNTRIES, true)) {
            return 'india';
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
