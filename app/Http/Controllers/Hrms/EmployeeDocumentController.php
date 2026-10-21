<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\DecideHistoricalTaskRequest;
use App\Http\Requests\Hrms\ReviewEmployeeDocumentRequest;
use App\Http\Requests\Hrms\StoreEmployeeDocumentRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\EmployeeDocuments;
use App\Services\Hrms\EmployeeOnboarding;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function __construct(
        private readonly EmployeeOnboarding $onboarding,
        private readonly EmployeeDocuments $documents,
    ) {}

    public function start(Employee $employee): RedirectResponse
    {
        $actor = $this->actor();
        abort_unless($actor->can('update', $employee), 403);
        $this->onboarding->open($actor, $employee);

        return back()->with('status', 'Onboarding checklist saved.');
    }

    public function store(StoreEmployeeDocumentRequest $request, Employee $employee): RedirectResponse
    {
        $file = $request->file('file');
        abort_unless($file !== null, 422);
        $this->documents->store($this->actor(), $employee, (string) $request->validated('category'), $file);

        return back()->with('status', 'Document uploaded. It stays pending until an authorized reviewer verifies it.');
    }

    public function review(ReviewEmployeeDocumentRequest $request, Employee $employee, int $document): RedirectResponse
    {
        $this->documents->review(
            $this->actor(),
            $employee,
            $document,
            (string) $request->validated('status'),
            $request->validated('note'),
        );

        return back()->with('status', 'Document review saved.');
    }

    public function decide(DecideHistoricalTaskRequest $request, Employee $employee, int $task): RedirectResponse
    {
        $this->onboarding->decide(
            $this->actor(),
            $employee,
            $task,
            (string) $request->validated('outcome'),
            $request->validated('note'),
        );

        return back()->with('status', 'Historical task decision saved.');
    }

    public function download(Employee $employee, int $document): StreamedResponse
    {
        return $this->documents->download($this->actor(), $employee, $document);
    }

    private function actor(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
