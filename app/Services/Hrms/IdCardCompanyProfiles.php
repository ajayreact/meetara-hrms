<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
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

class IdCardCompanyProfiles
{
    /**
     * @return array{
     *     country: string,
     *     countries: list<array{value: string, label: string}>,
     *     profile: array<string, mixed>,
     *     preview: array<string, mixed>
     * }
     */
    public function page(User $actor, string $country): array
    {
        $context = $this->context($actor);
        $country = $this->country($country);
        $profile = $this->present($context['tenantId'], $country);

        return [
            'country' => $country,
            'countries' => [
                ['value' => 'india', 'label' => 'India'],
                ['value' => 'usa', 'label' => 'USA'],
            ],
            'profile' => $profile,
            'preview' => $this->previewPayload($profile),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{message: string, profile: array<string, mixed>}
     */
    public function save(User $actor, string $country, array $input, ?UploadedFile $logo = null, ?UploadedFile $signature = null): array
    {
        $context = $this->context($actor);
        $country = $this->country($country);

        if (! Schema::hasTable('id_card_company_profiles')) {
            throw ValidationException::withMessages(['profile' => 'ID card company profiles are not available yet.']);
        }

        $row = IdCardCompanyProfile::query()
            ->inCompany($context['tenantId'])
            ->where('country', $country)
            ->first();

        $logoPath = $row?->logo_path;
        $logoMime = $row?->logo_mime;
        $previousLogo = is_string($logoPath) ? $logoPath : null;
        $signaturePath = $row?->signature_path;
        $signatureMime = $row?->signature_mime;
        $previousSignature = is_string($signaturePath) ? $signaturePath : null;

        if ($logo !== null) {
            // Store the new logo first; delete the previous file only after the DB write succeeds.
            [$logoPath, $logoMime] = $this->storeLogo($context['tenantId'], $country, $logo, null);
        }

        if ($signature !== null) {
            [$signaturePath, $signatureMime] = $this->storeSignature($context['tenantId'], $country, $signature, null);
        }

        $payload = [
            'legal_name' => trim((string) ($input['legal_name'] ?? '')),
            'display_name' => trim((string) ($input['display_name'] ?? '')),
            'office_address' => trim((string) ($input['office_address'] ?? '')),
            'contact_number' => $this->nullableString($input['contact_number'] ?? null),
            'email' => $this->nullableString($input['email'] ?? null),
            'website' => $this->nullableString($input['website'] ?? null),
            'footer_address' => trim((string) ($input['footer_address'] ?? '')),
            'logo_path' => $logoPath,
            'logo_mime' => $logoMime,
            'signature_path' => $signaturePath,
            'signature_mime' => $signatureMime,
            'status' => 'active',
            'updated_by' => $context['userId'],
            'updated_at' => now(),
        ];

        try {
            if ($row === null) {
                $payload['tenant_id'] = $context['tenantId'];
                $payload['country'] = $country;
                $payload['created_at'] = now();
                $id = DB::table('id_card_company_profiles')->insertGetId($payload);
                $row = IdCardCompanyProfile::query()->inCompany($context['tenantId'])->whereKey($id)->firstOrFail();
                $message = ($country === 'india' ? 'India' : 'USA').' ID card company details saved.';
            } else {
                DB::table('id_card_company_profiles')
                    ->where('tenant_id', $context['tenantId'])
                    ->where('id', $row->id)
                    ->update($payload);
                $row = $row->fresh();
                $message = ($country === 'india' ? 'India' : 'USA').' ID card company details updated.';
            }
        } catch (\Throwable $exception) {
            if ($logo !== null && is_string($logoPath) && $logoPath !== $previousLogo && $this->privateLogo($context['tenantId'], $logoPath)) {
                Storage::disk('local')->delete($logoPath);
            }

            if ($signature !== null && is_string($signaturePath) && $signaturePath !== $previousSignature && $this->privateSignature($context['tenantId'], $signaturePath)) {
                Storage::disk('local')->delete($signaturePath);
            }

            throw $exception;
        }

        if ($logo !== null && is_string($previousLogo) && $previousLogo !== '' && $previousLogo !== $logoPath && $this->privateLogo($context['tenantId'], $previousLogo)) {
            Storage::disk('local')->delete($previousLogo);
        }

        if ($signature !== null && is_string($previousSignature) && $previousSignature !== '' && $previousSignature !== $signaturePath && $this->privateSignature($context['tenantId'], $previousSignature)) {
            Storage::disk('local')->delete($previousSignature);
        }

        $profile = $this->present($context['tenantId'], $country, $row);

        return [
            'message' => $message,
            'profile' => $profile,
        ];
    }

    public function logoResponse(User $actor, string $country): StreamedResponse
    {
        $context = $this->context($actor);
        $country = $this->country($country);
        $row = IdCardCompanyProfile::query()
            ->inCompany($context['tenantId'])
            ->where('country', $country)
            ->first();
        abort_unless($row !== null && $this->privateLogo($context['tenantId'], $row->logo_path), 404);

        return Storage::disk('local')->response((string) $row->logo_path, basename((string) $row->logo_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => (string) ($row->logo_mime ?: 'application/octet-stream'),
        ]);
    }

    public function signatureResponse(User $actor, string $country): StreamedResponse
    {
        $context = $this->context($actor);
        $country = $this->country($country);
        $row = IdCardCompanyProfile::query()
            ->inCompany($context['tenantId'])
            ->where('country', $country)
            ->first();
        abort_unless($row !== null && $this->privateSignature($context['tenantId'], $row->signature_path), 404);

        return Storage::disk('local')->response((string) $row->signature_path, basename((string) $row->signature_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => (string) ($row->signature_mime ?: 'application/octet-stream'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(int $tenantId, string $country, ?IdCardCompanyProfile $row = null): array
    {
        $country = $this->country($country);
        $row ??= IdCardCompanyProfile::query()->inCompany($tenantId)->where('country', $country)->first();

        if ($row === null) {
            return [
                'id' => null,
                'country' => $country,
                'legalName' => '',
                'displayName' => '',
                'officeAddress' => '',
                'contactNumber' => '',
                'email' => '',
                'website' => '',
                'footerAddress' => '',
                'hasLogo' => false,
                'logoUrl' => null,
                'hasSignature' => false,
                'signatureUrl' => null,
                'status' => 'active',
                'updatedAt' => null,
            ];
        }

        $hasLogo = $this->privateLogo($tenantId, $row->logo_path);
        $hasSignature = $this->privateSignature($tenantId, $row->signature_path ?? null);

        return [
            'id' => (int) $row->id,
            'country' => (string) $row->country,
            'legalName' => (string) $row->legal_name,
            'displayName' => (string) $row->display_name,
            'officeAddress' => (string) $row->office_address,
            'contactNumber' => (string) ($row->contact_number ?? ''),
            'email' => (string) ($row->email ?? ''),
            'website' => (string) ($row->website ?? ''),
            'footerAddress' => (string) $row->footer_address,
            'hasLogo' => $hasLogo,
            'logoUrl' => $hasLogo ? '/id-cards/settings/logo?country='.$country : null,
            'hasSignature' => $hasSignature,
            'signatureUrl' => $hasSignature ? '/id-cards/settings/signature?country='.$country : null,
            'status' => (string) $row->status,
            'updatedAt' => $row->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function previewPayload(array $profile): array
    {
        return [
            'displayName' => (string) ($profile['displayName'] ?? ''),
            'legalName' => (string) ($profile['legalName'] ?? ''),
            'hasLogo' => (bool) ($profile['hasLogo'] ?? false),
            'logoUrl' => $profile['logoUrl'] ?? null,
            'hasSignature' => (bool) ($profile['hasSignature'] ?? false),
            'signatureUrl' => $profile['signatureUrl'] ?? null,
            'footerAddress' => (string) ($profile['footerAddress'] ?? ''),
            'officeAddress' => (string) ($profile['officeAddress'] ?? ''),
            'contactNumber' => (string) ($profile['contactNumber'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'website' => (string) ($profile['website'] ?? ''),
            'country' => (string) ($profile['country'] ?? 'india'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeLogo(int $tenantId, string $country, UploadedFile $logo, ?string $previous): array
    {
        $extension = strtolower((string) $logo->getClientOriginalExtension());
        $mime = (string) ($logo->getMimeType() ?: '');
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

        if (! array_key_exists($extension, $allowed) || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'logo' => 'Upload a PNG or JPEG logo up to 2 MB.',
            ]);
        }

        $contents = file_get_contents($logo->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['logo' => 'The logo file could not be read.']);
        }

        $path = 'id-cards/'.$tenantId.'/logos/'.$country.'-'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        if (is_string($previous) && $previous !== '' && $previous !== $path && $this->privateLogo($tenantId, $previous)) {
            Storage::disk('local')->delete($previous);
        }

        return [$path, $allowed[$extension]];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeSignature(int $tenantId, string $country, UploadedFile $signature, ?string $previous): array
    {
        $extension = strtolower((string) $signature->getClientOriginalExtension());
        $mime = (string) ($signature->getMimeType() ?: '');
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

        if (! array_key_exists($extension, $allowed) || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'signature' => 'Upload a PNG or JPEG signature up to 2 MB.',
            ]);
        }

        $contents = file_get_contents($signature->getRealPath());

        if (! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages(['signature' => 'The signature file could not be read.']);
        }

        $path = 'id-cards/'.$tenantId.'/signatures/'.$country.'-'.Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        if (is_string($previous) && $previous !== '' && $previous !== $path && $this->privateSignature($tenantId, $previous)) {
            Storage::disk('local')->delete($previous);
        }

        return [$path, $allowed[$extension]];
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
     * @return array{tenantId: int, userId: int}
     */
    private function context(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['idCardsManage'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        if (! Schema::hasTable('id_card_company_profiles')) {
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
