<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\Employee;
use App\Models\EmployeeIdCard;
use App\Models\EmployeeIdCardIssue;
use App\Models\IdCardCompanyProfile;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeIdCardIssuer
{
    public function __construct(private readonly EmployeeIdCardPdf $pdf) {}

    /**
     * @return array{message: string, cardId: int}
     */
    public function issue(User $actor, EmployeeIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if (! in_array($card->status, ['draft', 'active'], true)) {
            throw ValidationException::withMessages(['card' => 'Only draft or active ID cards can be issued.']);
        }

        $this->assertReady($context['tenantId'], $card);

        $payload = $this->snapshot($context['tenantId'], $card);
        $bytes = $this->pdf->render($payload);

        return DB::transaction(function () use ($context, $card, $payload, $bytes): array {
            // Prevent concurrent duplicate actives for the same employee.
            EmployeeIdCard::query()
                ->inCompany($context['tenantId'])
                ->where('employee_id', $card->employee_id)
                ->whereIn('status', ['draft', 'active'])
                ->lockForUpdate()
                ->get();

            DB::table('employee_id_cards')
                ->where('tenant_id', $context['tenantId'])
                ->where('employee_id', $card->employee_id)
                ->where('status', 'active')
                ->where('id', '!=', $card->id)
                ->update([
                    'status' => 'inactive',
                    'deactivated_at' => now(),
                    'updated_by' => $context['userId'],
                    'updated_at' => now(),
                ]);

            $issueId = DB::table('employee_id_card_issues')->insertGetId([
                'tenant_id' => $context['tenantId'],
                'id_card_id' => $card->id,
                'payload_json' => json_encode($this->storedPayload($payload), JSON_THROW_ON_ERROR),
                'issued_by' => $context['userId'],
                'created_at' => now(),
            ]);

            $pdfPath = 'id-cards/'.$context['tenantId'].'/pdfs/'.$card->id.'-'.$issueId.'.pdf';
            Storage::disk('local')->put($pdfPath, $bytes);
            DB::table('employee_id_card_issues')
                ->where('tenant_id', $context['tenantId'])
                ->where('id', $issueId)
                ->update(['pdf_path' => $pdfPath]);

            $cardNumber = $card->card_number ?: ('IDC-'.str_pad((string) $card->id, 6, '0', STR_PAD_LEFT));
            DB::table('employee_id_cards')
                ->where('tenant_id', $context['tenantId'])
                ->where('id', $card->id)
                ->update([
                    'status' => 'active',
                    'card_number' => $cardNumber,
                    'issued_at' => now(),
                    'deactivated_at' => null,
                    'company_profile_id' => $payload['companyProfileId'] ?? $card->company_profile_id,
                    'updated_by' => $context['userId'],
                    'updated_at' => now(),
                ]);

            return [
                'message' => 'Employee ID card issued.',
                'cardId' => (int) $card->id,
            ];
        });
    }

    /**
     * @return array{message: string, cardId: int}
     */
    public function reissue(User $actor, EmployeeIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if ($card->status !== 'active') {
            throw ValidationException::withMessages(['card' => 'Only an active ID card can be reissued.']);
        }

        $this->assertReady($context['tenantId'], $card);

        $newId = null;
        $photoCopy = null;

        try {
            $newId = DB::transaction(function () use ($context, $card, &$photoCopy): int {
                $locked = EmployeeIdCard::query()
                    ->inCompany($context['tenantId'])
                    ->whereKey($card->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== 'active') {
                    throw ValidationException::withMessages(['card' => 'Only an active ID card can be reissued.']);
                }

                DB::table('employee_id_cards')
                    ->where('tenant_id', $context['tenantId'])
                    ->where('id', $locked->id)
                    ->update([
                        'status' => 'reissued',
                        'deactivated_at' => now(),
                        'updated_by' => $context['userId'],
                        'updated_at' => now(),
                    ]);

                [$photoPath, $photoMime] = $this->copyPhoto(
                    $context['tenantId'],
                    (int) $locked->employee_id,
                    $locked->photo_path,
                    $locked->photo_mime,
                );
                $photoCopy = $photoPath;

                $insert = [
                    'tenant_id' => $context['tenantId'],
                    'employee_id' => $locked->employee_id,
                    'country' => $locked->country,
                    'company_profile_id' => $locked->company_profile_id,
                    'blood_group' => $locked->blood_group,
                    'emergency_contact_number' => $locked->emergency_contact_number,
                    'photo_path' => $photoPath,
                    'photo_mime' => $photoMime,
                    'status' => 'draft',
                    'card_number' => null,
                    'qr_token' => $this->qrToken(),
                    'issued_at' => null,
                    'deactivated_at' => null,
                    'reissued_from_id' => $locked->id,
                    'created_by' => $context['userId'],
                    'updated_by' => $context['userId'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('employee_id_cards', 'designation')) {
                    $insert['designation'] = $locked->designation;
                }

                if (Schema::hasColumn('employee_id_cards', 'department')) {
                    $insert['department'] = $locked->department;
                }

                return (int) DB::table('employee_id_cards')->insertGetId($insert);
            });

            $newCard = $this->ownedCard($context['tenantId'], $newId);
            $issued = $this->issue($actor, $newCard);

            return [
                'message' => 'Employee ID card reissued with a new verification QR.',
                'cardId' => $issued['cardId'],
            ];
        } catch (\Throwable $exception) {
            if ($newId !== null) {
                $this->restoreReissue(
                    $context['tenantId'],
                    (int) $card->id,
                    $newId,
                    is_string($photoCopy) ? $photoCopy : null,
                    $context['userId'],
                );
            }

            throw $exception;
        }
    }

    /**
     * @return array{message: string, cardId: int}
     */
    public function deactivate(User $actor, EmployeeIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if (! in_array($card->status, ['draft', 'active'], true)) {
            throw ValidationException::withMessages(['card' => 'This ID card is already inactive.']);
        }

        DB::table('employee_id_cards')
            ->where('tenant_id', $context['tenantId'])
            ->where('id', $card->id)
            ->update([
                'status' => 'inactive',
                'deactivated_at' => now(),
                'updated_by' => $context['userId'],
                'updated_at' => now(),
            ]);

        return [
            'message' => 'Employee ID card deactivated. The QR verification link is no longer valid.',
            'cardId' => (int) $card->id,
        ];
    }

    public function download(User $actor, EmployeeIdCard $card): StreamedResponse
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);
        abort_unless($card->status === 'active', 404);
        $path = $this->latestPdfPath($context['tenantId'], $card);
        abort_unless($path !== null && $this->privatePdf($context['tenantId'], $path), 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->downloadFilename($card).'"',
        ]);
    }

    /**
     * Preview payload for the manage UI (front/back), without writing a PDF.
     *
     * @return array<string, mixed>
     */
    public function preview(User $actor, EmployeeIdCard $card): array
    {
        $context = $this->manageContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);
        $snapshot = $this->snapshot($context['tenantId'], $card, false);

        return [
            'front' => [
                'companyDisplayName' => $snapshot['companyDisplayName'],
                'hasLogo' => $snapshot['logoPath'] !== null,
                'logoUrl' => $snapshot['logoUrl'],
                'hasPhoto' => $snapshot['photoPath'] !== null,
                'photoUrl' => $snapshot['photoUrl'],
                'name' => $snapshot['name'],
                'employeeCode' => $snapshot['employeeCode'],
                'designation' => $snapshot['designation'],
                'department' => $snapshot['department'],
            ],
            'back' => [
                'email' => $snapshot['email'],
                'bloodGroup' => $snapshot['bloodGroup'],
                'emergencyContactNumber' => $snapshot['emergencyContactNumber'],
                'footerAddress' => $snapshot['footerAddress'],
                'verifyUrl' => $snapshot['verifyUrl'],
            ],
            'readiness' => $this->readiness($context['tenantId'], $card),
        ];
    }

    /**
     * Public verification payload — limited professional details only.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        if (! Schema::hasTable('employee_id_cards') || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->invalidVerification();
        }

        $card = EmployeeIdCard::query()->where('qr_token', $token)->first();

        if ($card === null) {
            return $this->invalidVerification();
        }

        $valid = $card->status === 'active';

        // Invalidated QR codes must not disclose identity — only that the badge is no longer valid.
        if (! $valid) {
            return [
                'valid' => false,
                'status' => (string) $card->status,
                'name' => '',
                'employeeCode' => '',
                'designation' => '',
                'department' => '',
                'companyName' => '',
                'country' => '',
                'cardNumber' => '',
                'message' => 'This employee ID card is no longer valid.',
            ];
        }

        $snapshot = EmployeeIdCardIssue::query()
            ->inCompany((int) $card->tenant_id)
            ->where('id_card_id', $card->id)
            ->orderByDesc('id')
            ->value('payload_json');

        if (is_array($snapshot)) {
            return [
                'valid' => true,
                'status' => 'active',
                'name' => (string) ($snapshot['name'] ?? ''),
                'employeeCode' => (string) ($snapshot['employeeCode'] ?? ''),
                'designation' => (string) ($snapshot['designation'] ?? ''),
                'department' => (string) ($snapshot['department'] ?? ''),
                'companyName' => (string) ($snapshot['companyDisplayName'] ?? ''),
                'country' => (string) ($snapshot['country'] ?? $card->country),
                'cardNumber' => (string) ($snapshot['cardNumber'] ?? $card->card_number ?? ''),
                'message' => 'This employee ID card is active.',
            ];
        }

        $snapshot = $this->snapshot((int) $card->tenant_id, $card, false);

        return [
            'valid' => true,
            'status' => 'active',
            'name' => (string) ($snapshot['name'] !== '' ? $snapshot['name'] : 'Unavailable'),
            'employeeCode' => (string) ($snapshot['employeeCode'] ?? ''),
            'designation' => (string) ($snapshot['designation'] ?? ''),
            'department' => (string) ($snapshot['department'] ?? ''),
            'companyName' => (string) ($snapshot['companyDisplayName'] ?? ''),
            'country' => (string) $card->country,
            'cardNumber' => (string) ($snapshot['cardNumber'] ?? $card->card_number ?? ''),
            'message' => 'This employee ID card is active.',
        ];
    }

    /**
     * @return array{ready: bool, blockers: list<string>}
     */
    public function readiness(int $tenantId, EmployeeIdCard $card): array
    {
        $blockers = [];

        if (trim((string) ($card->blood_group ?? '')) === '') {
            $blockers[] = 'Blood group is required.';
        }

        if (trim((string) ($card->emergency_contact_number ?? '')) === '') {
            $blockers[] = 'Emergency contact is required.';
        }

        if (! $this->privatePhoto($tenantId, $card->photo_path)) {
            $blockers[] = 'Employee photo is required.';
        }

        $profile = IdCardCompanyProfile::query()
            ->inCompany($tenantId)
            ->where('country', $card->country)
            ->first();

        if ($profile === null) {
            $blockers[] = 'Save the '.strtoupper((string) $card->country).' ID card company profile first.';
        }

        $employee = Employee::query()->inCompany($tenantId)->whereKey($card->employee_id)->first();
        if ($employee === null) {
            $blockers[] = 'Employee record was not found.';
        } elseif ($employee->date_of_birth === null) {
            $blockers[] = 'Date of birth is required.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentIssueState(int $tenantId, ?EmployeeIdCard $card): array
    {
        if ($card === null) {
            return [
                'ready' => false,
                'blockers' => ['Select an employee and save card details first.'],
                'canIssue' => false,
                'canReissue' => false,
                'canDeactivate' => false,
                'hasPdf' => false,
                'pdfUrl' => null,
                'verifyUrl' => null,
                'issuedAt' => null,
                'cardNumber' => null,
                'preview' => null,
            ];
        }

        $readiness = $this->readiness($tenantId, $card);
        $hasPdf = $this->latestPdfPath($tenantId, $card) !== null;
        $snapshot = $this->snapshot($tenantId, $card, false);

        return [
            'ready' => $readiness['ready'],
            'blockers' => $readiness['blockers'],
            'canIssue' => $readiness['ready'] && in_array($card->status, ['draft', 'active'], true),
            'canReissue' => $card->status === 'active' && $readiness['ready'],
            'canDeactivate' => in_array($card->status, ['draft', 'active'], true),
            'hasPdf' => $hasPdf,
            'pdfUrl' => $hasPdf ? '/id-cards/'.$card->id.'/pdf' : null,
            'verifyUrl' => $this->verifyUrl((string) $card->qr_token),
            'issuedAt' => $card->issued_at?->toDateTimeString(),
            'cardNumber' => $card->card_number,
            'preview' => [
                'front' => [
                    'companyDisplayName' => $snapshot['companyDisplayName'],
                    'hasLogo' => $snapshot['logoPath'] !== null,
                    'logoUrl' => $snapshot['logoUrl'],
                    'hasPhoto' => $snapshot['photoPath'] !== null,
                    'photoUrl' => $snapshot['photoUrl'],
                    'name' => $snapshot['name'],
                    'employeeCode' => $snapshot['employeeCode'],
                    'designation' => $snapshot['designation'],
                    'department' => $snapshot['department'],
                    'dateOfBirth' => $snapshot['dateOfBirth'],
                ],
                'back' => [
                    'email' => $snapshot['email'],
                    'bloodGroup' => $snapshot['bloodGroup'],
                    'emergencyContactNumber' => $snapshot['emergencyContactNumber'],
                    'footerAddress' => $snapshot['footerAddress'],
                    'verifyUrl' => $snapshot['verifyUrl'],
                    'hasSignature' => $snapshot['signaturePath'] !== null,
                    'signatureUrl' => $snapshot['signatureUrl'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $tenantId, EmployeeIdCard $card, bool $requireReady = true): array
    {
        if ($requireReady) {
            $this->assertReady($tenantId, $card);
        }

        $employee = Employee::query()
            ->inCompany($tenantId)
            ->with(['department', 'designation'])
            ->whereKey($card->employee_id)
            ->first();

        $profile = IdCardCompanyProfile::query()
            ->inCompany($tenantId)
            ->where('country', $card->country)
            ->first();

        // Only bind the company profile when issuing — never mutate on read/preview.
        if ($requireReady && $profile !== null && (int) ($card->company_profile_id ?? 0) !== (int) $profile->id) {
            $card->company_profile_id = $profile->id;
        }

        $logoPath = $profile && $this->privateLogo($tenantId, $profile->logo_path)
            ? Storage::disk('local')->path((string) $profile->logo_path)
            : $this->officialLogoPath();
        $photoPath = $this->privatePhoto($tenantId, $card->photo_path)
            ? Storage::disk('local')->path((string) $card->photo_path)
            : null;

        $designation = '';
        $department = '';

        if (Schema::hasColumn('employee_id_cards', 'designation')) {
            $designation = trim((string) ($card->designation ?? ''));
        }

        if (Schema::hasColumn('employee_id_cards', 'department')) {
            $department = trim((string) ($card->department ?? ''));
        }

        if ($designation === '') {
            $designation = (string) ($employee?->designation?->name ?? '');
        }

        if ($department === '') {
            $department = (string) ($employee?->department?->name ?? '');
        }

        $hasCompanyLogo = $profile && $this->privateLogo($tenantId, $profile->logo_path);
        [$signaturePath, $signatureUrl] = $this->signatureAsset($tenantId, $profile, (string) $card->country);

        return [
            'name' => $employee ? trim($employee->first_name.' '.$employee->last_name) : '',
            'employeeCode' => $employee ? (string) $employee->employee_code : '',
            'designation' => $designation,
            'department' => $department,
            'email' => (string) ($employee?->email ?? ''),
            'dateOfBirth' => $employee?->date_of_birth !== null
                ? $employee->date_of_birth->format('Y-m-d')
                : '',
            'bloodGroup' => (string) ($card->blood_group ?? ''),
            'emergencyContactNumber' => (string) ($card->emergency_contact_number ?? ''),
            'country' => (string) $card->country,
            'companyProfileId' => $profile !== null ? (int) $profile->id : ($card->company_profile_id ? (int) $card->company_profile_id : null),
            'companyDisplayName' => (string) ($profile?->display_name ?? ''),
            'companyLegalName' => (string) ($profile?->legal_name ?? ''),
            'footerAddress' => $this->cardOfficeAddress($profile),
            'logoPath' => $logoPath,
            'photoPath' => $photoPath,
            'signaturePath' => $signaturePath,
            'signatureUrl' => $signatureUrl,
            'logoUrl' => $hasCompanyLogo
                ? '/id-cards/settings/logo?country='.$card->country
                : ($logoPath !== null ? '/brand/veltrix-logo.png' : null),
            'photoUrl' => $photoPath !== null ? '/id-cards/'.$card->id.'/photo' : null,
            'verifyUrl' => $this->verifyUrl((string) $card->qr_token),
            'cardNumber' => (string) ($card->card_number ?? ''),
            'issuedAt' => $card->issued_at?->toDateTimeString(),
        ];
    }

    private function cardOfficeAddress(?IdCardCompanyProfile $profile): string
    {
        $office = trim((string) ($profile?->office_address ?? ''));
        $footer = trim((string) ($profile?->footer_address ?? ''));

        if ($office === '') {
            return $footer;
        }

        if ($footer === '' || str_contains($office, $footer) || str_contains($footer, $office)) {
            return strlen($office) >= strlen($footer) ? $office : $footer;
        }

        return $office.', '.$footer;
    }

    /**
     * Refresh the latest issued PDF in place after card-only edits.
     * Keeps the same QR token and issue history row.
     */
    public function refreshIssuedPdf(int $tenantId, EmployeeIdCard $card): void
    {
        if ($card->status !== 'active') {
            return;
        }

        $issue = EmployeeIdCardIssue::query()
            ->inCompany($tenantId)
            ->where('id_card_id', $card->id)
            ->orderByDesc('id')
            ->first();

        if ($issue === null || ! $this->privatePdf($tenantId, $issue->pdf_path)) {
            return;
        }

        $payload = $this->snapshot($tenantId, $card, false);
        $bytes = $this->pdf->render($payload);
        Storage::disk('local')->put((string) $issue->pdf_path, $bytes);

        DB::table('employee_id_card_issues')
            ->where('tenant_id', $tenantId)
            ->where('id', $issue->id)
            ->update([
                'payload_json' => json_encode($this->storedPayload($payload), JSON_THROW_ON_ERROR),
            ]);
    }

    private function officialLogoPath(): ?string
    {
        $path = public_path('images/logo/veltrix-logo.png');

        return is_file($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function storedPayload(array $payload): array
    {
        unset($payload['logoPath'], $payload['photoPath'], $payload['signaturePath'], $payload['companyProfileId']);

        return $payload;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function copyPhoto(int $tenantId, int $employeeId, mixed $path, mixed $mime): array
    {
        if (! $this->privatePhoto($tenantId, $path)) {
            return [null, null];
        }

        $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $extension = 'png';
        }

        $contents = Storage::disk('local')->get((string) $path);
        if (! is_string($contents) || $contents === '') {
            return [null, null];
        }

        $copyPath = 'id-cards/'.$tenantId.'/photos/'.$employeeId.'-'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($copyPath, $contents);

        return [$copyPath, is_string($mime) && $mime !== '' ? $mime : 'image/'.$extension];
    }

    private function assertReady(int $tenantId, EmployeeIdCard $card): void
    {
        $readiness = $this->readiness($tenantId, $card);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'card' => $readiness['blockers'][0] ?? 'ID card is not ready to issue.',
            ]);
        }
    }

    private function ownedCard(int $tenantId, int $cardId): EmployeeIdCard
    {
        $card = EmployeeIdCard::query()->inCompany($tenantId)->whereKey($cardId)->first();
        abort_unless($card !== null, 404);

        return $card;
    }

    private function latestPdfPath(int $tenantId, EmployeeIdCard $card): ?string
    {
        $path = EmployeeIdCardIssue::query()
            ->inCompany($tenantId)
            ->where('id_card_id', $card->id)
            ->orderByDesc('id')
            ->value('pdf_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function downloadFilename(EmployeeIdCard $card): string
    {
        $code = 'card-'.$card->id;
        $employee = Employee::query()->inCompany((int) $card->tenant_id)->whereKey($card->employee_id)->first();
        if ($employee !== null) {
            $code = (string) $employee->employee_code;
        }

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $code).'-id-card.pdf';
    }

    private function verifyUrl(string $token): string
    {
        return url('/id-cards/verify/'.$token);
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidVerification(): array
    {
        return [
            'valid' => false,
            'status' => 'invalid',
            'name' => '',
            'employeeCode' => '',
            'designation' => '',
            'department' => '',
            'companyName' => '',
            'country' => '',
            'cardNumber' => '',
            'message' => 'This employee ID card could not be verified.',
        ];
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

    private function privateLogo(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/logos/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    private function privateSignature(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/signatures/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function signatureAsset(int $tenantId, ?IdCardCompanyProfile $profile, string $country): array
    {
        if ($profile === null || ! Schema::hasColumn('id_card_company_profiles', 'signature_path')) {
            return [null, null];
        }

        if (! $this->privateSignature($tenantId, $profile->signature_path)) {
            return [null, null];
        }

        return [
            Storage::disk('local')->path((string) $profile->signature_path),
            '/id-cards/settings/signature?country='.$country,
        ];
    }

    private function privatePdf(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/pdfs/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    /**
     * Restore the previous active card when a reissue fails after the archive step.
     */
    private function restoreReissue(int $tenantId, int $oldCardId, int $newCardId, ?string $photoCopy, int $userId): void
    {
        DB::transaction(function () use ($tenantId, $oldCardId, $newCardId, $userId): void {
            DB::table('employee_id_card_issues')
                ->where('tenant_id', $tenantId)
                ->where('id_card_id', $newCardId)
                ->delete();

            DB::table('employee_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('id', $newCardId)
                ->delete();

            DB::table('employee_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('id', $oldCardId)
                ->update([
                    'status' => 'active',
                    'deactivated_at' => null,
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);
        });

        if (is_string($photoCopy) && $photoCopy !== '' && $this->privatePhoto($tenantId, $photoCopy)) {
            $stillUsed = DB::table('employee_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('photo_path', $photoCopy)
                ->exists();

            if (! $stillUsed) {
                Storage::disk('local')->delete($photoCopy);
            }
        }
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
    private function manageContext(User $actor): array
    {
        return $this->context($actor, 'idCardsManage');
    }

    /**
     * @return array{tenantId: int, userId: int}
     */
    private function issueContext(User $actor): array
    {
        return $this->context($actor, 'idCardsIssue');
    }

    /**
     * @return array{tenantId: int, userId: int}
     */
    private function context(User $actor, string $permission): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions[$permission] ?? false) !== true || $actor->companyId() === null) {
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
}
