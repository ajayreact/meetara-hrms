<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\EmployeeIdCard;
use App\Models\IdCardCompanyProfile;
use App\Models\User;
use App\Services\Hrms\EmployeeIdCardBulkIssuer;
use App\Services\Hrms\EmployeeIdCardIssuer;
use App\Services\Hrms\EmployeeIdCards;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeIdCardController extends Controller
{
    public function __construct(
        private readonly EmployeeIdCards $cards,
        private readonly EmployeeIdCardIssuer $issuer,
        private readonly EmployeeIdCardBulkIssuer $bulk,
    ) {}

    public function manage(Request $request): Response
    {
        $actor = $this->manager($request);
        $employeeId = $request->query('employee');

        return Inertia::render('id-cards/manage', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->cards->page(
                $actor,
                is_numeric($employeeId) ? (int) $employeeId : null,
            ),
            'notice' => session('status'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'country' => ['required', 'string', Rule::in(IdCardCompanyProfile::COUNTRIES)],
            'blood_group' => ['nullable', 'string', Rule::in(EmployeeIdCard::BLOOD_GROUPS)],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
            'designation' => ['required', 'string', 'max:120'],
            'department' => ['required', 'string', 'max:120'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $result = $this->cards->save($actor, $data, $request->file('photo'));

        return redirect()
            ->route('hrms.id-cards', ['employee' => (int) $data['employee_id']])
            ->with('status', $result['message']);
    }

    public function issue(Request $request, EmployeeIdCard $idCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->issue($actor, $idCard);

        return redirect()
            ->route('hrms.id-cards', ['employee' => (int) $idCard->employee_id])
            ->with('status', $result['message']);
    }

    public function reissue(Request $request, EmployeeIdCard $idCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->reissue($actor, $idCard);
        $employeeId = (int) EmployeeIdCard::query()
            ->inCompany((int) $actor->companyId())
            ->whereKey($result['cardId'])
            ->value('employee_id');

        return redirect()
            ->route('hrms.id-cards', ['employee' => $employeeId])
            ->with('status', $result['message']);
    }

    public function deactivate(Request $request, EmployeeIdCard $idCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->deactivate($actor, $idCard);

        return redirect()
            ->route('hrms.id-cards', ['employee' => (int) $idCard->employee_id])
            ->with('status', $result['message']);
    }

    public function pdf(Request $request, EmployeeIdCard $idCard): StreamedResponse
    {
        return $this->issuer->download($this->manager($request), $idCard);
    }

    public function photo(Request $request, EmployeeIdCard $idCard): StreamedResponse
    {
        return $this->cards->photoResponse($this->manager($request), $idCard);
    }

    public function bulk(Request $request): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('id-cards/bulk', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->bulk->page($actor),
            'result' => session('idCardBulkResult'),
            'notice' => session('status'),
        ]);
    }

    public function bulkPreview(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
        ]);

        $preview = $this->bulk->preview($actor, $data['employee_ids']);

        return redirect()
            ->route('hrms.id-cards.bulk')
            ->with('idCardBulkResult', [
                'mode' => 'preview',
                'format' => null,
                'successCount' => 0,
                'failureCount' => $preview['notReadyCount'],
                'skippedCount' => $preview['alreadyIssuedCount'] + $preview['missingCount'],
                'downloadUrl' => null,
                'rows' => $preview['selected'],
                'eligibleCount' => $preview['eligibleCount'],
                'message' => $preview['eligibleCount'].' ready to issue, '.$preview['notReadyCount'].' not ready, '.($preview['alreadyIssuedCount'] + $preview['missingCount']).' skipped.',
            ]);
    }

    public function bulkIssue(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
            'format' => ['required', 'string', Rule::in(EmployeeIdCardBulkIssuer::FORMATS)],
        ]);

        $result = $this->bulk->run($actor, $data['employee_ids'], (string) $data['format']);

        return redirect()
            ->route('hrms.id-cards.bulk')
            ->with('status', $result['message'])
            ->with('idCardBulkResult', [
                'mode' => 'issue',
                ...$result,
            ]);
    }

    public function bulkDownload(Request $request, string $token): BinaryFileResponse|StreamedResponse
    {
        return $this->bulk->download($this->manager($request), $token);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
