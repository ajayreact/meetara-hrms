<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\PayrollItem;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayslipPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PayslipController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'hr', 'accounts', 'team_lead', 'employee'], true), 403);

        $year = $request->integer('year');
        $month = $request->integer('month');
        $search = trim($request->string('search')->toString());

        return Inertia::render('payslips/index', [
            'payslips' => $directory->payslips(
                $request->user(),
                $year >= 2000 && $year <= 2100 ? $year : null,
                $month >= 1 && $month <= 12 ? $month : null,
                $search !== '' ? $search : null,
            ),
            'filters' => [
                'year' => $year >= 2000 && $year <= 2100 ? $year : null,
                'month' => $month >= 1 && $month <= 12 ? $month : null,
                'search' => $search,
            ],
            'canDownloadAll' => in_array($request->user()->applicationRole(), ['company_admin', 'accounts', 'hr'], true),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function show(Request $request, PayrollItem $payslip, PayrollDirectory $directory): Response
    {
        abort_unless($request->user()->can('view', $payslip), 404);

        return Inertia::render('payslips/show', [
            'payslip' => [
                ...$directory->payslip($payslip),
                'generated' => now()->toDateString(),
            ],
        ]);
    }

    public function pdf(Request $request, PayrollItem $payslip, PayrollDirectory $directory, PayslipPdf $pdf): HttpResponse
    {
        abort_unless($request->user()->can('view', $payslip), 404);

        $stored = $this->storedPdf($payslip);

        if ($stored !== null) {
            return response($stored, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->filename($directory->payslip($payslip)).'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $slip = $directory->payslip($payslip);
        $slip['generated'] = now()->toDateString();

        return response($pdf->render($slip), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($slip).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archive(Request $request, PayrollDirectory $directory, PayslipPdf $pdf): HttpResponse
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'accounts', 'hr'], true), 403);
        abort_unless(class_exists(\ZipArchive::class), 503, 'Bulk payslip download is not available on this server.');

        $year = $request->integer('year');
        $month = $request->integer('month');
        $slips = $directory->payslips(
            $request->user(),
            $year >= 2000 && $year <= 2100 ? $year : null,
            $month >= 1 && $month <= 12 ? $month : null,
            null,
        );

        if ($slips === []) {
            return back()->with('error', 'No payslips are available for that period.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'payslips');
        $zip = new \ZipArchive;

        if ($temporary === false || $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(503, 'Bulk payslip download could not be prepared.');
        }

        foreach ($slips as $slip) {
            $item = PayrollItem::query()->find($slip['id']);
            $stored = $item ? $this->storedPdf($item) : null;
            $slip['generated'] = now()->toDateString();
            $zip->addFromString($this->filename($slip), $stored ?? $pdf->render($slip));
        }

        $zip->close();

        return response()->download($temporary, 'payslips-'.($year ?: 'all').'-'.($month ?: 'all').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function storedPdf(PayrollItem $payslip): ?string
    {
        $path = $payslip->payslip_path;

        if (! is_string($path) || $path === '' || $payslip->payslip_error !== null) {
            return null;
        }

        $prefix = 'payroll-payslips/'.(int) $payslip->tenant_id.'/';

        if (! str_starts_with($path, $prefix) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * @param  array<string, mixed>  $slip
     */
    private function filename(array $slip): string
    {
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($slip['code'] ?: $slip['id'])) ?: 'payslip';

        return 'payslip-'.$slip['period'].'-'.$code.'.pdf';
    }
}
