<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Offer letters stay unissued until a reviewed template and verified company
 * details exist. This class does not approve wording or treat a company
 * display name or address as legal verification.
 */
class OfferLetterReadiness
{
    public function ready(): bool
    {
        return $this->issues() === [];
    }

    public function assertReady(): void
    {
        if ($this->ready()) {
            return;
        }

        throw ValidationException::withMessages([
            'letter' => 'Offer letters cannot be issued. Template approval is not on file, and company legal details are not verified. A draft preview is not an approved offer letter.',
        ]);
    }

    /**
     * @return list<array{key: string, label: string, state: string, action: string}>
     */
    public function issues(): array
    {
        return [
            $this->templateIssue(),
            $this->companyIssue(),
        ];
    }

    /**
     * @return array{ready: bool, canIssue: bool, status: string, draftNote: string, items: list<array{key: string, label: string, state: string, action: string}>}
     */
    public function present(): array
    {
        $issues = $this->issues();

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
     * @return array{key: string, label: string, state: string, action: string}
     */
    private function templateIssue(): array
    {
        $columns = Schema::hasTable('offer_letter_templates')
            ? Schema::getColumnListing('offer_letter_templates')
            : [];
        $required = ['tenant_id', 'version', 'body', 'approved_by', 'approved_at'];
        $missing = array_filter($required, fn (string $column): bool => ! in_array($column, $columns, true));

        if (! Schema::hasTable('offer_letter_templates')) {
            $state = 'not_configured';
        } elseif ($missing !== []) {
            $state = 'incomplete';
        } else {
            $state = 'invalid';
        }

        $action = match ($state) {
            'not_configured' => 'Store an approved template version with the approving user and approval time. Legal review is required. A Company Admin or HR user cannot self-certify the wording.',
            'incomplete' => 'The template record does not contain a version, body, approver, and approval time.',
            default => 'A stored template is not a completed legal review. Approval cannot be inferred from the row.',
        };

        return [
            'key' => 'template_approval',
            'label' => 'Approved template',
            'state' => $state,
            'action' => $action,
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, action: string}
     */
    private function companyIssue(): array
    {
        $columns = Schema::hasTable('tenants') ? Schema::getColumnListing('tenants') : [];
        $required = ['legal_name', 'legal_address', 'legal_details_verified_by', 'legal_details_verified_at'];
        $missing = array_filter($required, fn (string $column): bool => ! in_array($column, $columns, true));

        if ($missing !== []) {
            $state = 'not_configured';
            $action = 'Verified legal name and legal address need an authorizing user and verification time. The company display name and address text are not that verification.';
        } else {
            $state = 'invalid';
            $action = 'Company legal columns are present, but this application has no completed verification workflow that can authorize issuance.';
        }

        return [
            'key' => 'company_legal_details',
            'label' => 'Company legal details',
            'state' => $state,
            'action' => $action,
        ];
    }
}
