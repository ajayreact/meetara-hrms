<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PayrollNoteRequest;
use App\Http\Requests\Hrms\StorePayrollRunRequest;
use App\Models\PayrollRun;
use App\Policies\PayrollRunPolicy;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayrollImport;
use App\Services\Hrms\PayrollRejected;
use App\Services\Hrms\PayrollService;
use App\Services\Hrms\PayrollTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PayrollController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'accounts', 'hr'], true), 403);

        return Inertia::render('payroll/index', [
            'overview' => $directory->overview($request->user(), $this->year($request), $this->month($request)),
            'history' => $directory->importHistory($request->user()),
            'canPrepare' => (new PayrollRunPolicy)->prepare($request->user()),
            'canImport' => $request->user()->can('import', PayrollRun::class),
            'importReady' => app(PayrollImport::class)->ready(),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function template(Request $request, PayrollTemplate $template): HttpResponse
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'hr', 'accounts'], true), 403);
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';

        try {
            $file = $template->download($format);
        } catch (PayrollRejected $exception) {
            abort(503, $exception->getMessage());
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function runs(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'accounts'], true), 403);

        return Inertia::render('payroll/runs', [
            'runs' => $directory->runs($request->user(), $request->string('status')->toString(), $this->year($request), $this->month($request)),
            'filter' => $request->string('status')->toString(),
            'year' => $this->year($request),
            'month' => $this->month($request),
            'canPrepare' => in_array($request->user()->applicationRole(), ['company_admin', 'accounts'], true),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(StorePayrollRunRequest $request, PayrollService $payroll): RedirectResponse
    {
        try {
            $run = $payroll->open($request->user(), (int) $request->validated('year'), (int) $request->validated('month'));
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hrms.payroll.show', $run)->with('status', 'Payroll run opened.');
    }

    public function show(Request $request, PayrollRun $payrollRun, PayrollDirectory $directory): Response
    {
        abort_unless($request->user()->can('view', $payrollRun), 404);

        return Inertia::render('payroll/show', [
            'run' => $directory->run($payrollRun),
            'canPrepare' => $request->user()->can('prepare', $payrollRun),
            'canApprove' => $request->user()->can('approve', $payrollRun),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function calculate(Request $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        abort_unless($request->user()->can('prepare', $payrollRun), 403);

        return $this->act(fn () => $payroll->calculate($request->user(), $payrollRun), 'Payroll calculated.');
    }

    public function review(PayrollNoteRequest $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        return $this->act(fn () => $payroll->review($request->user(), $payrollRun, (string) $request->validated('note')), 'Payroll sent for review.');
    }

    public function approve(PayrollNoteRequest $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        return $this->act(fn () => $payroll->approve($request->user(), $payrollRun, $request->validated('note')), 'Payroll approved.');
    }

    public function process(PayrollNoteRequest $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        return $this->act(fn () => $payroll->process($request->user(), $payrollRun), 'Payroll processed.');
    }

    public function import(Request $request, PayrollRun $payrollRun, PayrollDirectory $directory): Response
    {
        abort_unless((int) $payrollRun->tenant_id === (int) $request->user()->companyId(), 404);
        abort_unless($request->user()->can('import', $payrollRun) || $request->user()->can('view', $payrollRun), 404);

        return Inertia::render('payroll/import', [
            'review' => $directory->importReview($payrollRun),
            'canImport' => $request->user()->can('import', $payrollRun),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function storeImport(Request $request, PayrollImport $import): RedirectResponse
    {
        abort_unless($request->user()->can('import', PayrollRun::class), 403);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'file' => ['required', 'file', 'max:5120'],
        ]);
        $extension = strtolower((string) $request->file('file')?->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            return back()->withErrors(['file' => 'Upload a CSV or XLSX file.']);
        }

        try {
            $run = $import->stage($request->user(), (int) $data['year'], (int) $data['month'], $request->file('file'));
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hrms.payroll.import', $run)->with('status', 'Payroll file uploaded. Review the rows before confirming.');
    }

    public function remapImport(Request $request, PayrollRun $payrollRun, PayrollImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $payrollRun);
        $mapping = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:120'],
        ])['mapping'];

        try {
            $import->remap($request->user(), $payrollRun, $mapping);
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Column mapping updated.');
    }

    public function confirmImport(Request $request, PayrollRun $payrollRun, PayrollImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $payrollRun);

        try {
            $import->confirm($request->user(), $payrollRun);
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hrms.payroll.import', $payrollRun)->with('status', 'Payroll import confirmed. Payslips were generated from the imported amounts.');
    }

    public function retryPayslips(Request $request, PayrollRun $payrollRun, PayrollImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $payrollRun);

        try {
            $written = $import->generate($payrollRun, true);
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $written === 0 ? 'No payslip needed to be written again.' : 'Missing payslips were generated.');
    }

    public function correct(PayrollNoteRequest $request, PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        return $this->act(fn () => $payroll->correct($request->user(), $payrollRun, (string) $request->validated('note')), 'Payroll correction opened.');
    }

    private function act(callable $action, string $status): RedirectResponse
    {
        try {
            $action();
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $status);
    }

    private function authorizeImport(Request $request, PayrollRun $payrollRun): void
    {
        abort_unless((int) $payrollRun->tenant_id === (int) $request->user()->companyId(), 404);
        abort_unless($request->user()->can('import', $payrollRun), 403);
    }

    private function year(Request $request): ?int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : null;
    }

    private function month(Request $request): ?int
    {
        $month = $request->integer('month');

        return $month >= 1 && $month <= 12 ? $month : null;
    }
}
