<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PayrollNoteRequest;
use App\Http\Requests\Hrms\StorePayrollRunRequest;
use App\Models\PayrollRun;
use App\Policies\PayrollRunPolicy;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayrollRejected;
use App\Services\Hrms\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'accounts'], true), 403);

        return Inertia::render('payroll/index', [
            'overview' => $directory->overview($request->user(), $this->year($request), $this->month($request)),
            'canPrepare' => (new PayrollRunPolicy)->prepare($request->user()),
            'notice' => session('status'),
            'error' => session('error'),
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
            'canPrepare' => $request->user()->applicationRole() === 'accounts',
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
