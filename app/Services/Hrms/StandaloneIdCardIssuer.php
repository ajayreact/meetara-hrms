<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\EmployeeIdCard;
use App\Models\IdCardCompanyProfile;
use App\Models\StandaloneIdCard;
use App\Models\StandaloneIdCardIssue;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StandaloneIdCardIssuer
{
    public function __construct(private readonly EmployeeIdCardPdf $pdf) {}

    /**
     * @return array{message: string, cardId: int}
     */
    public function issue(User $actor, StandaloneIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if (! in_array($card->status, ['draft', 'active'], true)) {
            throw ValidationException::withMessages(['card' => 'Only draft or active standalone ID cards can be issued.']);
        }

        $this->assertReady($context['tenantId'], $card);

        $payload = $this->snapshot($context['tenantId'], $card);
        $bytes = $this->pdf->render($payload);

        return DB::transaction(function () use ($context, $card, $payload, $bytes): array {
            StandaloneIdCard::query()
                ->inCompany($context['tenantId'])
                ->where('card_number', $card->card_number)
                ->whereIn('status', ['draft', 'active'])
                ->lockForUpdate()
                ->get();

            DB::table('standalone_id_cards')
                ->where('tenant_id', $context['tenantId'])
                ->where('card_number', $card->card_number)
                ->where('status', 'active')
                ->where('id', '!=', $card->id)
                ->update([
                    'status' => 'inactive',
                    'deactivated_at' => now(),
                    'updated_by' => $context['userId'],
                    'updated_at' => now(),
                ]);

            $issueId = DB::table('standalone_id_card_issues')->insertGetId([
                'tenant_id' => $context['tenantId'],
                'id_card_id' => $card->id,
                'payload_json' => json_encode($this->storedPayload($payload), JSON_THROW_ON_ERROR),
                'issued_by' => $context['userId'],
                'created_at' => now(),
            ]);

            $pdfPath = 'id-cards/'.$context['tenantId'].'/standalone/pdfs/'.$card->id.'-'.$issueId.'.pdf';
            Storage::disk('local')->put($pdfPath, $bytes);
            DB::table('standalone_id_card_issues')
                ->where('tenant_id', $context['tenantId'])
                ->where('id', $issueId)
                ->update(['pdf_path' => $pdfPath]);

            DB::table('standalone_id_cards')
                ->where('tenant_id', $context['tenantId'])
                ->where('id', $card->id)
                ->update([
                    'status' => 'active',
                    'issued_at' => now(),
                    'deactivated_at' => null,
                    'company_profile_id' => $payload['companyProfileId'] ?? $card->company_profile_id,
                    'updated_by' => $context['userId'],
                    'updated_at' => now(),
                ]);

            return [
                'message' => 'Standalone ID card issued.',
                'cardId' => (int) $card->id,
            ];
        });
    }

    /**
     * @return array{message: string, cardId: int}
     */
    public function reissue(User $actor, StandaloneIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if ($card->status !== 'active') {
            throw ValidationException::withMessages(['card' => 'Only an active standalone ID card can be reissued.']);
        }

        $this->assertReady($context['tenantId'], $card);

        $newId = null;
        $photoCopy = null;
        $originalNumber = (string) $card->card_number;

        try {
            $newId = DB::transaction(function () use ($context, $card, &$photoCopy): int {
                $locked = StandaloneIdCard::query()
                    ->inCompany($context['tenantId'])
                    ->whereKey($card->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->status !== 'active') {
                    throw ValidationException::withMessages(['card' => 'Only an active standalone ID card can be reissued.']);
                }

                $archivedNumber = $locked->card_number.'#R'.$locked->id;

                DB::table('standalone_id_cards')
                    ->where('tenant_id', $context['tenantId'])
                    ->where('id', $locked->id)
                    ->update([
                        'status' => 'reissued',
                        'card_number' => $archivedNumber,
                        'deactivated_at' => now(),
                        'updated_by' => $context['userId'],
                        'updated_at' => now(),
                    ]);

                [$photoPath, $photoMime] = $this->copyPhoto(
                    $context['tenantId'],
                    $locked->photo_path,
                    $locked->photo_mime,
                );
                $photoCopy = $photoPath;

                return (int) DB::table('standalone_id_cards')->insertGetId([
                    'tenant_id' => $context['tenantId'],
                    'country' => $locked->country,
                    'company_profile_id' => $locked->company_profile_id,
                    'full_name' => $locked->full_name,
                    'card_number' => $locked->card_number,
                    'designation' => $locked->designation,
                    'department' => $locked->department,
                    'email' => $locked->email,
                    'phone' => $locked->phone,
                    'date_of_birth' => $locked->date_of_birth instanceof \DateTimeInterface
                        ? $locked->date_of_birth->format('Y-m-d')
                        : ($locked->date_of_birth ?: null),
                    'blood_group' => $locked->blood_group,
                    'emergency_contact_number' => $locked->emergency_contact_number,
                    'photo_path' => $photoPath,
                    'photo_mime' => $photoMime,
                    'status' => 'draft',
                    'qr_token' => $this->freshQrToken(),
                    'issued_at' => null,
                    'deactivated_at' => null,
                    'reissued_from_id' => $locked->id,
                    'created_by' => $context['userId'],
                    'updated_by' => $context['userId'],
                    'import_batch_id' => $locked->import_batch_id ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $newCard = $this->ownedCard($context['tenantId'], $newId);
            $issued = $this->issue($actor, $newCard);

            return [
                'message' => 'Standalone ID card reissued with a new verification QR.',
                'cardId' => $issued['cardId'],
            ];
        } catch (\Throwable $exception) {
            if ($newId !== null) {
                $this->restoreReissue(
                    $context['tenantId'],
                    (int) $card->id,
                    $originalNumber,
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
    public function deactivate(User $actor, StandaloneIdCard $card): array
    {
        $context = $this->issueContext($actor);
        $card = $this->ownedCard($context['tenantId'], (int) $card->id);

        if (! in_array($card->status, ['draft', 'active'], true)) {
            throw ValidationException::withMessages(['card' => 'This standalone ID card is already inactive.']);
        }

        DB::table('standalone_id_cards')
            ->where('tenant_id', $context['tenantId'])
            ->where('id', $card->id)
            ->update([
                'status' => 'inactive',
                'deactivated_at' => now(),
                'updated_by' => $context['userId'],
                'updated_at' => now(),
            ]);

        return [
            'message' => 'Standalone ID card deactivated. The QR verification link is no longer valid.',
            'cardId' => (int) $card->id,
        ];
    }

    public function download(User $actor, StandaloneIdCard $card): StreamedResponse
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
     * Public verification payload — limited professional details only.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        if (! Schema::hasTable('standalone_id_cards') || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->invalidVerification();
        }

        $card = StandaloneIdCard::query()->where('qr_token', $token)->first();

        if ($card === null) {
            return $this->invalidVerification();
        }

        $valid = $card->status === 'active';

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
                'message' => 'This ID card is no longer valid.',
            ];
        }

        $snapshot = StandaloneIdCardIssue::query()
            ->inCompany((int) $card->tenant_id)
            ->where('id_card_id', $card->id)
            ->orderByDesc('id')
            ->value('payload_json');

        if (is_array($snapshot)) {
            return [
                'valid' => true,
                'status' => 'active',
                'name' => (string) ($snapshot['name'] ?? ''),
                'employeeCode' => (string) ($snapshot['employeeCode'] ?? $snapshot['cardNumber'] ?? ''),
                'designation' => (string) ($snapshot['designation'] ?? ''),
                'department' => (string) ($snapshot['department'] ?? ''),
                'companyName' => (string) ($snapshot['companyDisplayName'] ?? ''),
                'country' => (string) ($snapshot['country'] ?? $card->country),
                'cardNumber' => (string) ($snapshot['cardNumber'] ?? $card->card_number),
                'message' => 'This ID card is active.',
            ];
        }

        $profile = IdCardCompanyProfile::query()
            ->inCompany((int) $card->tenant_id)
            ->whereKey($card->company_profile_id)
            ->first();

        return [
            'valid' => true,
            'status' => 'active',
            'name' => (string) $card->full_name,
            'employeeCode' => (string) $card->card_number,
            'designation' => (string) ($card->designation ?? ''),
            'department' => (string) ($card->department ?? ''),
            'companyName' => (string) ($profile?->display_name ?? ''),
            'country' => (string) $card->country,
            'cardNumber' => (string) $card->card_number,
            'message' => 'This ID card is active.',
        ];
    }

    /**
     * @return array{ready: bool, blockers: list<string>}
     */
    public function readiness(int $tenantId, StandaloneIdCard $card): array
    {
        $blockers = [];

        if (trim((string) $card->full_name) === '') {
            $blockers[] = 'Full name is required.';
        }

        if (trim((string) $card->card_number) === '') {
            $blockers[] = 'Card number is required.';
        }

        if (trim((string) ($card->designation ?? '')) === '') {
            $blockers[] = 'Designation is required.';
        }

        if (trim((string) ($card->department ?? '')) === '') {
            $blockers[] = 'Department is required.';
        }

        if ($card->date_of_birth === null || trim((string) $card->date_of_birth) === '') {
            $blockers[] = 'Date of birth is required.';
        }

        if (trim((string) ($card->blood_group ?? '')) === '') {
            $blockers[] = 'Blood group is required.';
        }

        if (trim((string) ($card->emergency_contact_number ?? '')) === '') {
            $blockers[] = 'Emergency contact is required.';
        }

        if (! $this->privatePhoto($tenantId, $card->photo_path)) {
            $blockers[] = 'Photo is required.';
        }

        $profile = IdCardCompanyProfile::query()
            ->inCompany($tenantId)
            ->where('country', $card->country)
            ->first();

        if ($profile === null) {
            $blockers[] = 'Save the '.strtoupper((string) $card->country).' ID card company profile first.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentIssueState(int $tenantId, ?StandaloneIdCard $card): array
    {
        if ($card === null) {
            return [
                'ready' => false,
                'blockers' => ['Create and save a standalone card first.'],
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
            'pdfUrl' => $hasPdf ? '/id-cards/standalone/'.$card->id.'/pdf' : null,
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

    public function freshQrToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $employeeHit = Schema::hasTable('employee_id_cards')
                && EmployeeIdCard::query()->where('qr_token', $token)->exists();
            $standaloneHit = Schema::hasTable('standalone_id_cards')
                && StandaloneIdCard::query()->where('qr_token', $token)->exists();
        } while ($employeeHit || $standaloneHit);

        return $token;
    }

    /**
     * Restore the previous active card when a reissue fails after the archive step.
     */
    private function restoreReissue(
        int $tenantId,
        int $oldCardId,
        string $originalNumber,
        int $newCardId,
        ?string $photoCopy,
        int $userId,
    ): void {
        DB::transaction(function () use ($tenantId, $oldCardId, $originalNumber, $newCardId, $userId): void {
            DB::table('standalone_id_card_issues')
                ->where('tenant_id', $tenantId)
                ->where('id_card_id', $newCardId)
                ->delete();

            DB::table('standalone_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('id', $newCardId)
                ->delete();

            DB::table('standalone_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('id', $oldCardId)
                ->update([
                    'status' => 'active',
                    'card_number' => $originalNumber,
                    'deactivated_at' => null,
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ]);
        });

        if (is_string($photoCopy) && $photoCopy !== '' && $this->privatePhoto($tenantId, $photoCopy)) {
            $stillUsed = DB::table('standalone_id_cards')
                ->where('tenant_id', $tenantId)
                ->where('photo_path', $photoCopy)
                ->exists();

            if (! $stillUsed) {
                Storage::disk('local')->delete($photoCopy);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(int $tenantId, StandaloneIdCard $card, bool $requireReady = true): array
    {
        if ($requireReady) {
            $this->assertReady($tenantId, $card);
        }

        $profile = IdCardCompanyProfile::query()
            ->inCompany($tenantId)
            ->where('country', $card->country)
            ->first();

        if ($requireReady && $profile !== null && (int) ($card->company_profile_id ?? 0) !== (int) $profile->id) {
            $card->company_profile_id = $profile->id;
        }

        $logoPath = $profile && $this->privateLogo($tenantId, $profile->logo_path)
            ? Storage::disk('local')->path((string) $profile->logo_path)
            : $this->officialLogoPath();
        $photoPath = $this->privatePhoto($tenantId, $card->photo_path)
            ? Storage::disk('local')->path((string) $card->photo_path)
            : null;
        $hasCompanyLogo = $profile && $this->privateLogo($tenantId, $profile->logo_path);
        [$signaturePath, $signatureUrl] = $this->signatureAsset($tenantId, $profile, (string) $card->country);
        $dateOfBirth = $card->date_of_birth instanceof \DateTimeInterface
            ? $card->date_of_birth->format('Y-m-d')
            : trim((string) ($card->date_of_birth ?? ''));

        return [
            'name' => (string) $card->full_name,
            'employeeCode' => (string) $card->card_number,
            'designation' => (string) ($card->designation ?? ''),
            'department' => (string) ($card->department ?? ''),
            'email' => (string) ($card->email ?? ''),
            'dateOfBirth' => $dateOfBirth,
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
            'photoUrl' => $photoPath !== null ? '/id-cards/standalone/'.$card->id.'/photo' : null,
            'verifyUrl' => $this->verifyUrl((string) $card->qr_token),
            'cardNumber' => (string) $card->card_number,
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
    private function copyPhoto(int $tenantId, mixed $path, mixed $mime): array
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

        $copyPath = 'id-cards/'.$tenantId.'/standalone/photos/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($copyPath, $contents);

        return [$copyPath, is_string($mime) && $mime !== '' ? $mime : 'image/'.$extension];
    }

    private function assertReady(int $tenantId, StandaloneIdCard $card): void
    {
        $readiness = $this->readiness($tenantId, $card);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'card' => $readiness['blockers'][0] ?? 'Standalone ID card is not ready to issue.',
            ]);
        }
    }

    private function ownedCard(int $tenantId, int $cardId): StandaloneIdCard
    {
        $card = StandaloneIdCard::query()->inCompany($tenantId)->whereKey($cardId)->first();
        abort_unless($card !== null, 404);

        return $card;
    }

    private function latestPdfPath(int $tenantId, StandaloneIdCard $card): ?string
    {
        $path = StandaloneIdCardIssue::query()
            ->inCompany($tenantId)
            ->where('id_card_id', $card->id)
            ->orderByDesc('id')
            ->value('pdf_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function downloadFilename(StandaloneIdCard $card): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $card->card_number).'-id-card.pdf';
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
            'message' => 'This ID card could not be verified.',
        ];
    }

    private function privatePhoto(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/standalone/photos/')) {
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

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/standalone/pdfs/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
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

        if (! Schema::hasTable('standalone_id_cards')) {
            abort(404);
        }

        return [
            'tenantId' => (int) $actor->companyId(),
            'userId' => (int) $actor->id,
        ];
    }
}
