<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\AttendanceCorrection;
use App\Services\Hrms\AttendanceDirectory;
use App\Services\Hrms\AttendanceRejected;
use App\Services\Hrms\AttendanceService;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceDirectory $directory,
        private readonly AttendanceService $attendance,
        private readonly AttendanceCorrection $corrections,
    ) {}

    public function mine(Request $request): Response
    {
        $user = $this->actor($request);

        return Inertia::render('attendance/mine', [
            'workspace' => AuthenticatedWorkspace::resolve($user),
            'timing' => $this->directory->timing($user),
            'history' => $this->directory->month($user, $request->query('month')),
            'corrections' => $this->corrections->forEmployee($user),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function manage(Request $request): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if ($user->cannot('viewAny', AttendanceLog::class)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Attendance',
                'message' => 'Your account cannot open Attendance. Company records are not loaded on this screen.',
            ]);
        }

        $officeId = ctype_digit((string) $request->query('office')) ? (int) $request->query('office') : null;

        return Inertia::render('attendance/manage', [
            'workspace' => $workspace,
            'roster' => $this->directory->roster(
                $user,
                $request->query('date'),
                $officeId,
                (string) $request->query('status', 'All statuses'),
                trim((string) $request->query('employee', '')),
            ),
            'filters' => [
                'date' => (string) $request->query('date', ''),
                'office' => $officeId ? (string) $officeId : '',
                'status' => (string) $request->query('status', 'All statuses'),
                'employee' => trim((string) $request->query('employee', '')),
            ],
            'canCorrect' => in_array($user->applicationRole(), ['company_admin', 'hr'], true),
            'canExport' => $user->can('export', AttendanceLog::class),
            'corrections' => $this->corrections->forCompany($user),
            'authorizations' => $user->applicationRole() === 'team_lead' ? [] : $this->corrections->authorizations($user),
            'people' => $this->people($user),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): string {
            $officeId = $request->filled('office_id') ? (int) $request->input('office_id') : null;
            $this->attendance->clockIn($this->actor($request), $officeId, $request);

            return 'Clock-in recorded.';
        });
    }

    public function startBreak(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): string {
            $this->attendance->startBreak($this->actor($request));

            return 'Break started.';
        });
    }

    public function endBreak(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): string {
            $this->attendance->endBreak($this->actor($request));

            return 'Break ended.';
        });
    }

    public function clockOut(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): string {
            $this->attendance->clockOut($this->actor($request), $request);

            return 'Clock-out recorded.';
        });
    }

    public function recover(Request $request, AttendanceLog $attendanceLog): RedirectResponse
    {
        $note = (string) $request->input('reason', '');

        if (strlen(trim($note)) < 8) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason of at least 8 characters.']);
        }

        return $this->attempt(function () use ($request, $attendanceLog, $note): string {
            $this->attendance->recover($this->actor($request), $attendanceLog, trim($note));

            return 'The open session was closed. The original clock-in time was kept.';
        });
    }

    public function storeCorrection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'type' => ['required', 'string'],
            'check_in' => ['nullable', 'string'],
            'check_out' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);
        $this->corrections->submit($this->actor($request), $data);

        return back()->with('status', 'Correction request submitted. Attendance was not changed.');
    }

    public function cancelCorrection(Request $request, int $correction): RedirectResponse
    {
        $this->corrections->cancel($this->actor($request), $correction);

        return back()->with('status', 'Correction request cancelled.');
    }

    public function approveCorrection(Request $request, int $correction): RedirectResponse
    {
        $this->corrections->approve($this->actor($request), $correction, (string) $request->input('review_reason', ''));

        return back()->with('status', 'Correction approved. The attendance record was updated.');
    }

    public function rejectCorrection(Request $request, int $correction): RedirectResponse
    {
        $this->corrections->reject($this->actor($request), $correction, (string) $request->input('review_reason', ''));

        return back()->with('status', 'Correction rejected. The attendance record was not changed.');
    }

    public function authorizeHolidayWork(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'work_date' => ['required', 'date'],
            'kind' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);
        $this->corrections->authorizeWork(
            $this->actor($request),
            (int) $data['employee_id'],
            (string) $data['work_date'],
            (string) $data['kind'],
            $data['reason'] ?? null,
        );

        return back()->with('status', 'Holiday work was authorized for that employee only.');
    }

    public function export(Request $request): HttpResponse
    {
        $user = $this->actor($request);
        abort_unless($user->can('export', AttendanceLog::class), 403);
        $officeId = ctype_digit((string) $request->query('office')) ? (int) $request->query('office') : null;

        return response($this->directory->export(
            $user,
            $request->query('date'),
            $officeId,
            (string) $request->query('status', 'All statuses'),
            trim((string) $request->query('employee', '')),
        ), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function attempt(callable $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (AttendanceRejected $exception) {
            throw ValidationException::withMessages(['attendance' => $exception->getMessage()]);
        }

        return back()->with('status', $message);
    }

    /**
     * @return list<array{id: int, name: string, code: string}>
     */
    private function people(User $user): array
    {
        if (! in_array($user->applicationRole(), ['company_admin', 'hr'], true) || $user->companyId() === null) {
            return [];
        }

        return Employee::query()->inCompany($user->companyId())->orderBy('first_name')->limit(200)->get()->map(fn (Employee $employee) => [
            'id' => (int) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'code' => (string) $employee->employee_code,
        ])->all();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
