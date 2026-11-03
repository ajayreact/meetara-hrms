<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreMyDocumentRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hrms\DocumentCatalog;
use App\Services\Hrms\DocumentCenter;
use App\Services\Hrms\EmployeeDocuments;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentCatalog $catalog,
        private readonly DocumentCenter $center,
        private readonly EmployeeDocuments $documents,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('documents/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->center->dashboard($actor, [
                'employee' => $request->integer('employee'),
                'department' => $request->integer('department'),
                'category' => trim($request->string('category')->toString()),
                'status' => trim($request->string('status')->toString()),
            ]),
            'notice' => session('status'),
        ]);
    }

    public function employee(Request $request, Employee $employee): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('documents/employee', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->center->employee($actor, $employee),
            'notice' => session('status'),
        ]);
    }

    public function show(Request $request, Employee $employee, int $document): Response
    {
        $actor = $this->manager($request);

        return Inertia::render('documents/show', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->center->detail($actor, $employee, $document),
            'notice' => session('status'),
        ]);
    }

    public function mine(Request $request): Response
    {
        $actor = $this->employeeActor($request);

        return Inertia::render('documents/mine', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            ...$this->center->mine($actor),
            'notice' => session('status'),
        ]);
    }

    public function storeMine(StoreMyDocumentRequest $request): RedirectResponse
    {
        $actor = $this->employeeActor($request);
        $employee = Employee::query()->where('tenant_id', $actor->companyId())->where('user_id', $actor->id)->first();
        abort_if($employee === null, 404);
        $file = $request->file('file');
        abort_unless($file !== null, 422);
        $this->documents->store(
            $actor,
            $employee,
            (string) $request->validated('category'),
            $file,
            $request->validated('reference'),
            $request->validated('description'),
        );

        return back()->with('status', 'Document uploaded. It stays pending until HR verifies it.');
    }

    public function saveCategories(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $settings = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*.key' => ['required', 'string', 'max:40'],
            'categories.*.name' => ['required', 'string', 'max:80'],
            'categories.*.required' => ['required', 'boolean'],
            'categories.*.active' => ['required', 'boolean'],
        ]);
        $this->catalog->save($actor, $settings['categories']);

        return back()->with('status', 'Document categories saved. Existing files were not changed.');
    }

    public function addCategory(Request $request): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'group' => ['required', 'string', 'max:30'],
        ]);
        $this->catalog->add($actor, $data['name'], $data['group']);

        return back()->with('status', 'Document category added. Existing files were not changed.');
    }

    public function replacement(Request $request, Employee $employee, int $document): RedirectResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);
        $this->documents->requestReplacement($actor, $employee, $document, $data['note']);

        return back()->with('status', 'Replacement requested. The document stays rejected until a new file is uploaded.');
    }

    public function preview(Request $request, Employee $employee, int $document): StreamedResponse
    {
        return $this->documents->preview($this->manager($request), $employee, $document);
    }

    public function myPreview(Request $request, int $document): StreamedResponse
    {
        $actor = $this->employeeActor($request);
        $employee = Employee::query()->where('tenant_id', $actor->companyId())->where('user_id', $actor->id)->first();
        abort_if($employee === null, 404);

        return $this->documents->preview($actor, $employee, $document);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->catalog->manages($user), 403);

        return $user;
    }

    private function employeeActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->companyId() !== null && $user->applicationRole() === 'employee', 403);

        return $user;
    }
}
