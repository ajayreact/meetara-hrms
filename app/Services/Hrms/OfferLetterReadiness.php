<?php

namespace App\Services\Hrms;

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
            'action' => 'Store an approved template version with the approving user and approval time. Legal review is required. A Company Admin or HR user cannot self-certify the wording.',
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

        $verified = DB::table('company_legal_profiles')
            ->where('tenant_id', $companyId)
            ->when($country !== null && Schema::hasColumn('company_legal_profiles', 'country'), fn ($query) => $query->where('country', $country))
            ->where('status', 'verified')
            ->whereNotNull('verified_by')
            ->whereNotNull('verified_at')
            ->whereColumn('verified_by', '!=', 'updated_by')
            ->exists();

        return $verified ? null : $blocked;
    }
}
