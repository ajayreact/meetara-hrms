<?php

namespace App\Support\Candidate;

use App\Contracts\CaptchaVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier implements CaptchaVerifier
{
    public function required(): bool
    {
        return config('candidate.captcha') === 'required';
    }

    public function configured(): bool
    {
        return $this->siteKey() !== null && $this->secret() !== null;
    }

    public function siteKey(): ?string
    {
        $key = trim((string) config('candidate.turnstile_site_key', ''));

        return $key === '' ? null : $key;
    }

    public function verify(?string $token, ?string $ip): string
    {
        if (! $this->required()) {
            return 'accepted';
        }

        $secret = $this->secret();
        $token = trim((string) $token);

        if ($secret === null || $token === '' || strlen($token) > 2048) {
            return $token === '' ? 'rejected' : 'unavailable';
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => is_string($ip) ? $ip : '',
                ]);
        } catch (\Throwable) {
            Log::warning('Candidate verification could not be completed.', ['reason' => 'provider_unreachable']);

            return 'unavailable';
        }

        if (! $response->ok()) {
            Log::warning('Candidate verification could not be completed.', ['reason' => 'provider_status']);

            return 'unavailable';
        }

        if ($response->json('success') === true) {
            return 'accepted';
        }

        $errors = $response->json('error-codes');

        if (is_array($errors) && in_array('timeout-or-duplicate', $errors, true)) {
            return 'expired';
        }

        return 'rejected';
    }

    private function secret(): ?string
    {
        $secret = trim((string) config('candidate.turnstile_secret', ''));

        return $secret === '' ? null : $secret;
    }
}
