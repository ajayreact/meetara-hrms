<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PayrollSettings
{
    /**
     * Company payroll rules that are safe to show. Statutory amounts stay unconfigured.
     *
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $companyId = $user->companyId();
        abort_if($companyId === null || $user->applicationRole() !== 'company_admin', 403);

        return $this->payload($this->currency($companyId));
    }

    public function save(User $actor, ?string $currency): void
    {
        $companyId = $actor->companyId();
        abort_if($companyId === null || $actor->applicationRole() !== 'company_admin', 403);
        abort_unless(Schema::hasTable('company_payroll_settings'), 404);

        $currency = $currency !== null && $currency !== '' ? strtoupper($currency) : null;

        DB::transaction(function () use ($actor, $companyId, $currency): void {
            $existing = DB::table('company_payroll_settings')->where('tenant_id', $companyId)->first();

            if ($existing === null) {
                DB::table('company_payroll_settings')->insert([
                    'tenant_id' => $companyId,
                    'currency' => $currency,
                    'updated_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('company_payroll_settings')->where('tenant_id', $companyId)->update([
                    'currency' => $currency,
                    'updated_by' => $actor->id,
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('company_payroll_setting_events')) {
                DB::table('company_payroll_setting_events')->insert([
                    'tenant_id' => $companyId,
                    'actor_id' => $actor->id,
                    'action' => $currency === null ? 'currency_cleared' : 'currency_saved',
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function suggestedCurrency(User $user): ?string
    {
        $companyId = $user->companyId();

        if ($companyId === null) {
            return null;
        }

        return $this->currency($companyId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $currency): array
    {
        return [
            'currency' => $currency,
            'currencyRule' => 'Each salary record keeps the currency saved on that record. This value is only a default for the next salary record.',
            'period' => 'Monthly',
            'periodNote' => 'One payroll run is allowed for each company and month. Another pay frequency is not available.',
            'statutory' => 'Not configured',
            'statutoryNote' => 'PF, ESI, LWF, TDS, and professional tax are not configured. Approval and processing stay blocked. A zero deduction is not a configuration and is not an exemption.',
            'unpaidLeave' => 'Not configured',
            'unpaidLeaveNote' => 'Approved leave is counted. It does not reduce pay. Pending leave is not deducted.',
            'attendanceDeduction' => 'Not configured',
            'attendanceNote' => 'Missing attendance is flagged. It is not treated as unpaid.',
            'proration' => 'Not configured',
            'prorationNote' => 'An employee who joins after the first day of the month is left out of that run. Pay is not prorated.',
            'salaryVisibility' => 'Processed payslip only',
            'salaryVisibilityNote' => 'An employee can open only their own processed payslips. Salary administration stays with HR, Accounts, and Head of Operations.',
        ];
    }

    private function currency(int $companyId): ?string
    {
        if (! Schema::hasTable('company_payroll_settings')) {
            return null;
        }

        $currency = DB::table('company_payroll_settings')->where('tenant_id', $companyId)->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : null;
    }
}
