<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\IdCardCompanyProfile;
use App\Models\User;
use App\Services\Hrms\IdCardCompanyProfiles;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdCardCompanyProfileController extends Controller
{
    public function __construct(private readonly IdCardCompanyProfiles $profiles) {}

    public function settings(Request $request): Response
    {
        $actor = $this->manager($request);
        $country = (string) $request->query('country', 'india');

        return Inertia::render('id-cards/settings', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->profiles->page($actor, $country),
            'notice' => session('status'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'country' => ['required', 'string', Rule::in(IdCardCompanyProfile::COUNTRIES)],
            'legal_name' => ['required', 'string', 'max:191'],
            'display_name' => ['required', 'string', 'max:191'],
            'office_address' => ['required', 'string', 'max:2000'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'string', 'max:191'],
            'footer_address' => ['required', 'string', 'max:2000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'signature' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $result = $this->profiles->save(
            $actor,
            (string) $data['country'],
            $data,
            $request->file('logo'),
            $request->file('signature'),
        );

        return redirect()
            ->route('hrms.id-cards.settings', ['country' => $data['country']])
            ->with('status', $result['message']);
    }

    public function logo(Request $request): StreamedResponse
    {
        return $this->profiles->logoResponse(
            $this->manager($request),
            (string) $request->query('country', 'india'),
        );
    }

    public function signature(Request $request): StreamedResponse
    {
        return $this->profiles->signatureResponse(
            $this->manager($request),
            (string) $request->query('country', 'india'),
        );
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
