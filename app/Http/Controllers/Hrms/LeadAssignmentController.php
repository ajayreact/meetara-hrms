<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\LeadAssignmentRejected;
use App\Services\Hrms\LeadTeamAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadAssignmentController extends Controller
{
    public function prepare(Request $request, LeadTeamAssignment $assignments): Response
    {
        $actor = $this->actor($request);
        $assignments->assertAssigner($actor);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LeadTeamAssignment::LIMIT],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return Inertia::render('leads/assign', [
            ...$assignments->prepare($actor, $validated['ids']),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function review(Request $request, LeadTeamAssignment $assignments): Response
    {
        $actor = $this->actor($request);
        $assignments->assertAssigner($actor);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LeadTeamAssignment::LIMIT],
            'ids.*' => ['integer', 'min:1'],
            'team_lead_employee_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $preview = $assignments->review($actor, $validated['ids'], (int) $validated['team_lead_employee_id']);
        } catch (LeadAssignmentRejected $exception) {
            return Inertia::render('leads/assign', [
                ...$assignments->prepare($actor, $validated['ids']),
                'notice' => null,
                'error' => $exception->getMessage(),
            ]);
        }

        return Inertia::render('leads/assign-confirm', [
            ...$preview,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(Request $request, LeadTeamAssignment $assignments): Response|RedirectResponse
    {
        $actor = $this->actor($request);
        $assignments->assertAssigner($actor);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LeadTeamAssignment::LIMIT],
            'ids.*' => ['integer', 'min:1'],
            'team_lead_employee_id' => ['required', 'integer', 'min:1'],
            'reassign' => ['sometimes', 'boolean'],
        ]);
        $teamLeadId = (int) $validated['team_lead_employee_id'];

        try {
            $result = $assignments->confirm($actor, $validated['ids'], $teamLeadId, $request->boolean('reassign'));
        } catch (LeadAssignmentRejected $exception) {
            return $this->rejected($assignments, $actor, $validated['ids'], $teamLeadId, $exception->getMessage());
        }

        return redirect()->route('hrms.leads', ['pool' => 'unassigned'])->with('status', $result['message']);
    }

    /**
     * @param  list<int>  $ids
     */
    private function rejected(LeadTeamAssignment $assignments, User $actor, array $ids, int $teamLeadId, string $message): Response
    {
        try {
            $preview = $assignments->review($actor, $ids, $teamLeadId);
        } catch (LeadAssignmentRejected) {
            return Inertia::render('leads/assign', [
                ...$assignments->prepare($actor, $ids),
                'notice' => null,
                'error' => $message,
            ]);
        }

        return Inertia::render('leads/assign-confirm', [
            ...$preview,
            'notice' => null,
            'error' => $message,
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
