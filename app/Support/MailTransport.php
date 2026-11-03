<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class MailTransport
{
    public function ready(): bool
    {
        return $this->problem() === null;
    }

    /**
     * A message safe to show to HR or the person requesting a reset.
     * It never includes a password, API key, or token.
     */
    public function problem(): ?string
    {
        $mailer = (string) config('mail.default');

        if (app()->runningUnitTests() && $mailer === 'array') {
            return null;
        }

        $from = strtolower(trim((string) config('mail.from.address')));
        if ($from === '' || $from === 'hello@example.com' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return 'Mail delivery is not configured, so the email was not sent. Set a verified sender address and try again.';
        }

        if (! in_array($mailer, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            return 'Mail delivery is not configured, so the email was not sent.';
        }

        if ($mailer === 'smtp') {
            $host = trim((string) config('mail.mailers.smtp.host'));
            $username = trim((string) config('mail.mailers.smtp.username'));
            $password = trim((string) config('mail.mailers.smtp.password'));

            if ($host === '' || $username === '' || strcasecmp($username, 'null') === 0 || $password === '' || strcasecmp($password, 'null') === 0) {
                return 'SMTP is not fully configured, so the email was not sent. The mailbox password stays in the server environment and was not sent.';
            }

            $domain = strtolower(trim((string) config('mail.sender_domain')));
            $fromDomain = substr(strrchr($from, '@') ?: '', 1);

            if (str_contains($host, 'zeptomail') && $domain === '') {
                return 'The ZeptoMail sender domain is not configured, so the email was not sent. Set the verified sender domain and a matching from address.';
            }

            if ($domain !== '' && $fromDomain !== $domain) {
                return 'The sender address must be a verified address on '.$domain.', so the email was not sent.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    public function logFailure(string $action, Throwable $exception, array $context = []): void
    {
        Log::warning($action.' email was not sent.', $context + [
            'reason' => $this->reason($exception),
        ]);
    }

    private function reason(Throwable $exception): string
    {
        $text = strtolower($exception->getMessage());

        if (str_contains($text, 'timed out') || str_contains($text, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($text, 'authenticat') || str_contains($text, '535') || str_contains($text, 'credential')) {
            return 'authentication';
        }

        if (str_contains($text, 'sender') || str_contains($text, 'relay') || str_contains($text, '553')) {
            return 'sender';
        }

        if (str_contains($text, 'connect') || str_contains($text, 'connection')) {
            return 'connection';
        }

        return 'delivery';
    }
}
