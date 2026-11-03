<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\OfferLetterCatalog;
use App\Services\Hrms\OfferLetters;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferLetterTemplateController extends Controller
{
    public function __construct(private readonly OfferLetterCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $page = $this->settingsPayload($actor, $request->query('country'), $request->session()->get('status'));

        if ($page['country'] === 'usa') {
            return Inertia::render('offer-letters/templates', $page);
        }

        if ($request->query('editor') === '1') {
            return Inertia::render('offer-letters/india-editor', $page);
        }

        return Inertia::render('offer-letters/india-settings', $page);
    }

    public function saveLegal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:191'],
            'legal_address' => ['required', 'string', 'max:1000'],
            'registration_details' => ['nullable', 'string', 'max:191'],
            'signatory_name' => ['required', 'string', 'max:191'],
            'signatory_designation' => ['required', 'string', 'max:191'],
            'country' => ['nullable', 'in:usa,india'],
            'hr_email' => ['required', 'email', 'max:191'],
            'verified' => ['prohibited'],
            'verified_by' => ['prohibited'],
        ]);
        $this->catalog->saveLegal($this->actor($request), $data);

        return back()->with('status', 'Legal details saved. They stay unverified until a different authorized user verifies them.');
    }

    public function verifyLegal(Request $request): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'VERIFY', 422);
        $country = $request->input('country');
        $this->catalog->verifyLegal($this->actor($request), is_string($country) ? $country : null);

        return back()->with('status', 'Company legal details verified. The company display name was not used as the legal name.');
    }

    public function storeLetterhead(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['required', 'in:usa,india'],
            'letterhead' => ['required', 'file', 'max:5120'],
            'printed_legal_name' => ['nullable', 'string', 'max:191'],
            'printed_address' => ['nullable', 'string', 'max:1000'],
            'margin_top' => ['nullable', 'integer', 'min:36', 'max:240'],
            'margin_right' => ['nullable', 'integer', 'min:36', 'max:180'],
            'margin_bottom' => ['nullable', 'integer', 'min:36', 'max:240'],
            'margin_left' => ['nullable', 'integer', 'min:36', 'max:180'],
            'header_height' => ['nullable', 'integer', 'min:0', 'max:240'],
            'footer_height' => ['nullable', 'integer', 'min:0', 'max:200'],
            'page_width' => ['nullable', 'numeric', 'min:200', 'max:1200'],
            'page_height' => ['nullable', 'numeric', 'min:200', 'max:1400'],
        ]);
        $this->catalog->storeLetterhead($this->actor($request), $data['country'], $request->file('letterhead'), [
            'margin_top' => (int) ($data['margin_top'] ?? ($data['country'] === 'india' ? 110 : 72)),
            'margin_right' => (int) ($data['margin_right'] ?? ($data['country'] === 'india' ? 54 : 48)),
            'margin_bottom' => (int) ($data['margin_bottom'] ?? ($data['country'] === 'india' ? 70 : 56)),
            'margin_left' => (int) ($data['margin_left'] ?? ($data['country'] === 'india' ? 54 : 48)),
            'header_height' => (int) ($data['header_height'] ?? ($data['country'] === 'india' ? 96 : 0)),
            'footer_height' => (int) ($data['footer_height'] ?? ($data['country'] === 'india' ? 72 : 0)),
            'page_width' => $data['page_width'] ?? ($data['country'] === 'india' ? 612 : null),
            'page_height' => $data['page_height'] ?? ($data['country'] === 'india' ? 792 : null),
            'printed_legal_name' => $request->input('printed_legal_name'),
            'printed_address' => $request->input('printed_address'),
        ]);

        return back()->with('status', 'Letterhead stored for this company. A single-page PDF, PNG, or JPG can sit behind the letter. A completed appointment letter is not used as a blank background.');
    }

    public function saveLetterheadLayout(Request $request, int $letterhead): RedirectResponse
    {
        $data = $request->validate([
            'margin_top' => ['required', 'integer', 'min:36', 'max:240'],
            'margin_right' => ['required', 'integer', 'min:36', 'max:180'],
            'margin_bottom' => ['required', 'integer', 'min:36', 'max:240'],
            'margin_left' => ['required', 'integer', 'min:36', 'max:180'],
            'header_height' => ['required', 'integer', 'min:0', 'max:240'],
            'footer_height' => ['required', 'integer', 'min:0', 'max:200'],
            'page_width' => ['required', 'numeric', 'min:200', 'max:1200'],
            'page_height' => ['required', 'numeric', 'min:200', 'max:1400'],
        ]);
        $this->catalog->saveLetterheadLayout($this->actor($request), $letterhead, $data);

        return back()->with('status', 'Letterhead layout saved. Issued offer letters keep the pages they were generated with.');
    }

    public function selectLetterhead(Request $request, int $letterhead): RedirectResponse
    {
        $this->catalog->selectLetterhead($this->actor($request), $letterhead);

        return back()->with('status', 'That letterhead is now the active file. The previous file stays in history, and the letterhead confirmation was cleared.');
    }

    public function saveLetterheadIdentity(Request $request, int $letterhead): RedirectResponse
    {
        $data = $request->validate([
            'printed_legal_name' => ['required', 'string', 'max:191'],
            'printed_address' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->catalog->saveLetterheadIdentity($this->actor($request), $letterhead, $data['printed_legal_name'], (string) ($data['printed_address'] ?? ''));

        return back()->with('status', 'Printed letterhead identity saved. It is not confirmed, and a mismatch still blocks the final PDF.');
    }

    public function deactivateLetterhead(Request $request, int $letterhead): RedirectResponse
    {
        $this->catalog->deactivateLetterhead($this->actor($request), $letterhead);

        return back()->with('status', 'Letterhead deactivated. Issued offer letters keep the file they were generated with.');
    }

    public function previewLetterhead(Request $request, int $letterhead): StreamedResponse
    {
        return $this->catalog->previewLetterhead($this->actor($request), $letterhead);
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['required', 'in:usa,india'],
            'name' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:60000'],
            'template_id' => ['nullable', 'integer'],
            'approved_by' => ['prohibited'],
            'status' => ['prohibited'],
        ]);
        $this->catalog->saveTemplate($this->actor($request), $data);

        return back()->with('status', 'Template draft saved. It cannot be used for issuance until another person approves it and it is activated.');
    }

    public function duplicateTemplate(Request $request, int $template): RedirectResponse
    {
        $this->catalog->duplicateTemplate($this->actor($request), $template);

        return back()->with('status', 'A separate draft copy was saved. It is not approved or active.');
    }

    public function submitTemplate(Request $request, int $template): RedirectResponse
    {
        $this->catalog->submitTemplate($this->actor($request), $template);

        return back()->with('status', 'Template submitted for review.');
    }

    public function approveTemplate(Request $request, int $template): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'APPROVE', 422);
        $this->catalog->approveTemplate($this->actor($request), $template);

        return back()->with('status', 'Template version approved. Activate it before offer letters can use it.');
    }

    public function activateTemplate(Request $request, int $template): RedirectResponse
    {
        abort_unless($request->input('confirm') === 'ACTIVATE', 422);
        $this->catalog->activateTemplate($this->actor($request), $template);

        return back()->with('status', 'Template is active for this country. Earlier issued letters were not changed.');
    }

    public function samplePdf(Request $request): HttpResponse
    {
        $data = $request->validate([
            'country' => ['required', 'in:usa,india'],
            'body' => ['required', 'string', 'max:60000'],
        ]);
        $pdf = $this->catalog->samplePdf($this->actor($request), $data['country'], $data['body']);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="appointment-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function loadIndiaSource(Request $request): RedirectResponse
    {
        $this->catalog->loadIndiaSource($this->actor($request));

        return back()->with('status', 'India appointment letter saved as a draft. It is not approved or active.');
    }

    public function storeSignature(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'in:usa,india'],
            'signature' => ['required', 'file', 'max:2048'],
        ]);
        $this->catalog->storeSignature($this->actor($request), $request->file('signature'), $data['country'] ?? null);

        return back()->with('status', 'Signatory signature stored privately. It is an image placed on the letter, not a legally verified electronic signature.');
    }

    public function removeSignature(Request $request): RedirectResponse
    {
        $country = $request->input('country');
        $this->catalog->removeSignature($this->actor($request), is_string($country) ? $country : null);

        return back()->with('status', 'Signatory signature removed. Issued letters keep the signature image they were generated with.');
    }

    public function setSignatureActive(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'country' => ['required', 'in:usa,india'],
            'active' => ['required', 'boolean'],
        ]);
        $this->catalog->setSignatureActive($this->actor($request), $data['country'], (bool) $data['active']);

        return back()->with('status', $data['active']
            ? 'Signature activated for new letters. Issued letters keep the signature they were generated with.'
            : 'Signature deactivated. The image stays on file, and issued letters are unchanged.');
    }

    public function saveSignatureSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'width' => ['required', 'integer', 'min:40', 'max:240'],
            'height' => ['required', 'integer', 'min:16', 'max:120'],
            'align' => ['required', 'in:left,center,right'],
            'gap_before' => ['required', 'integer', 'min:0', 'max:40'],
            'gap_after' => ['required', 'integer', 'min:0', 'max:40'],
            'country' => ['nullable', 'in:usa,india'],
        ]);
        $this->catalog->saveSignatureSettings($this->actor($request), $data);

        return back()->with('status', 'Signature placement settings saved.');
    }

    public function showSignature(Request $request): StreamedResponse
    {
        $country = $request->query('country');

        return $this->catalog->signatureFile($this->actor($request), is_string($country) ? $country : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(User $actor, mixed $country, mixed $notice): array
    {
        $page = $this->catalog->page($actor, is_string($country) ? $country : null);
        $legal = $page['legal'] ?? null;

        return [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$page,
            'notice' => is_string($notice) ? $notice : null,
            'approvalRequired' => app(OfferLetters::class)->approvalRequired((int) $actor->companyId()),
            'canConfigure' => $actor->applicationRole() === 'company_admin',
            'identity' => $this->catalog->letterheadIdentity($actor, (string) $page['country']),
            'actorId' => (int) $actor->id,
            'canVerifyLegal' => is_array($legal)
                && ($legal['statusKey'] ?? '') === 'pending_verification'
                && ($legal['updatedBy'] ?? null) !== (int) $actor->id
                && trim((string) ($legal['legalName'] ?? '')) !== '',
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
