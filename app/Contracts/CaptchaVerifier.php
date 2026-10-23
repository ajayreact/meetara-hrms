<?php

namespace App\Contracts;

interface CaptchaVerifier
{
    public function required(): bool;

    public function configured(): bool;

    /**
     * Public site key for the browser widget. Never a secret.
     */
    public function siteKey(): ?string;

    /**
     * accepted, rejected, or unavailable. Unavailable is a failed check.
     */
    public function verify(?string $token, ?string $ip): string;
}
