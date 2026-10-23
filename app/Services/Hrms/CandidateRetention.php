<?php

namespace App\Services\Hrms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CandidateRetention
{
    /**
     * Records that would be eligible if a retention period were approved.
     * This method does not delete anything.
     *
     * @return array{policy: ?int, rows: list<array{tenantId: int, submissionId: int, reference: string, createdAt: string}>}
     */
    public function dryRun(): array
    {
        $days = $this->approvedDays();

        if ($days === null || ! $this->ready()) {
            return ['policy' => null, 'rows' => []];
        }

        $rows = DB::table('candidate_submissions')
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'reference', 'created_at']);

        return [
            'policy' => $days,
            'rows' => $rows->map(fn (object $row): array => [
                'tenantId' => (int) $row->tenant_id,
                'submissionId' => (int) $row->id,
                'reference' => (string) $row->reference,
                'createdAt' => (string) $row->created_at,
            ])->all(),
        ];
    }

    /**
     * Delete one submission for one tenant. Refuses to run unless the caller
     * passes a positive day count that was explicitly selected for this call.
     * The scheduled application does not call this method.
     */
    public function purge(int $tenantId, int $submissionId, ?int $actorId, int $approvedDays): void
    {
        if ($approvedDays < 1) {
            throw new \InvalidArgumentException('No retention period is approved.');
        }

        $paths = [];
        $directory = null;

        DB::transaction(function () use ($tenantId, $submissionId, $actorId, $approvedDays, &$paths, &$directory): void {
            $row = DB::table('candidate_submissions')
                ->where('tenant_id', $tenantId)
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            if (now()->parse($row->created_at)->greaterThan(now()->subDays($approvedDays))) {
                return;
            }

            $documents = DB::table('candidate_submission_documents')
                ->where('tenant_id', $tenantId)
                ->where('submission_id', $submissionId)
                ->get(['file_path']);
            $eventCount = DB::table('candidate_onboarding_events')
                ->where('tenant_id', $tenantId)
                ->where('submission_id', $submissionId)
                ->count();
            $paths = $documents->pluck('file_path')->map(fn (mixed $path): string => (string) $path)->all();
            $directory = 'candidate-submissions/'.$tenantId.'/'.$submissionId;

            DB::table('candidate_submission_documents')->where('tenant_id', $tenantId)->where('submission_id', $submissionId)->delete();
            DB::table('candidate_submission_operations')->where('tenant_id', $tenantId)->where('submission_id', $submissionId)->delete();
            DB::table('candidate_onboarding_events')->where('tenant_id', $tenantId)->where('submission_id', $submissionId)->delete();
            DB::table('candidate_submissions')->where('tenant_id', $tenantId)->where('id', $submissionId)->delete();
            DB::table('candidate_retention_audits')->insert([
                'tenant_id' => $tenantId,
                'reference' => (string) $row->reference,
                'submission_id' => $submissionId,
                'actor_id' => $actorId,
                'event_count' => $eventCount,
                'document_count' => count($paths),
                'files_removed' => false,
                'created_at' => now(),
            ]);
        });

        if ($directory === null) {
            return;
        }

        $removed = $this->deletePrivateFiles($tenantId, $directory, $paths);
        DB::table('candidate_retention_audits')
            ->where('tenant_id', $tenantId)
            ->where('submission_id', $submissionId)
            ->orderByDesc('id')
            ->limit(1)
            ->update(['files_removed' => $removed]);

        if (! $removed) {
            Log::warning('Candidate files remained after the submission record was deleted.', [
                'tenant_id' => $tenantId,
                'submission_id' => $submissionId,
                'reason' => 'file_delete_failed',
            ]);
        }
    }

    public function retryOrphanFiles(int $tenantId, int $submissionId): bool
    {
        $stillThere = DB::table('candidate_submissions')
            ->where('tenant_id', $tenantId)
            ->where('id', $submissionId)
            ->exists();

        if ($stillThere) {
            return false;
        }

        $directory = 'candidate-submissions/'.$tenantId.'/'.$submissionId;

        return Storage::disk('local')->deleteDirectory($directory);
    }

    /**
     * @param  list<string>  $paths
     */
    private function deletePrivateFiles(int $tenantId, string $directory, array $paths): bool
    {
        $prefix = 'candidate-submissions/'.$tenantId.'/';
        $removed = true;

        if (! str_starts_with($directory, $prefix) || str_contains($directory, '..')) {
            return false;
        }

        foreach ($paths as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                $removed = false;

                continue;
            }

            if (Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
                $removed = false;
            }
        }

        if (Storage::disk('local')->directoryExists($directory) && ! Storage::disk('local')->deleteDirectory($directory)) {
            $removed = false;
        }

        return $removed;
    }

    private function approvedDays(): ?int
    {
        $days = config('candidate.retention_days');

        if (! is_int($days) || $days < 1) {
            return null;
        }

        return $days;
    }

    private function ready(): bool
    {
        return DB::getSchemaBuilder()->hasTable('candidate_submissions');
    }
}
