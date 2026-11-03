<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\FollowUps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowUpController extends Controller
{
    public function __construct(private readonly FollowUps $followUps) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless((int) $employee->tenant_id === (int) $actor->companyId(), 404);
        $data = $this->validated($request);
        $this->followUps->save($actor, (int) $employee->id, $data);

        return back()->with('status', 'Candidate details saved.');
    }

    public function update(Request $request, int $candidate): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless(DB::table('followup_candidates')->where('tenant_id', $actor->companyId())->where('id', $candidate)->exists(), 404);
        $data = $this->validated($request);
        $owner = (int) $request->input('owner_employee_id');
        abort_unless($owner > 0, 422);
        $this->followUps->save($actor, $owner, $data, $candidate);

        return back()->with('status', 'Candidate details updated.');
    }

    public function remind(Request $request, int $candidate): RedirectResponse
    {
        $data = $request->validate([
            'remind_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->followUps->remind($this->actor($request), $candidate, $data);

        return back()->with('status', 'Reminder added.');
    }

    public function complete(Request $request, int $reminder): RedirectResponse
    {
        $this->followUps->complete($this->actor($request), $reminder);

        return back()->with('status', 'Reminder marked completed.');
    }

    public function reschedule(Request $request, int $reminder): RedirectResponse
    {
        $data = $request->validate([
            'remind_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->followUps->reschedule($this->actor($request), $reminder, $data['remind_at'], $data['notes'] ?? null);

        return back()->with('status', 'Reminder rescheduled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'university' => ['nullable', 'string', 'max:191'],
            'state' => ['nullable', 'string', 'max:191'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'follow_up_on' => ['nullable', 'date'],
            'remind_at' => ['nullable', 'date'],
            'reminder_notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:new,contacted,follow_up,interested,not_interested,converted'],
            'tenant_id' => ['prohibited'],
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
