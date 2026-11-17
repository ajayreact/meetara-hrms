<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\IdCardCompanyProfile;
use App\Models\StandaloneIdCard;
use App\Models\User;
use App\Services\Hrms\StandaloneIdCardBulkIssuer;
use App\Services\Hrms\StandaloneIdCardIssuer;
use App\Services\Hrms\StandaloneIdCards;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StandaloneIdCardController extends Controller
{
    public function __construct(
        private readonly StandaloneIdCards $cards,
        private readonly StandaloneIdCardIssuer $issuer,
        private readonly StandaloneIdCardBulkIssuer $bulk,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->manager($request);
        $cardId = $request->query('card');
        $query = $request->query('q');

        return Inertia::render('id-cards/standalone/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->cards->page(
                $actor,
                is_numeric($cardId) ? (int) $cardId : null,
                is_string($query) ? $query : '',
            ),
            'notice' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $this->validated($request);
        $result = $this->cards->save($actor, $data, $request->file('photo'));

        return redirect()
            ->route('hrms.id-cards.standalone', ['card' => $result['cardId']])
            ->with('status', $result['message']);
    }

    public function update(Request $request, StandaloneIdCard $standaloneIdCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $this->validated($request);
        $result = $this->cards->save($actor, $data, $request->file('photo'), $standaloneIdCard);

        return redirect()
            ->route('hrms.id-cards.standalone', ['card' => $result['cardId']])
            ->with('status', $result['message']);
    }

    public function issue(Request $request, StandaloneIdCard $standaloneIdCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->issue($actor, $standaloneIdCard);

        return redirect()
            ->route('hrms.id-cards.standalone', ['card' => $result['cardId']])
            ->with('status', $result['message']);
    }

    public function reissue(Request $request, StandaloneIdCard $standaloneIdCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->reissue($actor, $standaloneIdCard);

        return redirect()
            ->route('hrms.id-cards.standalone', ['card' => $result['cardId']])
            ->with('status', $result['message']);
    }

    public function deactivate(Request $request, StandaloneIdCard $standaloneIdCard): RedirectResponse
    {
        $actor = $this->manager($request);
        $result = $this->issuer->deactivate($actor, $standaloneIdCard);

        return redirect()
            ->route('hrms.id-cards.standalone')
            ->with('status', $result['message']);
    }

    public function pdf(Request $request, StandaloneIdCard $standaloneIdCard): StreamedResponse
    {
        return $this->issuer->download($this->manager($request), $standaloneIdCard);
    }

    public function photo(Request $request, StandaloneIdCard $standaloneIdCard): StreamedResponse
    {
        return $this->cards->photoResponse($this->manager($request), $standaloneIdCard);
    }

    public function bulk(Request $request): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('id-cards/standalone/bulk', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->bulk->page($actor),
            'result' => session('standaloneIdCardBulkResult'),
            'notice' => session('status'),
        ]);
    }

    public function bulkPreview(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'card_ids' => ['required', 'array', 'min:1'],
            'card_ids.*' => ['integer'],
        ]);

        $preview = $this->bulk->preview($actor, $data['card_ids']);

        return redirect()
            ->route('hrms.id-cards.standalone.bulk')
            ->with('standaloneIdCardBulkResult', [
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
            'card_ids' => ['required', 'array', 'min:1'],
            'card_ids.*' => ['integer'],
            'format' => ['required', 'string', Rule::in(StandaloneIdCardBulkIssuer::FORMATS)],
        ]);

        $result = $this->bulk->run($actor, $data['card_ids'], (string) $data['format']);

        return redirect()
            ->route('hrms.id-cards.standalone.bulk')
            ->with('status', $result['message'])
            ->with('standaloneIdCardBulkResult', [
                'mode' => 'issue',
                ...$result,
            ]);
    }

    public function bulkDownload(Request $request, string $token): BinaryFileResponse|StreamedResponse
    {
        return $this->bulk->download($this->manager($request), $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'card_number' => ['required', 'string', 'max:60'],
            'designation' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['required', 'string', Rule::in(IdCardCompanyProfile::COUNTRIES)],
            'blood_group' => ['nullable', 'string', Rule::in(StandaloneIdCard::BLOOD_GROUPS)],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
