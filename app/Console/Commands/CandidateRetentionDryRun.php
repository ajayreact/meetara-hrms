<?php

namespace App\Console\Commands;

use App\Services\Hrms\CandidateRetention;
use Illuminate\Console\Command;

class CandidateRetentionDryRun extends Command
{
    protected $signature = 'candidates:retention-dry-run';

    protected $description = 'List candidate submissions that would expire under an approved retention period, without deleting them';

    public function handle(CandidateRetention $retention): int
    {
        $report = $retention->dryRun();

        if ($report['policy'] === null) {
            $this->info('No retention period is approved. No candidate records were listed or deleted.');

            return 0;
        }

        $this->info('Retention period '.$report['policy'].' days. '.$this->countLabel(count($report['rows'])).' This command does not delete records.');

        foreach ($report['rows'] as $row) {
            $this->line($row['reference'].' company '.$row['tenantId'].' submission '.$row['submissionId']);
        }

        return 0;
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 submission is older than the period.' : $count.' submissions are older than the period.';
    }
}
