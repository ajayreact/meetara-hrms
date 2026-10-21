<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyAdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to administer a Meetara company');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.company-admin-invitation',
            text: 'mail.company-admin-invitation-text',
            with: [
                'companyName' => $this->companyName,
                'url' => route('invitations.accept', ['token' => $this->token]),
            ],
        );
    }
}
