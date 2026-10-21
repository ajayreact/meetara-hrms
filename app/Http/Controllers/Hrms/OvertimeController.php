<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreOvertimeRequest;
use App\Services\Hrms\OvertimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OvertimeController extends Controller
{
    public function index(Request $request, OvertimeService $overtime): Response
    {
        return Inertia::render('overtime/index', [
            'entries' => $overtime->list($request->user()),
            'canReview' => $overtime->canReviewList($request->user()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(StoreOvertimeRequest $request, OvertimeService $overtime): RedirectResponse
    {
        $overtime->request($request->user(), $request->validated());

        return back()->with('status', 'Overtime request saved. Pay was not calculated.');
    }

    public function decide(Request $request, int $overtimeEntry, OvertimeService $overtime): RedirectResponse
    {
        $decision = $request->string('decision')->toString();
        $overtime->decide($request->user(), $overtimeEntry, $decision);

        return back()->with('status', 'Overtime request '.$decision.'. Pay was not calculated.');
    }

    public function cancel(Request $request, int $overtimeEntry, OvertimeService $overtime): RedirectResponse
    {
        $overtime->cancel($request->user(), $overtimeEntry);

        return back()->with('status', 'Overtime request cancelled.');
    }
}
