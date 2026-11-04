<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Offer letters stay unissued until a reviewed template and verified company
 * details exist. This class does not approve wording or treat a company
 * display name or address as legal verification.
 */
class OfferLetterReadiness
{
    public function ready(?int $companyId = null, ?string $country = null): bool
    {
        return $this->issues($companyId, $country) === [];
    }

    public function assertReady(): void
    {
        $this->assertReadyFor(null, null);
    }

    public function assertReadyFor(?int $companyId, ?string $country): void
    {
        if ($this->ready($companyId, $country)) {
            return;
        }

        throw ValidationException::withMessages([
            'letter' => 'Offer letters cannot be issued. Template approval is not on file, and company legal details are not verified. A draft preview is not an approved offer letter.',
        ]);
    }

    /**
     * @return list<array{key: string, label: string, state: string, action: string}>
     */
    public function issues(?int $companyId = null, ?string $country = null): array
    {
        return array_values(array_filter([
            $this->templateIssue($companyId, $country),
            $this->companyIssue($companyId, $country),
        ]));
    }

    /**
     * @return array{ready: bool, canIssue: bool, status: string, draftNote: string, items: list<array{key: string, label: string, state: string, action: string}>}
     */
    public function present(?int $companyId = null, ?string $country = null): array
    {
        $issues = $this->issues($companyId, $country);

        return [
            'ready' => $issues === [],
            'canIssue' => $issues === [],
            'status' => $issues === []
                ? 'Template approval is on file.'
                : 'Issuance blocked — template approval required',
            'draftNote' => 'A draft preview is not an approved offer letter.',
            'items' => $issues,
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, action: string}|null
     */
    private function templateIssue(?int $companyId, ?string $country): ?array
    {
        $blocked = [
            'key' => 'template_approval',
            'label' => 'Approved template',
            'state' => 'not_configured',
            'action' => 'Store an approved template version with the approving user and approval time. Legal review is required. A Head of Operations or HR user cannot self-certify the wording.',
        ];

        if ($companyId === null || ! Schema::hasTable('offer_letter_template_versions')) {
            return $blocked;
        }

        $active = DB::table('offer_letter_template_versions')
            ->where('tenant_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at')
            ->when($country !== null, fn ($query) => $query->where('country', $country))
            ->exists();

        return $active ? null : $blocked;
    }

    /**
     * @return array{key: string, label: string, state: string, action: string}|null
     */
    private function companyIssue(?int $companyId, ?string $country = null): ?array
    {
        $blocked = [
            'key' => 'company_legal_details',
            'label' => 'Company legal details',
            'state' => 'not_configured',
            'action' => 'Verified legal name and legal address need an authorizing user and verification time. The company display name and address text are not that verification.',
        ];

        if ($companyId === null || ! Schema::hasTable('company_legal_profiles')) {
            return $blocked;
        }

        $profiles = DB::table('company_legal_profiles')
            ->where('tenant_id', $companyId)
            ->when($country !== null && Schema::hasColumn('company_legal_profiles', 'country'), fn ($query) => $query->where('country', $country))
            ->get();

        foreach ($profiles as $profile) {
            if ($this->legalProfileReady($profile)) {
                return null;
            }
        }

        return $blocked;
    }

    private function legalProfileReady(object $profile): bool
    {
        foreach (['legal_name', 'legal_address', 'signatory_name', 'signatory_designation', 'hr_email'] as $field) {
            if (trim((string) ($profile->{$field} ?? '')) === '') {
                return false;
            }
        }

        $verified = (string) $profile->status === 'verified' && $profile->verified_by !== null && $profile->verified_at !== null;

        if ($verified && (int) $profile->verified_by !== (int) $profile->updated_by) {
            return true;
        }

        $india = (string) ($profile->country ?? 'india') === 'india';

        if (! $india) {
            return false;
        }

        if ($verified && $this->headOfOperations((int) $profile->verified_by)) {
            return true;
        }

        return $this->headOfOperations((int) ($profile->updated_by ?? 0));
    }

    private function headOfOperations(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $user = User::query()->find($userId);

        return $user instanceof User && $user->applicationRole() === 'company_admin';
    }
}
