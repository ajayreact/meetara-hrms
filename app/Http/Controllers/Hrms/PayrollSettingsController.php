<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\UpdatePayrollSettingsRequest;
use App\Services\Hrms\PayrollSettings;
use Illuminate\Http\RedirectResponse;

class PayrollSettingsController extends Controller
{
    public function update(UpdatePayrollSettingsRequest $request, PayrollSettings $settings): RedirectResponse
    {
        $currency = $request->validated('currency');
        $settings->save($request->user(), is_string($currency) ? $currency : null);

        return back()->with('status', 'Payroll currency default saved. Existing salary records were not changed, and deductions were not activated.');
    }
}
