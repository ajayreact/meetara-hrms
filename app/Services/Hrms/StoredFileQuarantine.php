<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves selected private files aside, runs one database transaction, then deletes the copies.
 * A database failure puts the files back. A cleanup failure does not.
 */
class StoredFileQuarantine
{
    /** @var list<array{from: string, to: string}> */
    private array $moved = [];

    private string $prefix;

    public function __construct()
    {
        $this->prefix = 'quarantine/'.str_replace('-', '', (string) Str::uuid());
    }

    /**
     * @param  list<string>  $paths
     */
    public function commit(array $paths, \Closure $database): void
    {
        try {
            $this->move($paths);
        } catch (\RuntimeException $exception) {
            $this->restore();

            throw $exception;
        }

        try {
            DB::transaction($database);
        } catch (\Throwable $exception) {
            $this->restore();

            throw $exception;
        }

        try {
            $this->purge();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    public function move(array $paths): void
    {
        try {
            foreach ($paths as $path) {
                $this->relocate($path);
            }
        } catch (\Throwable $exception) {
            $this->restore();

            throw $exception instanceof \RuntimeException
                ? $exception
                : new \RuntimeException('The file could not be quarantined.', 0, $exception);
        }
    }

    public function restore(): void
    {
        $disk = Storage::disk('local');

        foreach (array_reverse($this->moved) as $item) {
            if (! $disk->exists($item['to'])) {
                continue;
            }

            if ($disk->exists($item['from'])) {
                throw new \RuntimeException('The original file reappeared during restore.');
            }

            $this->transfer($item['to'], $item['from']);
        }

        $this->moved = [];
        $this->removePrefix();
    }

    public function recover(int $companyId): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('quarantine')) {
            return;
        }

        foreach ($disk->directories('quarantine') as $directory) {
            $directory = str_replace('\\', '/', $directory);

            if (preg_match('#^quarantine/[a-f0-9]{32}$#', $directory) !== 1) {
                Log::warning('Quarantine directory was left in place because its name is not a deletion id.', [
                    'directory' => $directory,
                ]);

                continue;
            }

            foreach ($disk->allFiles($directory) as $stored) {
                $this->recoverFile(str_replace('\\', '/', $stored), $directory, $companyId);
            }

            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    public function purge(): void
    {
        $disk = Storage::disk('local');

        foreach ($this->moved as $item) {
            if (! str_starts_with($item['to'], $this->prefix.'/')) {
                throw new \RuntimeException('The quarantine path is not inside this deletion.');
            }

            $disk->delete($item['to']);
        }

        $this->moved = [];
        $this->removePrefix();
    }

    private function relocate(string $path): void
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_starts_with($path, 'quarantine/')) {
            throw new \RuntimeException('The file path cannot be quarantined.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return;
        }

        $destination = $this->prefix.'/'.$path;
        $this->transfer($path, $destination);
        $this->moved[] = ['from' => $path, 'to' => $destination];
    }

    /**
     * @return array{kind: string, companyId: int, recordId: int}|null
     */
    private function target(string $path): ?array
    {
        if (str_contains($path, '..')) {
            return null;
        }

        if (preg_match('#^usa-offers/([1-9][0-9]*)/([1-9][0-9]*)\.pdf$#', $path, $offer) === 1) {
            return ['kind' => 'usa_offer', 'companyId' => (int) $offer[1], 'recordId' => (int) $offer[2]];
        }

        if (preg_match('#^i983/([1-9][0-9]*)/([1-9][0-9]*)/.+$#', $path, $case) === 1) {
            return ['kind' => 'i983', 'companyId' => (int) $case[1], 'recordId' => (int) $case[2]];
        }

        return null;
    }

    private function recoverFile(string $stored, string $directory, int $companyId): void
    {
        if (! str_starts_with($stored, $directory.'/')) {
            return;
        }

        $original = substr($stored, strlen($directory) + 1);
        $target = is_string($original) ? $this->target($original) : null;

        if (! is_string($original) || $target === null) {
            Log::warning('Quarantine file was left in place because it is not a USA offer or I-983 case file.', [
                'path' => is_string($original) ? $original : $stored,
            ]);

            return;
        }

        if ($target['companyId'] !== $companyId) {
            return;
        }

        $context = [
            'tenant_id' => $target['companyId'],
            'kind' => $target['kind'],
            'record_id' => $target['recordId'],
            'path' => $original,
        ];

        $state = $this->recordState($target);

        if ($state === 'present') {
            $this->restoreOriginal($stored, $original, $context);

            return;
        }

        if ($state === 'absent') {
            $this->removeQuarantined($stored, $context);
        }
    }

    /**
     * @param  array{kind: string, companyId: int, recordId: int}  $target
     */
    private function recordState(array $target): string
    {
        $table = $target['kind'] === 'usa_offer' ? 'usa_offers' : 'i983_cases';

        if (! Schema::hasTable($table)) {
            Log::warning('Quarantine file was left in place because its table could not be read.', [
                'tenant_id' => $target['companyId'],
                'kind' => $target['kind'],
                'record_id' => $target['recordId'],
            ]);

            return 'unknown';
        }

        $present = DB::table($table)->where('tenant_id', $target['companyId'])->where('id', $target['recordId'])->exists();

        return $present ? 'present' : 'absent';
    }

    /**
     * @param  array{tenant_id: int, kind: string, record_id: int, path: string}  $context
     */
    private function restoreOriginal(string $stored, string $original, array $context): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($stored)) {
            return;
        }

        if ($disk->exists($original)) {
            Log::warning('Quarantine file was left in place because the live file already exists.', $context);

            return;
        }

        try {
            $this->transfer($stored, $original);
        } catch (\Throwable $exception) {
            Log::warning('Quarantine file could not be restored.', $context + [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        Log::info('Quarantine file was restored.', $context);
    }

    /**
     * @param  array{tenant_id: int, kind: string, record_id: int, path: string}  $context
     */
    private function removeQuarantined(string $stored, array $context): void
    {
        $disk = Storage::disk('local');

        if (! str_starts_with($stored, 'quarantine/') || ! $disk->exists($stored)) {
            return;
        }

        try {
            if ($disk->delete($stored) !== true) {
                throw new \RuntimeException('The quarantine file could not be removed.');
            }
        } catch (\Throwable $exception) {
            Log::warning('Quarantine file could not be removed.', $context + [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        Log::info('Quarantine file was removed because its record no longer exists.', $context);
    }

    protected function transfer(string $from, string $to): void
    {
        if (Storage::disk('local')->move($from, $to) !== true) {
            throw new \RuntimeException('The file could not be moved.');
        }
    }

    private function removePrefix(): void
    {
        if (preg_match('#^quarantine/[a-f0-9]{32}$#', $this->prefix) !== 1) {
            throw new \RuntimeException('The quarantine directory is not specific to this deletion.');
        }

        $disk = Storage::disk('local');

        if ($disk->exists($this->prefix)) {
            $disk->deleteDirectory($this->prefix);
        }
    }
}
