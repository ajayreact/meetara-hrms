<?php

namespace App\Providers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\Office;
use App\Models\PayrollItem;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\HolidayPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\OfficePolicy;
use App\Policies\PayrollPaymentPolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PayslipPolicy;
use App\Policies\SalaryStructurePolicy;
use App\Support\DeploymentSecurity;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        if (DeploymentSecurity::requiresHttps($this->app->environment())) {
            URL::forceScheme('https');
        }

        $this->applyTrustedProxies();

        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Office::class, OfficePolicy::class);
        Gate::policy(AttendanceLog::class, AttendancePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Holiday::class, HolidayPolicy::class);
        Gate::policy(SalaryStructure::class, SalaryStructurePolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(PayrollPayment::class, PayrollPaymentPolicy::class);
        Gate::policy(PayrollItem::class, PayslipPolicy::class);

        Route::bind('employee', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $employee = Employee::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($employee === null || $user->cannot('view', $employee)) {
                abort(404);
            }

            return $employee;
        });

        Route::bind('office', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $office = Office::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($office === null || $user->cannot('update', $office)) {
                abort(404);
            }

            return $office;
        });

        Route::bind('attendanceLog', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $log = AttendanceLog::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($log === null || $user->cannot('view', $log)) {
                abort(404);
            }

            return $log;
        });

        Route::bind('leaveRequest', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $request = LeaveRequest::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($request === null || $user->cannot('view', $request)) {
                abort(404);
            }

            return $request;
        });

        Route::bind('holiday', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $holiday = Holiday::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($holiday === null || $user->cannot('view', $holiday)) {
                abort(404);
            }

            return $holiday;
        });

        Route::bind('leavePolicy', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null || $user->applicationRole() !== 'company_admin') {
                abort(404);
            }

            return LeavePolicy::query()->inCompany($user->companyId())->whereKey($value)->firstOrFail();
        });

        Route::bind('payrollRun', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $run = PayrollRun::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($run === null || $user->cannot('view', $run)) {
                abort(404);
            }

            return $run;
        });

        Route::bind('payment', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $payment = PayrollPayment::query()->inCompany($user->companyId())->whereKey($value)->first();

            if ($payment === null || $user->cannot('manage', $payment)) {
                abort(404);
            }

            return $payment;
        });

        Route::bind('payslip', function (string $value) {
            $user = auth()->user();

            if (! $user instanceof User || ! ctype_digit($value) || $user->companyId() === null) {
                abort(404);
            }

            $item = PayrollItem::query()->with('run')->inCompany($user->companyId())->whereKey($value)->first();

            if ($item === null || $user->cannot('view', $item)) {
                abort(404);
            }

            return $item;
        });
    }

    /**
     * Apply proxy trust after configuration is loaded. Reading TRUSTED_PROXIES
     * while the HTTP kernel is constructed is too early: the environment file
     * is not loaded yet, and a cached configuration would ignore it.
     */
    private function applyTrustedProxies(): void
    {
        $configured = config('attendance.trusted_proxies', []);
        $proxies = DeploymentSecurity::trustedProxies(is_array($configured) ? implode(',', $configured) : '');

        if ($proxies === []) {
            return;
        }

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX
        );
    }
}
