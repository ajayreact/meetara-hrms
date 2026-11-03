<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptEmployeeInvitationRequest;
use App\Services\Hrms\EmployeeInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeInvitationAcceptanceController extends Controller
{
    public function create(Request $request, string $token, EmployeeInvitation $invitations): Response
    {
        $payload = $request->user() !== null
            ? ['state' => 'authenticated']
            : $invitations->describe($token);

        return Inertia::render('auth/accept-employee-invitation', $payload);
    }

    public function store(AcceptEmployeeInvitationRequest $request, string $token, EmployeeInvitation $invitations): RedirectResponse
    {
        $user = $invitations->accept($token, (string) $request->validated('password'));
        Auth::login($user);
        $request->session()->regenerate();

        $company = (string) DB::table('tenants')->where('id', $user->companyId())->value('name');

        return redirect()
            ->route('dashboard')
            ->with('status', 'Welcome to '.$company.'. Your account is active.');
    }
}
