<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use App\Services\Hrms\AttendanceDirectory;
use App\Services\Hrms\EmployeeDirectory;
use App\Services\Hrms\LeaveDirectory;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\CompanyAdminInvitation;
use App\Services\Hrms\PlatformDirectory;
use App\Services\Hrms\PayrollSettings;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HrmsPageController extends Controller
{
    /**
     * Serve the existing Meetara logo without copying or moving the file.
     */
    public function logo(): BinaryFileResponse
    {
        $path = base_path('images/logo/meetara-logo.png');
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function dashboard(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $component = match ($workspace['role']) {
            'super_admin' => 'dashboard/super-admin',
            'company_admin' => 'dashboard/company-admin',
            'hr' => 'dashboard/hr',
            'accounts' => 'dashboard/accounts',
            'team_lead' => 'dashboard/team-lead',
            default => 'dashboard/employee',
        };

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $directory = app(AttendanceDirectory::class);

        return Inertia::render($component, [
            'workspace' => $workspace,
            'timing' => $directory->timing($user),
            'history' => $directory->month($user, $request->query('month')),
            'attendanceRoster' => in_array($workspace['role'], ['team_lead', 'hr'], true)
                ? $directory->roster($user, null, null, 'All statuses', '')
                : null,
            'companyStats' => $this->companyStats($user),
            'leaveBoard' => app(LeaveDirectory::class)->preview($user),
            'payrollBoard' => app(PayrollDirectory::class)->overview($user),
            'platform' => $workspace['role'] === 'super_admin' ? app(PlatformDirectory::class)->overview($user) : null,
        ]);
    }

    public function profile(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('profile/mine', [
            'workspace' => AuthenticatedWorkspace::resolve($user),
            'employee' => app(EmployeeDirectory::class)->own($user),
        ]);
    }

    public function inbox(Request $request): Response
    {
        return app(InboxController::class)->index($request);
    }

    public function overtime(Request $request): Response
    {
        return app(OvertimeController::class)->index($request, app(\App\Services\Hrms\OvertimeService::class));
    }

    public function helpDesk(Request $request): Response
    {
        return app(HelpDeskController::class)->index($request, app(\App\Services\Hrms\HelpDeskService::class));
    }

    public function routeMap(Request $request): Response
    {
        return $this->gated($request, 'route-map/index', 'routeMap', 'Route Map');
    }

    public function companySettings(Request $request): Response
    {
        $workspace = $this->workspace($request);

        if (! ($workspace['permissions']['settings'] ?? false)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Settings',
                'message' => 'Your account cannot open Settings. Company records are not loaded on this screen.',
            ]);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('settings/company', [
            'workspace' => $workspace,
            'payrollSettings' => $user->applicationRole() === 'company_admin' ? app(PayrollSettings::class)->present($user) : null,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function companies(Request $request): Response
    {
        $workspace = $this->workspace($request);

        if (! ($workspace['permissions']['companies'] ?? false)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Companies',
                'message' => 'Your account cannot open Companies. Company records are not loaded on this screen.',
            ]);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $directory = app(PlatformDirectory::class);

        return Inertia::render('companies/index', [
            'workspace' => $workspace,
            ...$directory->companies(
                $user,
                $request->string('search')->toString(),
                $request->integer('page') ?: 1,
                $request->string('status')->toString(),
                $request->integer('company')
            ),
            'timezones' => timezone_identifiers_list(),
            'notice' => session('status'),
            'error' => session('error'),
            'invitation' => $request->integer('company') > 0
                ? app(CompanyAdminInvitation::class)->summary($request->integer('company'))
                : null,
            'mailConfigured' => app(CompanyAdminInvitation::class)->mailIsConfigured(),
        ]);
    }

    public function audit(Request $request): Response
    {
        $workspace = $this->workspace($request);

        if (! ($workspace['permissions']['audit'] ?? false)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Audit log',
                'message' => 'Your account cannot open Audit log. Company records are not loaded on this screen.',
            ]);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('audit/index', [
            'workspace' => $workspace,
            ...app(PlatformDirectory::class)->audit(
                $user,
                $request->string('search')->toString(),
                $request->string('source')->toString(),
                $request->integer('company'),
                $request->string('from')->toString(),
                $request->string('to')->toString(),
                $request->integer('page') ?: 1
            ),
        ]);
    }

    /**
     * @return array{headcount: int, invited: int, offices: int, pendingNetworks: int, officeList: list<array{id: int, name: string, timezone: string, status: string}>}
     */
    private function companyStats(User $user): array
    {
        $empty = ['headcount' => 0, 'invited' => 0, 'offices' => 0, 'pendingNetworks' => 0, 'officeList' => []];
        $companyId = $user->companyId();

        if ($companyId === null || ! Schema::hasTable('employees')) {
            return $empty;
        }

        $empty['headcount'] = Employee::query()->inCompany($companyId)->where('status', 'active')->count();
        $empty['invited'] = Employee::query()->inCompany($companyId)->where('status', 'invited')->count();

        if (Schema::hasTable('locations') && Schema::hasTable('location_networks')) {
            $offices = Office::query()->inCompany($companyId)->withCount('networks')->get();
            $empty['offices'] = $offices->count();
            $empty['pendingNetworks'] = $offices->where('networks_count', 0)->count();
            $empty['officeList'] = $offices->map(fn (Office $office) => [
                'id' => (int) $office->id,
                'name' => (string) $office->name,
                'timezone' => (string) ($office->timezone ?: 'Company time zone'),
                'status' => $office->status === 'inactive' ? 'Inactive' : 'Active',
            ])->all();
        }

        return $empty;
    }

    /**
     * @return array<string, mixed>
     */
    private function workspace(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return AuthenticatedWorkspace::resolve($user);
    }

    private function page(Request $request, string $component): Response
    {
        return Inertia::render($component, [
            'workspace' => $this->workspace($request),
        ]);
    }

    private function gated(Request $request, string $component, string $permission, string $title): Response
    {
        $workspace = $this->workspace($request);

        if (! ($workspace['permissions'][$permission] ?? false)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => $title,
                'message' => 'Your account cannot open '.$title.'. Company records are not loaded on this screen.',
            ]);
        }

        return Inertia::render($component, [
            'workspace' => $workspace,
        ]);
    }
}
