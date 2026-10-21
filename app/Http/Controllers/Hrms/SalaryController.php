<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreSalaryStructureRequest;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayrollRejected;
use App\Services\Hrms\PayrollSettings;
use App\Services\Hrms\SalaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'hr', 'accounts'], true), 403);

        return Inertia::render('payroll/salary', [
            ...$directory->salaries($request->user(), $request->string('search')->toString()),
            'suggestedCurrency' => app(PayrollSettings::class)->suggestedCurrency($request->user()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(StoreSalaryStructureRequest $request, SalaryService $salaries): RedirectResponse
    {
        try {
            $salaries->record($request->user(), $request->validated());
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Salary record saved.');
    }
}
