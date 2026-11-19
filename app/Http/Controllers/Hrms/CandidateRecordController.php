<?php

namespace App\Http\Controllers\Hrms;

use App\Auth\CompanyRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\CandidateRecords;
use App\Services\Hrms\CandidateRecordWorkbook;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidateRecordController extends Controller
{
    public function __construct(
        private readonly CandidateRecords $records,
        private readonly CandidateRecordWorkbook $workbook,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);

        return Inertia::render('candidate-records/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'records' => $this->records->list($actor, $filters),
            'filters' => [
                'search' => trim($request->string('search')->toString()),
                'expiry' => $this->records->expiryFilters($filters),
            ],
            'options' => $this->records->formOptions(),
            'canExport' => (CompanyRole::permissions($actor->applicationRole())['candidatesExport'] ?? false) === true,
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->records->ensureManage($actor);

        return Inertia::render('candidate-records/create', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'options' => $this->records->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $record = $this->records->register($this->actor($request), $request->all());

        return redirect()->route('hrms.candidate-records.show', $record)->with('status', 'Candidate registered.');
    }

    public function show(Request $request, int $record): Response
    {
        $actor = $this->actor($request);
        $found = $this->records->find($actor, $record);

        return Inertia::render('candidate-records/show', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'record' => $this->records->present($found),
            'options' => $this->records->formOptions(),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, int $record): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->update($actor, $this->records->find($actor, $record), $request->all());

        return back()->with('status', 'Candidate profile updated.');
    }

    public function expiries(Request $request, int $record): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->updateExpiries($actor, $this->records->find($actor, $record), $request->all());

        return back()->with('status', 'Expiry dates updated.');
    }

    public function reveal(Request $request, int $record): JsonResponse
    {
        $actor = $this->actor($request);
        $found = $this->records->find($actor, $record);
        abort_unless($this->records->passwordConfirmed($request), 423);

        return response()->json($this->records->reveal($actor, $found, $request->string('field')->toString()))->withHeaders([
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeDocument(Request $request, int $record): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->addDocument($actor, $this->records->find($actor, $record), $request);

        return back()->with('status', 'Document uploaded.');
    }

    public function downloadDocument(Request $request, int $record, int $document): BinaryFileResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        return $this->records->downloadDocument($actor, $this->records->find($actor, $record), $document, $request);
    }

    public function storePayment(Request $request, int $record): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->addPayment($actor, $this->records->find($actor, $record), $request->all());

        return back()->with('status', 'Payment recorded.');
    }

    public function storeReceipt(Request $request, int $record, int $candidatePayment): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->addReceipt($actor, $this->records->find($actor, $record), $candidatePayment, $request);

        return back()->with('status', 'Receipt uploaded.');
    }

    public function downloadReceipt(Request $request, int $record, int $receipt): BinaryFileResponse
    {
        $actor = $this->actor($request);

        return $this->records->downloadReceipt($actor, $this->records->find($actor, $record), $receipt);
    }

    public function checklist(Request $request, int $record, int $item): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->records->updateChecklist($actor, $this->records->find($actor, $record), $item, $request->all());

        return back()->with('status', 'Checklist updated.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $actor = $this->actor($request);
        $records = $this->records->exportQuery($actor, $this->filters($request))->get();

        return $this->workbook->download($records);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->toString(),
            'expiry' => $request->input('expiry', []),
            'page' => $request->integer('page') ?: 1,
        ];
    }
}
