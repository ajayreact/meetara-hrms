<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\LeadImport;
use App\Services\Hrms\LeadImportRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadImportController extends Controller
{
    public function index(Request $request, LeadImport $import): Response
    {
        $import->assertImporter($request->user());

        return Inertia::render('leads/import', [
            'preview' => null,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(Request $request, LeadImport $import): RedirectResponse
    {
        $import->assertImporter($request->user());
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $batch = $import->stage($request->user(), $request->file('file'));
        } catch (LeadImportRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hrms.leads.import', $batch)->with('status', 'File uploaded. Review the preview, then confirm to import new leads.');
    }

    public function show(Request $request, int $batch, LeadImport $import): Response
    {
        $record = $import->find($request->user(), $batch);

        try {
            $preview = $import->present($request->user(), $record);
        } catch (LeadImportRejected $exception) {
            return Inertia::render('leads/import', [
                'preview' => null,
                'notice' => session('status'),
                'error' => $exception->getMessage(),
            ]);
        }

        return Inertia::render('leads/import', [
            'preview' => $preview,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function confirm(Request $request, int $batch, LeadImport $import): RedirectResponse
    {
        $record = $import->find($request->user(), $batch);

        try {
            $import->confirm($request->user(), $record);
        } catch (LeadImportRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hrms.leads.import', $record)->with('status', 'New leads were imported. They remain unassigned.');
    }
}
