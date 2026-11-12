<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LeadFollowUpReminderMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $recipientName,
        public string $companyName,
        public string $leadName,
        public string $leadPhone,
        public string $statusLabel,
        public string $followUpOn,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Lead follow-up reminder: '.$this->leadName);
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.lead-follow-up-reminder',
            text: 'mail.lead-follow-up-reminder-text',
            with: [
                'recipientName' => $this->recipientName,
                'companyName' => $this->companyName,
                'leadName' => $this->leadName,
                'leadPhone' => $this->leadPhone,
                'statusLabel' => $this->statusLabel,
                'followUpOn' => $this->followUpOn,
                'url' => $this->url,
            ],
        );
    }
}
