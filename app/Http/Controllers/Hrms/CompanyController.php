<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CompanyStatusRequest;
use App\Http\Requests\Hrms\StoreCompanyRequest;
use App\Http\Requests\Hrms\UpdateCompanyRequest;
use App\Services\Hrms\CompanyOnboarding;
use Illuminate\Http\RedirectResponse;

class CompanyController extends Controller
{
    public function store(StoreCompanyRequest $request, CompanyOnboarding $onboarding): RedirectResponse
    {
        $validated = $request->validated();
        $company = $onboarding->create($request->user(), $validated);
        $message = trim((string) ($validated['admin_email'] ?? '')) !== ''
            ? 'Company saved. The Head of Operations account was created for this company.'
            : 'Company saved. No invitation email was sent.';

        return redirect()->route('hrms.companies', ['company' => $company->id])->with('status', $message);
    }

    public function update(UpdateCompanyRequest $request, int $company, CompanyOnboarding $onboarding): RedirectResponse
    {
        $onboarding->update($request->user(), $company, $request->validated());

        return redirect()->route('hrms.companies', ['company' => $company])->with('status', 'Company updated.');
    }

    public function status(CompanyStatusRequest $request, int $company, CompanyOnboarding $onboarding): RedirectResponse
    {
        $status = (string) $request->validated('status');
        $onboarding->transition($request->user(), $company, $status);

        return redirect()->route('hrms.companies', ['company' => $company])->with(
            'status',
            $status === 'inactive' ? 'Company deactivated. Historical records were kept.' : 'Company reactivated. Existing records were kept.'
        );
    }
}
