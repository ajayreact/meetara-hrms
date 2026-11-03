<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->companyIsInactive($user)) {
            $this->endSession($request);

            return redirect()->route('login')->withErrors([
                'email' => 'This company is not active.',
            ]);
        }

        if ($user instanceof User && $this->employeeIsBlocked($user)) {
            $this->endSession($request);

            return redirect()->route('login')->withErrors([
                'email' => 'This account is not active.',
            ]);
        }

        return $next($request);
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function employeeIsBlocked(User $user): bool
    {
        $companyId = $user->companyId();

        if ($companyId === null || ! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'status')) {
            return false;
        }

        $status = DB::table('employees')
            ->where('tenant_id', $companyId)
            ->where('user_id', $user->id)
            ->value('status');

        return in_array($status, ['inactive', 'deactivated', 'terminated', 'exited', 'suspended'], true);
    }

    private function companyIsInactive(User $user): bool
    {
        $companyId = $user->companyId();

        if ($companyId === null || ! Schema::hasTable('tenants') || ! Schema::hasColumn('tenants', 'status')) {
            return false;
        }

        $status = DB::table('tenants')->where('id', $companyId)->value('status');

        return $status !== null && $status !== 'active';
    }
}
