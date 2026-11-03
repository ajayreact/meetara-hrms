<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\UsaDesignationCatalog;
use App\Services\Hrms\UsaOfferLetters;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UsaOfferController extends Controller
{
    public function __construct(
        private readonly UsaDesignationCatalog $catalog,
        private readonly UsaOfferLetters $offers,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->manager($request);
        $companyId = (int) $actor->companyId();

        return Inertia::render('usa-offers/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'offers' => $this->offers->list($companyId),
            'designationCount' => count($this->catalog->all($companyId)),
            'stationery' => $this->offers->stationery($companyId),
            'notice' => session('status'),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('usa-offers/form', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'offer' => null,
            'designations' => $this->choices((int) $actor->companyId()),
            'stationery' => $this->offers->stationery((int) $actor->companyId()),
            'notice' => session('status'),
        ]);
    }

    public function show(Request $request, int $offer): Response
    {
        $actor = $this->manager($request);
        $companyId = (int) $actor->companyId();

        return Inertia::render('usa-offers/form', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'offer' => $this->offers->present($companyId, $offer),
            'designations' => $this->choices($companyId),
            'stationery' => $this->offers->stationery($companyId),
            'notice' => session('status'),
        ]);
    }

    public function settings(Request $request): Response
    {
        $actor = $this->manager($request);
        $companyId = (int) $actor->companyId();

        return Inertia::render('usa-offers/settings', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'designations' => $this->catalog->all($companyId),
            'groups' => UsaDesignationCatalog::GROUPS,
            'profile' => $this->offers->profile($companyId),
            'stationery' => $this->offers->stationery($companyId),
            'notice' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $id = $this->offers->create($actor, $this->offerInput($request, (int) $actor->companyId()));

        return redirect()->route('hrms.usa-offers.show', $id)->with('status', 'Draft saved. Preview the letter, then generate the PDF when it is ready. No PDF was created yet, and nothing was emailed.');
    }

    public function update(Request $request, int $offer): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->offers->update($actor, $offer, $this->offerInput($request, (int) $actor->companyId()));

        return redirect()->route('hrms.usa-offers.show', $offer)->with('status', 'USA offer draft updated.');
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:160'],
            'address_line' => ['nullable', 'string', 'max:160'],
            'city_line' => ['nullable', 'string', 'max:160'],
            'country_line' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'e_verify' => ['nullable', 'string', 'max:40'],
            'federal_id' => ['nullable', 'string', 'max:40'],
            'manager_name' => ['nullable', 'string', 'max:120'],
            'manager_title' => ['nullable', 'string', 'max:120'],
            'manager_email' => ['nullable', 'email', 'max:160'],
            'manager_phone' => ['nullable', 'string', 'max:40'],
            'hr_name' => ['nullable', 'string', 'max:120'],
            'hr_title' => ['nullable', 'string', 'max:120'],
            'hr_phone' => ['nullable', 'string', 'max:40'],
        ]);
        $this->offers->saveProfile((int) $actor->companyId(), $data);

        return redirect()->route('hrms.usa-offers.settings')->with('status', 'USA offer letter details saved.');
    }

    public function saveLetterhead(Request $request): RedirectResponse
    {
        return $this->saveStationeryFile($request, 'letterhead');
    }

    public function saveSignature(Request $request): RedirectResponse
    {
        return $this->saveStationeryFile($request, 'signature');
    }

    public function showLetterhead(Request $request): BinaryFileResponse
    {
        return $this->showStationery($request, 'letterhead');
    }

    public function showSignature(Request $request): BinaryFileResponse
    {
        return $this->showStationery($request, 'signature');
    }

    public function saveDesignation(Request $request, int $designation): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'responsibilities' => ['required', 'string', 'max:20000'],
            'active' => ['required', 'boolean'],
        ]);
        $this->catalog->save(
            (int) $actor->companyId(),
            $designation,
            preg_split('/\r\n|\r|\n/', $data['responsibilities']) ?: [],
            (bool) $data['active'],
        );

        return redirect()->route('hrms.usa-offers.settings')->with('status', 'Default responsibilities updated. Existing offers were not changed.');
    }

    public function addDesignation(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'group' => ['required', Rule::in(array_keys(UsaDesignationCatalog::GROUPS))],
            'responsibilities' => ['required', 'string', 'max:20000'],
        ]);
        $this->catalog->add(
            (int) $actor->companyId(),
            $data['name'],
            $data['group'],
            preg_split('/\r\n|\r|\n/', $data['responsibilities']) ?: [],
        );

        return redirect()->route('hrms.usa-offers.settings')->with('status', 'Designation added.');
    }

    public function restoreDesignation(Request $request, int $designation): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->catalog->restore((int) $actor->companyId(), $designation);

        return redirect()->route('hrms.usa-offers.settings')->with('status', 'Default responsibilities restored. Existing offers were not changed.');
    }

    public function preview(Request $request, int $offer): HttpResponse|RedirectResponse
    {
        $actor = $this->manager($request);

        try {
            return $this->pdf($this->offers->preview((int) $actor->companyId(), $offer), 'inline');
        } catch (ValidationException $exception) {
            return redirect()->route('hrms.usa-offers.show', $offer)->withErrors($exception->errors());
        }
    }

    public function generate(Request $request, int $offer): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->offers->generate($actor, $offer);

        return redirect()->route('hrms.usa-offers.show', $offer)->with('status', 'USA offer PDF generated. It was not emailed.');
    }

    public function download(Request $request, int $offer): HttpResponse
    {
        $actor = $this->manager($request);

        return $this->pdf($this->offers->download((int) $actor->companyId(), $offer), 'attachment');
    }

    /**
     * @return list<array{id: int, name: string, group: string, responsibilities: list<string>}>
     */
    private function choices(int $companyId): array
    {
        $choices = [];

        foreach ($this->catalog->all($companyId) as $designation) {
            if (! $designation['active']) {
                continue;
            }

            $choices[] = [
                'id' => $designation['id'],
                'name' => $designation['name'],
                'group' => $designation['group'],
                'responsibilities' => $designation['responsibilities'],
            ];
        }

        return $choices;
    }

    /**
     * @return array<string, mixed>
     */
    private function offerInput(Request $request, int $companyId): array
    {
        return $request->validate([
            'designation_id' => ['required', 'integer'],
            'responsibilities' => ['required', 'string', 'max:20000'],
            'candidate_name' => ['required', 'string', 'max:120'],
            'candidate_email' => ['nullable', 'email', 'max:160'],
            'candidate_phone' => ['nullable', 'string', 'max:40'],
            'candidate_address' => ['nullable', 'string', 'max:500'],
            'employment_type' => ['nullable', 'string', 'max:80'],
            'pay_type' => ['required', Rule::in(['hourly', 'annual'])],
            'pay_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'working_hours' => ['required', 'string', 'max:80'],
            'work_arrangement' => ['required', 'string', 'max:120'],
            'university' => ['required', 'string', 'max:160'],
            'start_date' => ['required', 'date'],
            'issue_date' => ['required', 'date'],
            'tenant_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
        ]) + ['company_id' => $companyId];
    }

    private function pdf(string $bytes, string $disposition): HttpResponse
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="usa-offer.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function saveStationeryFile(Request $request, string $kind): RedirectResponse
    {
        $actor = $this->manager($request);
        $rules = $kind === 'letterhead'
            ? ['letterhead' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,pdf']]
            : ['signature' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg']];
        $data = $request->validate($rules);
        $this->offers->saveStationery((int) $actor->companyId(), $kind, $data[$kind]);

        return redirect()->route('hrms.usa-offers.settings')->with('status', $kind === 'letterhead' ? 'Company letterhead saved. Generated offers were not changed.' : 'HR signature saved. Generated offers were not changed.');
    }

    private function showStationery(Request $request, string $kind): BinaryFileResponse
    {
        $actor = $this->manager($request);
        $companyId = (int) $actor->companyId();
        $bytes = $this->offers->stationeryFile($companyId, $kind);
        $path = tempnam(sys_get_temp_dir(), 'usa-stationery');

        if ($path === false) {
            abort(500);
        }

        file_put_contents($path, $bytes);

        $response = response()->file($path, [
            'Content-Type' => str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->companyId() !== null && $this->catalog->manages($user), 403);

        return $user;
    }
}
