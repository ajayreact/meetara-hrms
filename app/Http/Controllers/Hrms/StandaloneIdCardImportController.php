<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\StandaloneIdCardImport;
use App\Services\Hrms\StandaloneIdCardImportRejected;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StandaloneIdCardImportController extends Controller
{
    public function __construct(private readonly StandaloneIdCardImport $import) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $this->import->assertImporter($actor);

        return Inertia::render('id-cards/standalone/import', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'preview' => null,
            'batches' => $this->import->history($actor),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function template(Request $request): StreamedResponse|HttpResponse
    {
        $file = $this->import->templateDownload($request->user());

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->import->assertImporter($request->user());
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $batch = $this->import->stage($request->user(), $request->file('file'));
        } catch (StandaloneIdCardImportRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('hrms.id-cards.standalone.import.show', $batch)
            ->with('status', 'File uploaded. Review the preview, then confirm to create draft standalone ID cards.');
    }

    public function show(Request $request, int $batch): Response
    {
        $actor = $request->user();
        $record = $this->import->find($actor, $batch);

        try {
            $preview = $this->import->present($actor, $record);
        } catch (StandaloneIdCardImportRejected $exception) {
            return Inertia::render('id-cards/standalone/import', [
                'workspace' => AuthenticatedWorkspace::resolve($actor),
                'preview' => null,
                'batches' => $this->import->history($actor),
                'notice' => session('status'),
                'error' => $exception->getMessage(),
            ]);
        }

        return Inertia::render('id-cards/standalone/import', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'preview' => $preview,
            'batches' => $this->import->history($actor),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function confirm(Request $request, int $batch): RedirectResponse
    {
        $record = $this->import->find($request->user(), $batch);

        try {
            $this->import->confirm($request->user(), $record);
        } catch (StandaloneIdCardImportRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('hrms.id-cards.standalone.import.show', $record)
            ->with('status', 'Draft standalone ID cards were created. Upload photos before issuing.');
    }
}
