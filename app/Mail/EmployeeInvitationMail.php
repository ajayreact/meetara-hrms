<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class EmployeeInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $employeeName,
        public string $companyName,
        public Carbon $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set your Veltrix HRMS password');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.employee-invitation',
            text: 'mail.employee-invitation-text',
            with: [
                'employeeName' => $this->employeeName,
                'companyName' => $this->companyName,
                'expiresAt' => $this->expiresAt->timezone((string) config('app.timezone'))->format('d M Y, H:i T'),
                'hours' => max(1, (int) config('hrms.employee_invitation_hours', 72)),
                'url' => route('employee-invitations.accept', ['token' => $this->token]),
            ],
        );
    }
}
