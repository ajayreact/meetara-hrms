<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\PayrollItem;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayslipPdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PayslipController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'hr', 'accounts', 'employee'], true), 403);

        return Inertia::render('payslips/index', [
            'payslips' => $directory->payslips($request->user()),
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

        $slip = $directory->payslip($payslip);
        $slip['generated'] = now()->toDateString();

        return response($pdf->render($slip), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payslip.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
