<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreOfferLetterRequest;
use App\Models\User;
use App\Services\Hrms\OfferLetterCatalog;
use App\Services\Hrms\OfferLetters;
use App\Support\AuthenticatedWorkspace;
use App\Support\IndianAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferLetterController extends Controller
{
    public function __construct(private readonly OfferLetters $letters) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);

        return Inertia::render('offer-letters/index', [
            'ready' => $this->letters->ready(),
            'positions' => collect(OfferLetters::POSITIONS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'employees' => $this->letters->employees($actor),
            'shifts' => $this->letters->shifts($actor),
            'signatories' => $this->letters->signatories($actor),
            'letters' => $this->letters->list($actor),
            'counts' => $this->letters->counts($actor),
            'approvalRequired' => $this->letters->approvalRequired($actor->companyId()),
            'issuance' => $this->letters->issuance($actor),
            'editing' => $request->filled('letter') ? $this->letters->editing($actor, (int) $request->query('letter')) : null,
            'notice' => $request->session()->get('status'),
            'mailNote' => 'Email delivery is not used for offer letters. Download the PDF and send it from the company email account.',
        ]);
    }

    public function store(StoreOfferLetterRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $id = $this->letters->create($actor, $request->validated());
        $message = $request->input('flow') === 'appointment'
            ? 'Offer saved. Preview the appointment letter, then generate the PDF.'
            : 'Offer draft saved. Edit the letter, preview the PDF, then submit it for approval.';

        return redirect()->route('hrms.offer-letters', ['letter' => $id])->with('status', $message);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);
        $letter = $request->filled('letter') ? $this->letters->formState($actor, (int) $request->query('letter')) : null;

        if ($letter !== null && $letter['locked']) {
            return redirect()->route('hrms.offer-letters', ['letter' => $letter['id']]);
        }

        return Inertia::render('offer-letters/create', [
            'positions' => collect(OfferLetters::POSITIONS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'employees' => $this->letters->employees($actor),
            'shifts' => $this->letters->shifts($actor),
            'signatories' => $this->letters->signatories($actor),
            'offer' => $letter,
            'today' => ($issued = now('Asia/Kolkata')->startOfDay())->toDateString(),
            'defaultJoining' => $issued->copy()->addDay()->toDateString(),
            'indiaTemplateActive' => app(OfferLetterCatalog::class)->activeTemplate((int) $actor->companyId(), 'india') !== null,
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function previewIndiaTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);
        $catalog = app(OfferLetterCatalog::class);
        $body = $catalog->standardIndiaBody((int) $actor->companyId());
        abort_if($body === '', 409, OfferLetterCatalog::INDIA_TEMPLATE_REQUIRED);
        $pdf = $catalog->samplePdf($actor, 'india', $body);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="india-appointment-letter.pdf"',
        ]);
    }

    public function correct(Request $request, int $letter): RedirectResponse
    {
        $data = $request->validate([
            'joining_date' => ['required', 'date'],
            'date_of_issue' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'apply_salary' => ['nullable', 'boolean'],
        ]);
        $this->letters->correct($this->actor($request), $letter, $data);

        return back()->with('status', 'Offer dates corrected. The employee salary record was not changed, and no PDF was generated.');
    }

    public function update(StoreOfferLetterRequest $request, int $letter): RedirectResponse
    {
        $this->letters->updateDetails($this->actor($request), $letter, $request->validated());

        return redirect()->route('hrms.offer-letters', ['letter' => $letter])->with('status', 'Offer details saved. The employee record was not changed.');
    }

    public function setup(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);
        $page = app(OfferLetterCatalog::class)->page($actor, 'india');
        $legal = $page['legal'] ?? null;
        $letterheads = $page['letterheads'] ?? [];
        $active = collect($letterheads)->firstWhere('status', 'active');
        $notes = [
            'A review draft removes the U.S. staffing, OPT, and STEM OPT sentences. Previously submitted template copies were not changed.',
            'Clauses that assign monthly recruitment targets are still in the letter. Confirm those duties before the draft is approved if the role is not recruitment.',
        ];

        if (is_array($legal) && is_array($active)) {
            $notes[] = 'The legal name on file is '.$legal['legalName'].'. The active letterhead file is '.$active['name'].'.';
        }

        return Inertia::render('offer-letters/india-settings', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$page,
            'approvalRequired' => $this->letters->approvalRequired($actor->companyId()),
            'canConfigure' => $actor->applicationRole() === 'company_admin',
            'identity' => app(OfferLetterCatalog::class)->letterheadIdentity($actor, 'india'),
            'actorId' => (int) $actor->id,
            'canVerifyLegal' => is_array($legal)
                && ($legal['updatedBy'] ?? null) !== (int) $actor->id
                && trim((string) ($legal['legalName'] ?? '')) !== '',
            'identityNotes' => $notes,
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function confirmLetterhead(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'in:usa,india'],
            'confirm' => ['required', 'in:CONFIRM LETTERHEAD'],
        ]);
        app(OfferLetterCatalog::class)->confirmLetterhead($this->actor($request), $data['country'] ?? 'india');

        return back()->with('status', 'Letterhead confirmation saved. The legal name and the letterhead file were not changed.');
    }

    public function approval(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'required' => ['required', 'boolean'],
        ]);
        $this->letters->setApprovalRequired($this->actor($request), $request->boolean('required'));

        return back()->with('status', $request->boolean('required')
            ? 'Offer approval is required. Existing offers were not approved.'
            : 'Authorized HR staff can generate India appointment PDFs directly. Existing offers were not changed.');
    }

    public function amountWords(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->applicationRole(), ['company_admin', 'hr'], true), 403);
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        $monthly = (string) $data['amount'];
        $annual = (string) ((float) $monthly * 12);

        return response()->json([
            'monthly' => IndianAmount::figures($monthly),
            'monthlyWords' => IndianAmount::words($monthly),
            'annual' => IndianAmount::figures($annual),
            'annualWords' => IndianAmount::words($annual),
        ]);
    }

    public function appointmentPreview(Request $request, int $letter): RedirectResponse
    {
        $this->letters->saveAppointmentPreview($this->actor($request), $letter);

        return back()->with('status', 'Appointment letter preview saved. It is not the final PDF.');
    }

    public function workingHours(Request $request, int $letter): RedirectResponse
    {
        $data = $request->validate([
            'working_hours' => ['required', 'string', 'max:80'],
        ]);
        $this->letters->applyWorkingHours($this->actor($request), $letter, $data['working_hours']);

        return back()->with('status', 'Working hours saved on this offer. The employee record was not changed.');
    }

    public function saveContent(Request $request, int $letter): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:60000'],
        ]);
        $this->letters->saveDraft($this->actor($request), $letter, $data['body']);

        return back()->with('status', 'Offer letter draft saved. The master template was not changed.');
    }

    public function previewDocument(Request $request, int $letter): \Illuminate\Http\Response
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:60000'],
        ]);
        $pdf = $this->letters->previewDocument($this->actor($request), $letter, $data['body'] ?? null);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="offer-letter-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(Request $request, int $letter): RedirectResponse
    {
        $this->letters->preview($this->actor($request), $letter);

        return back()->with('status', 'Offer letter preview saved. It is not issued.');
    }

    public function submit(Request $request, int $letter): RedirectResponse
    {
        $this->letters->submit($this->actor($request), $letter);

        return back()->with('status', 'Offer letter submitted for approval. It is not issued.');
    }

    public function approve(Request $request, int $letter): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'APPROVE', 422);
        $this->letters->approve($this->actor($request), $letter);

        return back()->with('status', 'Offer letter approved. Download happens after it is issued.');
    }

    public function issue(Request $request, int $letter): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'ISSUE', 422);
        foreach (['template_approved', 'approval_status', 'approved_by', 'legal_name', 'legal_address', 'address_verified'] as $field) {
            if ($request->exists($field)) {
                throw ValidationException::withMessages([
                    'letter' => 'A request cannot approve an offer-letter template or verify company legal details.',
                ]);
            }
        }
        $this->letters->issue($this->actor($request), $letter);

        return back()->with('status', 'Offer letter issued. The issued copy is kept.');
    }

    public function decide(Request $request, int $letter): RedirectResponse
    {
        $status = (string) $request->input('status');
        $confirm = (string) $request->input('confirm');
        abort_unless(($status === 'accepted' && $confirm === 'ACCEPT') || ($status === 'declined' && $confirm === 'DECLINE'), 422);
        $this->letters->decide($this->actor($request), $letter, $status);

        return back()->with('status', $status === 'accepted' ? 'Offer letter accepted.' : 'Offer letter declined.');
    }

    public function revise(Request $request, int $letter): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'REVISE', 422);
        $this->letters->revise($this->actor($request), $letter);

        return back()->with('status', 'A new draft version was created. The issued letter was not changed.');
    }

    public function previewPdf(Request $request, int $letter): \Illuminate\Http\Response
    {
        $pdf = $this->letters->renderStoredPreview($this->actor($request), $letter);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="offer-letter-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, int $letter): StreamedResponse
    {
        return $this->letters->download($this->actor($request), $letter);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
