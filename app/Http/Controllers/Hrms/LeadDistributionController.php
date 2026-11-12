<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\LeadDistribution;
use App\Services\Hrms\LeadDistributionRejected;
use App\Services\Hrms\LeadFollowUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadDistributionController extends Controller
{
    public function index(Request $request, LeadDistribution $distribution, LeadFollowUp $followUps): Response
    {
        $actor = $this->actor($request);
        $page = $distribution->page($actor, $request->query());
        $monitor = $followUps->monitor($actor, $request->query());
        $page['employees'] = $followUps->applyMonitor($page['employees'], $monitor['byEmployee']);

        return Inertia::render('leads/team', [
            ...$page,
            'attention' => $monitor['attention'],
            'recentFollowUps' => $monitor['recentFollowUps'],
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function show(Request $request, int $lead, LeadDistribution $distribution, LeadFollowUp $followUps): Response
    {
        $record = $distribution->record($this->actor($request), $lead);
        $section = (string) ($record['placement']['section'] ?? '');

        return Inertia::render('leads/show', [
            ...$record,
            'canFollowUp' => in_array($section, ['pending', 'retained', 'distributed'], true),
            'followUpUrl' => '/my-leads/'.$lead.'/follow-ups',
            'statuses' => $followUps->statuses(),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function reviewRetain(Request $request, LeadDistribution $distribution): Response
    {
        return $this->review($request, $distribution, 'retain');
    }

    public function retain(Request $request, LeadDistribution $distribution): Response|RedirectResponse
    {
        return $this->commit($request, $distribution, 'retain', 'retained');
    }

    public function reviewDistribute(Request $request, LeadDistribution $distribution): Response
    {
        return $this->review($request, $distribution, 'distribute');
    }

    public function distribute(Request $request, LeadDistribution $distribution): Response|RedirectResponse
    {
        return $this->commit($request, $distribution, 'distribute', 'distributed');
    }

    public function reviewRecall(Request $request, LeadDistribution $distribution): Response
    {
        return $this->review($request, $distribution, 'recall');
    }

    public function recall(Request $request, LeadDistribution $distribution): Response|RedirectResponse
    {
        return $this->commit($request, $distribution, 'recall', 'retained');
    }

    private function review(Request $request, LeadDistribution $distribution, string $action): Response
    {
        $actor = $this->actor($request);
        $validated = $this->validated($request, $action === 'distribute');

        try {
            $preview = $this->preview($distribution, $actor, $action, $validated);
        } catch (LeadDistributionRejected $exception) {
            return Inertia::render('leads/team', [
                ...$distribution->page($actor, ['section' => $action === 'recall' ? 'distributed' : 'pending']),
                'notice' => null,
                'error' => $exception->getMessage(),
            ]);
        }

        return Inertia::render('leads/team-confirm', [
            ...$preview,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    private function commit(Request $request, LeadDistribution $distribution, string $action, string $section): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $this->validated($request, $action === 'distribute');

        try {
            $result = match ($action) {
                'retain' => $distribution->retain($actor, $validated['ids']),
                'distribute' => $distribution->distribute($actor, $validated['ids'], (int) $validated['employee_id']),
                default => $distribution->recall($actor, $validated['ids']),
            };
        } catch (LeadDistributionRejected $exception) {
            try {
                $preview = $this->preview($distribution, $actor, $action, $validated);
            } catch (LeadDistributionRejected) {
                return Inertia::render('leads/team', [
                    ...$distribution->page($actor, ['section' => $section]),
                    'notice' => null,
                    'error' => $exception->getMessage(),
                ]);
            }

            return Inertia::render('leads/team-confirm', [
                ...$preview,
                'notice' => null,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('hrms.my-leads', ['section' => $section])->with('status', $result['message']);
    }

    /**
     * @param  array{ids: list<int>, employee_id?: int}  $validated
     * @return array<string, mixed>
     */
    private function preview(LeadDistribution $distribution, User $actor, string $action, array $validated): array
    {
        return match ($action) {
            'retain' => $distribution->reviewRetain($actor, $validated['ids']),
            'distribute' => $distribution->reviewDistribute($actor, $validated['ids'], (int) $validated['employee_id']),
            default => $distribution->reviewRecall($actor, $validated['ids']),
        };
    }

    /**
     * @return array{ids: list<int>, employee_id?: int}
     */
    private function validated(Request $request, bool $employee): array
    {
        $rules = [
            'ids' => ['required', 'array', 'min:1', 'max:'.LeadDistribution::LIMIT],
            'ids.*' => ['integer', 'min:1'],
        ];

        if ($employee) {
            $rules['employee_id'] = ['required', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
