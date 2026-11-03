<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\ChangeEmployeeStatusRequest;
use App\Http\Requests\Hrms\StoreEmployeeRequest;
use App\Http\Requests\Hrms\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\EmployeeDirectory;
use App\Services\Hrms\EmployeeInvitation;
use App\Services\Hrms\EmployeeOnboarding;
use App\Services\Hrms\EmployeeProvisioner;
use App\Services\Hrms\FollowUps;
use App\Services\Hrms\OfferLetters;
use App\Services\Hrms\RoleAssignment;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly EmployeeProvisioner $provisioner,
        private readonly EmployeeInvitation $invitations,
        private readonly EmployeeOnboarding $onboarding,
        private readonly OfferLetters $offerLetters,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if ($user->cannot('viewAny', Employee::class)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Employees',
                'message' => 'Your account cannot open Employees. Company records are not loaded on this screen.',
            ]);
        }

        return Inertia::render('employees/index', [
            'workspace' => $workspace,
            'employees' => $this->directory->list($user, trim((string) $request->query('q', ''))),
            'filters' => ['q' => trim((string) $request->query('q', ''))],
            'options' => $user->can('create', Employee::class) ? $this->directory->options($user) : $this->emptyOptions(),
            'canManage' => $user->can('create', Employee::class),
            'canExport' => $user->can('export', Employee::class),
            'followups' => app(FollowUps::class)->page($user),
            'notice' => $request->session()->get('status'),
            'noticeTone' => $request->session()->get('notice_tone', 'success'),
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $user = $this->actor($request);

        $detail = $this->directory->detail($employee);

        if ($user->can('update', $employee) && Schema::hasColumn('employees', 'date_of_birth')) {
            $detail['dateOfBirth'] = optional($employee->date_of_birth)->toDateString() ?? '';
        }

        return Inertia::render('employees/show', [
            'workspace' => AuthenticatedWorkspace::resolve($user),
            'employee' => $detail,
            'options' => $user->can('update', $employee) ? $this->directory->options($user, (int) $employee->id) : $this->emptyOptions(),
            'canManage' => $user->can('update', $employee),
            'onboarding' => $this->onboarding->present($user, $employee),
            'offerLetters' => [
                'visible' => in_array($user->applicationRole(), ['company_admin', 'hr'], true),
                'letters' => $this->offerLetters->forEmployee($user, (int) $employee->id),
            ],
            'activity' => $this->onboarding->activity($user, $employee),
            'notice' => $request->session()->get('status'),
            'noticeTone' => $request->session()->get('notice_tone', 'success'),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $employee = $this->provisioner->create($actor, $request->validated());
        $message = 'Employee saved.';
        $tone = 'success';
        if ($employee->status === 'invited' && $employee->user_id !== null) {
            $delivery = $this->invitations->send($actor, $employee);
            $message .= ' '.$delivery['message'];
            $tone = $delivery['sent'] ? 'success' : 'error';
        }

        return redirect()->route('hrms.employees.show', $employee)->with('status', $message)->with('notice_tone', $tone);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $this->actor($request);
        $linked = $employee->user_id !== null;
        $this->provisioner->update($actor, $employee, $request->validated());
        $employee->refresh();
        $message = 'Employee updated.';
        $tone = 'success';
        if (! $linked && $employee->user_id !== null) {
            $delivery = $this->invitations->send($actor, $employee);
            $message .= ' '.$delivery['message'];
            $tone = $delivery['sent'] ? 'success' : 'error';
        }

        return redirect()->route('hrms.employees.show', $employee)->with('status', $message)->with('notice_tone', $tone);
    }

    public function status(ChangeEmployeeStatusRequest $request, Employee $employee): RedirectResponse
    {
        $status = (string) $request->validated('status');
        $this->provisioner->changeStatus($this->actor($request), $employee, $status);

        return back()->with('status', $status === 'active' ? 'Employee activated.' : 'Employee deactivated.');
    }

    public function invitation(Request $request, Employee $employee): RedirectResponse
    {
        $result = $this->invitations->send($this->actor($request), $employee);

        return back()->with('status', $result['message'])->with('notice_tone', $result['sent'] ? 'success' : 'error');
    }

    public function enableAccounts(Request $request, RoleAssignment $roles): RedirectResponse
    {
        $roles->defineAccounts($this->actor($request));

        return back()->with('status', 'Accounts access is available to assign. Nobody received it automatically.');
    }

    public function export(Request $request): HttpResponse
    {
        $user = $this->actor($request);
        abort_unless($user->can('export', Employee::class), 403);

        return response($this->directory->exportCsv($user), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employees.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{departments: list<empty>, designations: list<empty>, offices: list<empty>, managers: list<empty>, roles: list<empty>}
     */
    private function emptyOptions(): array
    {
        return [
            'departments' => [],
            'designations' => [],
            'offices' => [],
            'managers' => [],
            'roles' => [],
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
