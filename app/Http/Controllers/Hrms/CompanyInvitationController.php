<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\RevokeCompanyInvitationRequest;
use App\Http\Requests\Hrms\StoreCompanyInvitationRequest;
use App\Services\Hrms\CompanyAdminInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyInvitationController extends Controller
{
    public function store(StoreCompanyInvitationRequest $request, int $company, CompanyAdminInvitation $invitations): RedirectResponse
    {
        $result = $invitations->invite($request->user(), $company, (string) $request->validated('email'));

        return $this->back($company, $result);
    }

    public function resend(Request $request, int $company, int $invitation, CompanyAdminInvitation $invitations): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), 403);
        $result = $invitations->resend($request->user(), $company, $invitation);

        return $this->back($company, $result);
    }

    public function revoke(RevokeCompanyInvitationRequest $request, int $company, int $invitation, CompanyAdminInvitation $invitations): RedirectResponse
    {
        $invitations->revoke($request->user(), $company, $invitation);

        return redirect()
            ->route('hrms.companies', ['company' => $company])
            ->with('status', 'Invitation revoked.');
    }

    /**
     * @param  array{sent: bool, message: string}  $result
     */
    private function back(int $company, array $result): RedirectResponse
    {
        $redirect = redirect()->route('hrms.companies', ['company' => $company]);

        if ($result['sent']) {
            return $redirect->with('status', 'Invitation email sent.');
        }

        return $redirect->withErrors(['email' => $result['message']]);
    }
}
