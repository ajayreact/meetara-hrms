<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\LeadReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadReportController extends Controller
{
    public function company(Request $request, LeadReport $reports): Response
    {
        return Inertia::render('leads/reports', $reports->company($this->actor($request), $this->filters($request)));
    }

    public function export(Request $request, LeadReport $reports): StreamedResponse
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $reports->authorizeCompany($actor);

        return response()->streamDownload(function () use ($reports, $actor, $filters): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $reports->writeCsv($actor, $filters, $handle);
            fclose($handle);
        }, $reports->filename($filters), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function team(Request $request, LeadReport $reports): Response
    {
        return Inertia::render('leads/team-report', $reports->team($this->actor($request), $this->filters($request)));
    }

    public function mine(Request $request, LeadReport $reports): Response
    {
        return Inertia::render('leads/my-report', $reports->employee($this->actor($request), $this->filters($request)));
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = isset($validated['from']) ? (string) $validated['from'] : null;
        $to = isset($validated['to']) ? (string) $validated['to'] : null;

        if ($from !== null && $to !== null && $from > $to) {
            return ['from' => $to, 'to' => $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
