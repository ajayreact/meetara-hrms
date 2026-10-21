<?php

namespace App\Logging;

use Monolog\LogRecord;

class RedactSecrets
{
    public function __invoke(mixed $logger): void
    {
        if (! is_object($logger) || ! method_exists($logger, 'pushProcessor')) {
            return;
        }

        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: self::redact($record->message),
                context: self::redactValue($record->context),
            );
        });
    }

    public static function redact(string $value): string
    {
        $value = preg_replace('#(/invitations/accept/)[A-Za-z0-9]+#', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('#((?:token|password|session)=)[^&\s]+#i', '$1[redacted]', $value) ?? $value;

        return $value;
    }

    /**
     * @return mixed
     */
    private static function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = self::redactValue($item);
        }

        return $redacted;
    }
}
