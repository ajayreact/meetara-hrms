<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\Schema;

/**
 * Statutory payroll is blocked until a reviewed configuration exists.
 * This class does not store rates and does not treat zero as an exemption.
 */
class PayrollStatutoryReadiness
{
    /**
     * Proof columns that are intentionally absent. A company-level flag, even if
     * added later, cannot by itself authorize payroll for every employee.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'provident_fund' => 'Provident Fund',
        'employee_state_insurance' => 'Employee State Insurance',
        'labour_welfare_fund' => 'Labour Welfare Fund',
        'tax_deducted_at_source' => 'Tax Deducted at Source',
        'professional_tax' => 'Professional tax',
    ];

    public function ready(): bool
    {
        return $this->issues() === [];
    }

    public function assertReady(): void
    {
        if ($this->ready()) {
            return;
        }

        $labels = implode(', ', array_column($this->issues(), 'label'));

        throw new PayrollRejected(
            'Payroll cannot be approved or processed. Statutory readiness is unresolved for '.$labels.'. A missing setting or a zero deduction is not an exemption.'
        );
    }

    /**
     * @return list<array{key: string, label: string, state: string, action: string}>
     */
    public function issues(): array
    {
        $columns = Schema::hasTable('company_payroll_settings')
            ? Schema::getColumnListing('company_payroll_settings')
            : [];
        $issues = [];

        foreach (self::CATEGORIES as $key => $label) {
            $decision = $key.'_decision';
            $audit = $key.'_decided_by';

            if (! in_array($decision, $columns, true)) {
                $issues[] = $this->issue($key, $label, 'not_configured');

                continue;
            }

            $state = in_array($audit, $columns, true) ? 'invalid' : 'incomplete';
            $issues[] = $this->issue($key, $label, $state);
        }

        return $issues;
    }

    /**
     * @return array{ready: bool, approvalBlocked: bool, processingBlocked: bool, items: list<array{key: string, label: string, state: string, action: string}>}
     */
    public function present(): array
    {
        $issues = $this->issues();

        return [
            'ready' => $issues === [],
            'approvalBlocked' => $issues !== [],
            'processingBlocked' => $issues !== [],
            'items' => $issues,
        ];
    }

    /**
     * Display warnings for a calculated draft. Approval does not read these rows.
     *
     * @return list<array{level: string, message: string}>
     */
    public function draftWarnings(): array
    {
        return [
            ['level' => 'warning', 'message' => 'Statutory deductions are not configured. PF, ESI, LWF, and TDS were left at 0.00.'],
            ['level' => 'warning', 'message' => 'This payroll is not ready for live processing. Professional tax is not configured, and no statutory rate was applied.'],
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, action: string}
     */
    private function issue(string $key, string $label, string $state): array
    {
        $action = match ($state) {
            'not_configured' => 'A qualified reviewer must supply an authorized rule or an explicit non-applicability decision. Payroll settings currently store currency only.',
            'incomplete' => 'The stored decision has no authorized actor, so it cannot be used.',
            default => 'The stored decision is not a validated statutory rule and cannot authorize payroll.',
        };

        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'action' => $action,
        ];
    }
}
