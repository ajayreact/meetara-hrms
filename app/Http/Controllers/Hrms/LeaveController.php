<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CancelLeaveRequest;
use App\Http\Requests\Hrms\DecideLeaveRequest;
use App\Http\Requests\Hrms\StoreLeaveRequest;
use App\Http\Requests\Hrms\UpdateLeavePolicyRequest;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Services\Hrms\LeaveDirectory;
use App\Services\Hrms\LeaveRejected;
use App\Services\Hrms\LeaveService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function index(LeaveDirectory $directory): Response
    {
        return Inertia::render('leave/index', [
            ...$directory->page(request()->user()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(StoreLeaveRequest $request, LeaveService $leave): RedirectResponse
    {
        try {
            $leave->apply($request->user(), $request->validated());
        } catch (LeaveRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Leave request submitted.');
    }

    public function approve(DecideLeaveRequest $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        return $this->decide($request, $leaveRequest, $leave, 'approved');
    }

    public function reject(DecideLeaveRequest $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        return $this->decide($request, $leaveRequest, $leave, 'rejected');
    }

    public function cancel(CancelLeaveRequest $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        try {
            $leave->cancel($request->user(), $leaveRequest, $request->validated('note'));
        } catch (LeaveRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Leave request cancelled.');
    }

    public function updatePolicy(UpdateLeavePolicyRequest $request, LeavePolicy $leavePolicy): RedirectResponse
    {
        if ((int) $leavePolicy->tenant_id !== (int) $request->user()->companyId()) {
            abort(404);
        }

        $leavePolicy->update($request->safe()->only('applicable_after_days'));

        return back()->with('status', 'Leave policy updated.');
    }

    private function decide(DecideLeaveRequest $request, LeaveRequest $leaveRequest, LeaveService $leave, string $decision): RedirectResponse
    {
        try {
            $leave->decide($request->user(), $leaveRequest, $decision, $request->validated('note'));
        } catch (LeaveRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $decision === 'approved' ? 'Leave request approved.' : 'Leave request rejected.');
    }
}
