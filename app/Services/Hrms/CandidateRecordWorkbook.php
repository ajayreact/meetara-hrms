<?php

namespace App\Services\Hrms;

use App\Models\CandidateChecklistItem;
use App\Models\CandidatePayment;
use App\Models\CandidateRecord;
use App\Support\CandidateRegistry;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidateRecordWorkbook
{
    /**
     * @param  Collection<int, CandidateRecord>  $records
     */
    public function download(Collection $records): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Veltrix HRMS')->setTitle('Candidate Management');
        $candidates = $book->getActiveSheet();
        $candidates->setTitle('Candidates');
        $payments = $book->createSheet();
        $payments->setTitle('Payments');

        $candidateHeaders = [
            'Reference', 'First name', 'Last name', 'Email', 'Phone', 'Job title', 'University',
            'Work authorization', 'Hourly rate', 'Hours per week', 'Start date',
            'Payment received', 'Stated amount', 'Stated method',
        ];

        foreach (CandidateRegistry::CHECKLIST as $definition) {
            $candidateHeaders[] = $definition['label'];
        }

        array_push(
            $candidateHeaders,
            'Completion percent',
            'Work authorization expiry',
            'EAD expiry',
            'EAD alert',
            'Passport expiry',
            'Passport alert',
            'Registered',
            'Updated',
            'SSN last four',
            'Account last four',
            'Routing last four',
            'I-94 last four',
            'EAD number last four',
        );
        $this->writeRow($candidates, 1, $candidateHeaders);

        $paymentHeaders = ['Reference', 'Candidate', 'Amount', 'Currency', 'Paid on', 'Method', 'Transaction reference', 'Notes', 'Receipts'];
        $this->writeRow($payments, 1, $paymentHeaders);
        $paymentRow = 2;

        foreach ($records->values() as $index => $record) {
            $ead = CandidateRegistry::assess($record->ead_expiry_date?->toDateString(), 'ead');
            $passport = CandidateRegistry::assess($record->passport_expiry_date?->toDateString(), 'passport');
            $checklist = [];

            foreach (array_keys(CandidateRegistry::CHECKLIST) as $key) {
                $item = $record->checklistItems->firstWhere('item_key', $key);
                $definition = CandidateRegistry::CHECKLIST[$key];
                $checklist[] = $item instanceof CandidateChecklistItem
                    ? ($definition['options'][$item->status] ?? $item->status)
                    : '';
            }

            $complete = $record->checklistItems->filter(function (CandidateChecklistItem $item): bool {
                $definition = CandidateRegistry::CHECKLIST[$item->item_key] ?? null;

                return $definition !== null && $item->status === $definition['complete'];
            })->count();
            $percent = (int) round(($complete / count(CandidateRegistry::CHECKLIST)) * 100);
            $values = [
                $record->reference,
                $record->first_name,
                $record->last_name,
                $record->email,
                $record->phone,
                $record->job_title,
                $record->university_name,
                $record->work_authorization,
                (string) $record->hourly_rate,
                (string) $record->hours_per_week,
                $record->start_date?->toDateString() ?? '',
                $record->payment_received,
                (string) $record->stated_amount,
                $record->stated_payment_method,
                ...$checklist,
                (string) $percent,
                $record->work_authorization_expires_on?->toDateString() ?? '',
                $ead['date'] ?? '',
                $ead['label'],
                $passport['date'] ?? '',
                $passport['label'],
                $record->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '',
                $record->updated_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i') ?? '',
                CandidateRegistry::mask('ssn', $record->ssn_last4),
                CandidateRegistry::mask('account', $record->account_last4),
                CandidateRegistry::mask('routing', $record->routing_last4),
                CandidateRegistry::mask('i94', $record->i94_last4),
                CandidateRegistry::mask('ead_number', $record->ead_last4),
            ];
            $this->writeRow($candidates, $index + 2, $values);

            foreach ($record->payments as $payment) {
                if (! $payment instanceof CandidatePayment) {
                    continue;
                }

                $this->writeRow($payments, $paymentRow, [
                    $record->reference,
                    trim($record->first_name.' '.$record->last_name),
                    (string) $payment->amount,
                    'USD',
                    $payment->paid_on?->toDateString() ?? '',
                    (string) $payment->method,
                    (string) ($payment->reference ?? ''),
                    (string) ($payment->notes ?? ''),
                    (string) $payment->receipts->count(),
                ]);
                $paymentRow++;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cmr');

        if ($tmp === false) {
            abort(500);
        }

        (new Xlsx($book))->save($tmp);
        $book->disconnectWorksheets();

        $response = response()->download($tmp, 'candidate-management-'.CandidateRegistry::today()->toDateString().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param  list<string|null>  $values
     */
    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, $row], (string) $value, DataType::TYPE_STRING);
        }
    }
}
