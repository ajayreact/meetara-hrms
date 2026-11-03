<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    public function __construct(
        public string $token,
        public string $email,
        public string $name,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Meetara HRMS password');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.password-reset',
            text: 'mail.password-reset-text',
            with: [
                'name' => $this->name,
                'minutes' => (int) config('auth.passwords.users.expire'),
                'url' => route('password.reset', ['token' => $this->token, 'email' => $this->email]),
            ],
        );
    }
}
