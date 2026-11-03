<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\WorkTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkTaskController extends Controller
{
    public function __construct(private readonly WorkTasks $tasks) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_employee_id' => ['required', 'integer'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'expected_on' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'tenant_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
        ]);
        $this->tasks->assign($this->actor($request), $data);

        return back()->with('status', 'Task assigned.');
    }

    public function progress(Request $request, int $task): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:assigned,in_progress,on_hold,completed,cancelled'],
            'note' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'assignee_employee_id' => ['prohibited'],
            'title' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ]);
        $this->tasks->progress($this->actor($request), $task, $data);

        return back()->with('status', 'Task updated.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
