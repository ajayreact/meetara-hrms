<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreOfferLetterRequest;
use App\Models\User;
use App\Services\Hrms\OfferLetters;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'letters' => $this->letters->list($actor),
            'issuance' => $this->letters->issuance(),
            'notice' => $request->session()->get('status'),
            'mailNote' => 'Email delivery is not used for offer letters. A log mailer is not delivery.',
        ]);
    }

    public function store(StoreOfferLetterRequest $request): RedirectResponse
    {
        $this->letters->create($this->actor($request), $request->validated());

        return back()->with('status', 'Offer letter draft saved.');
    }

    public function preview(Request $request, int $letter): RedirectResponse
    {
        $this->letters->preview($this->actor($request), $letter);

        return back()->with('status', 'Offer letter preview saved. It is not issued.');
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
