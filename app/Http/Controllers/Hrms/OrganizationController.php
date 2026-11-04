<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\DepartmentRequest;
use App\Http\Requests\Hrms\DesignationRequest;
use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use App\Services\Hrms\CompanyCatalog;
use App\Services\Hrms\RoleAssignment;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly CompanyCatalog $catalog,
        private readonly RoleAssignment $roles,
    ) {}

    public function departments(Request $request): Response
    {
        return $this->page($request, Department::class, 'Departments', 'organization/departments', 'departments');
    }

    public function storeDepartment(DepartmentRequest $request): RedirectResponse
    {
        $this->catalog->saveDepartment($this->actor($request), $request->validated());

        return back()->with('status', 'Department saved.');
    }

    public function updateDepartment(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $before = (bool) $department->is_active;
        $saved = $this->catalog->saveDepartment($this->actor($request), $request->validated(), $department);

        return back()->with('status', $this->statusMessage('Department', $before, (bool) $saved->is_active));
    }

    public function designations(Request $request): Response
    {
        return $this->page($request, Designation::class, 'Designations', 'organization/designations', 'designations');
    }

    public function storeDesignation(DesignationRequest $request): RedirectResponse
    {
        $this->catalog->saveDesignation($this->actor($request), $request->validated());

        return back()->with('status', 'Designation saved.');
    }

    public function updateDesignation(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        $before = (bool) $designation->is_active;
        $saved = $this->catalog->saveDesignation($this->actor($request), $request->validated(), $designation);

        return back()->with('status', $this->statusMessage('Designation', $before, (bool) $saved->is_active));
    }

    public function accessLevels(Request $request): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if ($user->applicationRole() !== 'company_admin' || $user->companyId() === null) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Access levels',
                'message' => 'Only a Company Admin can open access levels. Company records are not loaded on this screen.',
            ]);
        }

        $companyId = $user->companyId();
        $this->roles->ensureStandard($companyId);

        return Inertia::render('organization/access-levels', [
            'workspace' => $workspace,
            'levels' => $this->catalog->accessLevels($companyId),
            'notice' => $request->session()->get('status'),
        ]);
    }

    /**
     * @param  class-string<Department|Designation>  $model
     */
    private function page(Request $request, string $model, string $title, string $view, string $key): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if ($user->cannot('viewAny', $model)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => $title,
                'message' => 'Your account cannot open '.$title.'. Company records are not loaded on this screen.',
            ]);
        }

        $companyId = (int) $user->companyId();

        return Inertia::render($view, [
            'workspace' => $workspace,
            $key => $key === 'departments' ? $this->catalog->departments($companyId) : $this->catalog->designations($companyId),
            'notice' => $request->session()->get('status'),
        ]);
    }

    private function statusMessage(string $label, bool $before, bool $after): string
    {
        if ($before && ! $after) {
            return $label.' deactivated.';
        }

        if (! $before && $after) {
            return $label.' activated.';
        }

        return $label.' updated.';
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
