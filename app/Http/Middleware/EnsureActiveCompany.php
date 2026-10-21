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
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This company is not active.',
            ]);
        }

        return $next($request);
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
