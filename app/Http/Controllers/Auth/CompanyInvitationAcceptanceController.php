<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptCompanyInvitationRequest;
use App\Services\Hrms\CompanyAdminInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CompanyInvitationAcceptanceController extends Controller
{
    public function create(Request $request, string $token, CompanyAdminInvitation $invitations): Response
    {
        $payload = $request->user() !== null
            ? ['state' => 'authenticated']
            : $invitations->describe($token);

        return Inertia::render('auth/accept-invitation', $payload);
    }

    public function store(AcceptCompanyInvitationRequest $request, string $token, CompanyAdminInvitation $invitations): RedirectResponse
    {
        $user = $invitations->accept($token, (string) $request->validated('name'), (string) $request->validated('password'));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
