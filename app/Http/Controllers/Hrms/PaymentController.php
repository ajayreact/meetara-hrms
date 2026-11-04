<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\RecordPaymentRequest;
use App\Models\PayrollPayment;
use App\Services\Hrms\PayrollDirectory;
use App\Services\Hrms\PayrollRejected;
use App\Services\Hrms\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request, PayrollDirectory $directory): Response
    {
        abort_unless(in_array($request->user()->applicationRole(), ['company_admin', 'accounts'], true), 403);

        return Inertia::render('payroll/payments', [
            'payments' => $directory->payments($request->user(), $request->string('status')->toString()),
            'filter' => $request->string('status')->toString(),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(RecordPaymentRequest $request, PayrollPayment $payment, PayrollService $payroll): RedirectResponse
    {
        try {
            $payroll->markPaid($request->user(), $payment, $request->validated());
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Payment recorded. This is a record of confirmation, not a bank transfer.');
    }

    public function reverse(RecordPaymentRequest $request, PayrollPayment $payment, PayrollService $payroll): RedirectResponse
    {
        try {
            $payroll->reversePayment($request->user(), $payment, (string) $request->validated('note'));
        } catch (PayrollRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Payment reversed.');
    }
}
