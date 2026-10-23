<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\RotateCandidateFormTokenRequest;
use App\Http\Requests\Hrms\UpdateCandidateFormSettingsRequest;
use App\Services\Hrms\CandidateOnboarding;
use Illuminate\Http\RedirectResponse;

class CandidateFormSettingsController extends Controller
{
    public function update(UpdateCandidateFormSettingsRequest $request, CandidateOnboarding $onboarding): RedirectResponse
    {
        $onboarding->save($request->user(), $request->boolean('enabled'), $request->boolean('confirm_disable'));

        return back()->with('status', $request->boolean('enabled')
            ? 'The public candidate form is enabled. New submissions can be sent from the public link.'
            : 'The public candidate form is disabled. Existing submissions were kept.');
    }

    public function rotate(RotateCandidateFormTokenRequest $request, CandidateOnboarding $onboarding): RedirectResponse
    {
        $onboarding->rotateToken($request->user(), $request->boolean('confirm_rotate'));

        return back()->with('status', 'The public link was replaced. The previous link no longer opens the form. Existing submissions were kept.');
    }
}
