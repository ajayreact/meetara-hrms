<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\I983Cases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class I983SubmissionController extends Controller
{
    public function __construct(private readonly I983Cases $cases) {}

    public function show(Request $request, string $token): Response
    {
        $page = $this->cases->publicPage($token);
        abort_if($page === null, 404);

        return Inertia::render('i983/submit', [
            'token' => $token,
            'form' => $page,
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        if ($request->filled('fax')) {
            abort(404);
        }

        $this->cases->saveStudent($token, $request->except(['fax', 'submit']), $request->boolean('submit'));

        return back()->with('status', $request->boolean('submit')
            ? 'Section 1 and your Section 2 certification are saved. Section 1 and Student Role are locked. You can still update Goals and Objectives. Employer fields stay with HR. This was not sent to a school or agency.'
            : 'Draft saved on this link. You can return with the same link until it expires.');
    }

    public function preview(string $token): HttpResponse
    {
        return $this->pdf($this->cases->studentFinal($token), 'inline');
    }

    public function download(string $token): HttpResponse
    {
        return $this->pdf($this->cases->studentFinal($token), 'attachment');
    }

    public function signature(string $token): HttpResponse
    {
        $file = $this->cases->studentSignatureFile($token);
        abort_if($file === null, 404);

        return response($file['bytes'], 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="student-signature.png"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array{bytes: string, filename: string}  $file
     */
    private function pdf(array $file, string $disposition): HttpResponse
    {
        $filename = str_replace(['"', "\r", "\n"], '', $file['filename']);

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
