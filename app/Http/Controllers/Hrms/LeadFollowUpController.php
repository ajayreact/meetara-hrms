<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Services\Hrms\LeadFollowUp;
use App\Services\Hrms\LeadFollowUpRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadFollowUpController extends Controller
{
    public function mine(Request $request, LeadFollowUp $followUps): Response
    {
        return Inertia::render('leads/mine', [
            ...$followUps->employeePage($this->actor($request), $request->query()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function showMine(Request $request, int $lead, LeadFollowUp $followUps): Response
    {
        return Inertia::render('leads/show', [
            ...$followUps->employeeRecord($this->actor($request), $lead),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function company(Request $request, LeadFollowUp $followUps): Response
    {
        return Inertia::render('leads/follow-ups', [
            ...$followUps->companyPage($this->actor($request), $request->query()),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function employeeHistory(Request $request, int $employeeId, LeadFollowUp $followUps): Response
    {
        return Inertia::render('leads/employee-history', [
            ...$followUps->employeeHistory($this->actor($request), $employeeId),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function storeMine(Request $request, int $lead, LeadFollowUp $followUps): RedirectResponse
    {
        return $this->store($request, $lead, fn (User $actor, array $fields) => $followUps->storeForEmployee($actor, $lead, $fields['pipeline_status'], $fields['follow_up_on'], $fields['remarks']), '/leads/mine/'.$lead);
    }

    public function storeTeam(Request $request, int $lead, LeadFollowUp $followUps): RedirectResponse
    {
        return $this->store($request, $lead, fn (User $actor, array $fields) => $followUps->storeForTeam($actor, $lead, $fields['pipeline_status'], $fields['follow_up_on'], $fields['remarks']), '/my-leads/'.$lead);
    }

    public function storeCompany(Request $request, int $lead, LeadFollowUp $followUps): RedirectResponse
    {
        return $this->store($request, $lead, fn (User $actor, array $fields) => $followUps->storeForCompany($actor, $lead, $fields['pipeline_status'], $fields['follow_up_on'], $fields['remarks']), '/leads/'.$lead);
    }

    /**
     * @param  callable(User, array{pipeline_status: string, follow_up_on: ?string, remarks: string}): mixed  $save
     */
    private function store(Request $request, int $lead, callable $save, string $back): RedirectResponse
    {
        $fields = $this->fields($request);

        try {
            $save($this->actor($request), $fields);
        } catch (LeadFollowUpRejected $exception) {
            return redirect()->to($back)->with('error', $exception->getMessage());
        }

        return redirect()->to($back)->with('status', 'Follow-up saved.');
    }

    /**
     * @return array{pipeline_status: string, follow_up_on: ?string, remarks: string}
     */
    private function fields(Request $request): array
    {
        $validated = $request->validate([
            'pipeline_status' => ['required', 'string', Rule::in(array_keys(Lead::STATUSES))],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            'pipeline_status' => (string) $validated['pipeline_status'],
            'follow_up_on' => isset($validated['follow_up_on']) && $validated['follow_up_on'] !== '' ? (string) $validated['follow_up_on'] : null,
            'remarks' => trim((string) ($validated['remarks'] ?? '')),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
