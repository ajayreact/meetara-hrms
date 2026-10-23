<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\CandidateOnboarding;
use App\Support\CandidateForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CandidateSubmissionController extends Controller
{
    public function __construct(private readonly CandidateOnboarding $onboarding) {}

    public function show(Request $request, string $token): Response
    {
        $page = $this->onboarding->publicPage($token);
        abort_if($page === null, 404);

        return Inertia::render($page['open'] ? 'apply/form' : 'apply/unavailable', [
            'token' => $token,
            'form' => $page,
            'uploadNotice' => $request->query('notice') === 'request-too-large' ? CandidateForm::REQUEST_TOO_LARGE : null,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        try {
            $reference = $this->onboarding->submit($token, $request);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->withInput($request->except(CandidateForm::sensitiveKeys()));
        }

        return redirect()
            ->route('apply.received', ['token' => $token])
            ->with('candidate_reference', $reference);
    }

    public function received(Request $request, string $token): Response
    {
        abort_if($this->onboarding->publicPage($token) === null, 404);

        return Inertia::render('apply/received', [
            'reference' => $request->session()->get('candidate_reference'),
        ]);
    }

}
