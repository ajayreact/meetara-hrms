<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\I983Cases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class I983CaseController extends Controller
{
    public function __construct(private readonly I983Cases $cases) {}

    public function index(Request $request): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('i983/index', [
            'cases' => $this->cases->list((int) $actor->companyId()),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function destroy(Request $request, int $case): RedirectResponse
    {
        $this->cases->remove($this->manager($request), $case);

        return redirect()->route('hrms.i983')->with('status', 'I-983 case deleted.');
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $opened = $this->cases->open($actor, $request->all());

        return redirect()
            ->route('hrms.i983.show', $opened['id'])
            ->with('status', 'I-983 case created. Share the student link. It expires in 14 days and was not emailed.')
            ->with('i983_link', $opened['url']);
    }

    public function show(Request $request, int $case): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('i983/show', [
            ...$this->cases->show($actor, $case),
            'linkUrl' => $request->session()->get('i983_link'),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function saveEmployer(Request $request, int $case): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->cases->saveEmployer($actor, $case, $request->all(), false);

        return back()->with('status', 'Employer information saved. Review it before finalizing.');
    }

    public function finalizeEmployer(Request $request, int $case): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->cases->saveEmployer($actor, $case, $request->all(), true);

        return back()->with('status', 'Employer information reviewed and finalized. Nothing was filed or signed.');
    }

    public function saveTraining(Request $request, int $case): RedirectResponse
    {
        $actor = $this->manager($request);
        $this->cases->saveTraining($actor, $case, $request->all());

        return back()->with('status', 'Training plan saved. Student name and student role were left unchanged.');
    }

    public function requestCorrection(Request $request, int $case): RedirectResponse
    {
        $this->cases->requestCorrection($this->manager($request), $case, $request->all());

        return back()->with('status', 'Correction requested. The student can update Section 1 and Student Role. Employer HR can also update Student Role. Employer fields were not changed.');
    }

    public function revoke(Request $request, int $case): RedirectResponse
    {
        $this->cases->revoke($this->manager($request), $case);

        return back()->with('status', 'The student link has been revoked.');
    }

    public function reissue(Request $request, int $case): RedirectResponse
    {
        $url = $this->cases->reissue($this->manager($request), $case);

        return back()
            ->with('status', 'A new student link is ready. The previous link no longer works.')
            ->with('i983_link', $url);
    }

    public function studentSnapshot(Request $request, int $case): RedirectResponse
    {
        $this->cases->generateStudentSnapshot($this->manager($request), $case);

        return back()->with('status', 'A new student-submitted PDF version was stored. It was not downloaded or emailed.');
    }

    public function workingCopy(Request $request, int $case): RedirectResponse
    {
        $this->cases->generateWorkingCopy($this->manager($request), $case);

        return back()->with('status', 'A new HR working PDF version was stored. It was not downloaded or emailed.');
    }

    public function draft(Request $request, int $case): HttpResponse
    {
        return $this->pdf($this->cases->draftFile($this->manager($request), $case), 'inline');
    }

    public function preview(Request $request, int $case, int $document): HttpResponse
    {
        return $this->pdf($this->cases->documentFile($this->manager($request), $case, $document), 'inline');
    }

    public function download(Request $request, int $case, int $document): HttpResponse
    {
        return $this->pdf($this->cases->documentFile($this->manager($request), $case, $document), 'attachment');
    }

    public function saveSignatory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'signatory_name' => ['required', 'string', 'max:160'],
            'signatory_title' => ['required', 'string', 'max:120'],
            'signature' => ['nullable', 'file', 'max:1024', 'mimes:png,jpg,jpeg'],
        ]);
        $this->cases->saveSignatory($this->manager($request), $data['signatory_name'], $data['signatory_title'], $request->file('signature'));

        return back()->with('status', 'Employer signature saved. No I-983 was signed.');
    }

    public function showSignatory(Request $request): HttpResponse
    {
        $image = $this->cases->signatoryImage($this->manager($request));

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function applySignature(Request $request, int $case): RedirectResponse
    {
        $this->cases->applySignature($this->manager($request), $case, $request->all());

        return back()->with('status', 'Employer signature applied. A new signed PDF was stored. The unsigned working PDF was not changed, and nothing was emailed.');
    }

    public function generateFinal(Request $request, int $case): RedirectResponse
    {
        $this->cases->generateFinal($this->manager($request), $case, $request->all());

        return back()->with('status', 'The final I-983 PDF is stored. Earlier versions were not replaced, and nothing was emailed.');
    }

    public function finalizeDocument(Request $request, int $case): RedirectResponse
    {
        $this->cases->finalizeDocument($this->manager($request), $case, $request->all());

        return back()->with('status', 'I-983 finalized. The final PDF is stored separately. Earlier versions were not changed, and nothing was emailed.');
    }

    /**
     * @param  array{bytes: string, filename: string}  $file
     */
    private function pdf(array $file, string $disposition): HttpResponse
    {
        return response($file['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$file['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->companyId() !== null && $this->cases->manages($user), 403);

        return $user;
    }
}
