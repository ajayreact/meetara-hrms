<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\LeadSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadSourceController extends Controller
{
    public function index(Request $request, LeadSources $sources): Response
    {
        return Inertia::render('leads/sources', [
            ...$sources->page($request->user()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(Request $request, LeadSources $sources): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $sources->create($request->user(), $data['name']);

        return redirect()->route('hrms.leads.sources')->with('status', 'Lead source saved.');
    }

    public function update(Request $request, int $source, LeadSources $sources): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'active' => ['required', 'boolean'],
        ]);
        $sources->update($request->user(), $source, $data['name'], (bool) $data['active']);

        return redirect()->route('hrms.leads.sources')->with('status', 'Lead source updated.');
    }

    public function attach(Request $request, int $lead, LeadSources $sources): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer'],
        ]);

        try {
            $sources->attach($request->user(), $lead, (int) $data['source_id']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', 'Original source saved.');
    }
}
