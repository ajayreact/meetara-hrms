<?php

namespace App\Support\Candidate;

use App\Contracts\MalwareScanner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ClamAvScanner implements MalwareScanner
{
    public function available(): bool
    {
        $command = $this->command();

        if ($command === null) {
            return false;
        }

        try {
            $process = new Process([$command, '--version']);
            $process->setTimeout(3);
            $process->run();

            return $process->getExitCode() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function scan(string $absolutePath): string
    {
        $command = $this->command();

        if ($command === null || ! is_file($absolutePath)) {
            return 'unavailable';
        }

        try {
            $process = new Process([$command, '--no-summary', $absolutePath]);
            $process->setTimeout((int) config('candidate.clamav_timeout', 10));
            $process->run();
            $exit = $process->getExitCode();
        } catch (ProcessTimedOutException) {
            Log::warning('Candidate malware scan did not finish.', ['reason' => 'timeout']);

            return 'unavailable';
        } catch (\Throwable) {
            Log::warning('Candidate malware scan could not run.', ['reason' => 'scanner_error']);

            return 'unavailable';
        }

        if ($exit === 0) {
            return 'clean';
        }

        if ($exit === 1) {
            return 'infected';
        }

        Log::warning('Candidate malware scan could not run.', ['reason' => 'scanner_exit', 'exit_code' => $exit]);

        return 'unavailable';
    }

    private function command(): ?string
    {
        $command = trim((string) config('candidate.clamav_command', ''));

        if ($command === '' || str_contains($command, "\0")) {
            return null;
        }

        return $command;
    }
}
