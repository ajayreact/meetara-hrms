<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\CandidateOnboarding;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateReviewController extends Controller
{
    public function __construct(private readonly CandidateOnboarding $onboarding) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('candidates/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'submissions' => $this->onboarding->list($actor, [
                'search' => $request->string('search')->toString(),
                'status' => $request->string('status')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'page' => $request->integer('page') ?: 1,
            ]),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $request->string('status')->toString(),
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
            ],
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function show(Request $request, int $submission): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('candidates/show', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'submission' => $this->onboarding->detail($actor, $submission),
            'notice' => $request->session()->get('status'),
            'error' => $request->session()->get('error'),
        ]);
    }

    public function status(Request $request, int $submission): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->onboarding->updateStatus($this->actor($request), $submission, $data['status'], $data['note'] ?? null);

        return back()->with('status', 'Review status saved.');
    }

    public function operations(Request $request, int $submission): RedirectResponse
    {
        $data = $request->validate([
            'payment_received' => ['required', 'boolean'],
            'amount_paid' => ['nullable', 'numeric'],
            'payment_method' => ['nullable', 'string', 'max:20'],
            'training_agreement_sent' => ['required', 'boolean'],
            'work_day' => ['nullable', 'date'],
        ]);
        $this->onboarding->updateOperations($this->actor($request), $submission, $data);

        return back()->with('status', 'Internal fields saved. No payment or payroll record was created.');
    }

    public function download(Request $request, int $submission, int $document): StreamedResponse
    {
        return $this->onboarding->download($this->actor($request), $submission, $document);
    }

    public function banking(Request $request, int $submission): JsonResponse
    {
        return response()->json($this->onboarding->revealBanking($this->actor($request), $submission), 200, [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function identity(Request $request, int $submission): JsonResponse
    {
        return response()->json($this->onboarding->revealIdentity($this->actor($request), $submission), 200, [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function ssn(Request $request, int $submission): JsonResponse
    {
        return response()->json([
            'ssn' => $this->onboarding->revealSsn($this->actor($request), $submission),
        ], 200, [
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
