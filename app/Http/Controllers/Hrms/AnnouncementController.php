<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\Announcements;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnnouncementController extends Controller
{
    public function __construct(private readonly Announcements $announcements) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('announcements/index', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'announcements' => $this->announcements->feed($actor),
            'canManage' => $this->announcements->manages($actor),
            'categories' => Announcements::CATEGORIES,
            'visibility' => Announcements::VISIBILITY,
            'unread' => $this->announcements->unreadCount($actor),
            'notice' => session('status'),
        ]);
    }

    public function show(Request $request, int $announcement): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('announcements/show', [
            'workspace' => AuthenticatedWorkspace::resolve($actor),
            'announcement' => $this->announcements->show($actor, $announcement),
            'canManage' => $this->announcements->manages($actor),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->announcements->save($this->actor($request), $data, $request->file('attachment'));

        return back()->with('status', 'Announcement saved as a draft. It is not visible to employees.');
    }

    public function update(Request $request, int $announcement): RedirectResponse
    {
        $data = $this->validated($request);
        $this->announcements->save($this->actor($request), $data, $request->file('attachment'), $announcement);

        return back()->with('status', 'Announcement updated.');
    }

    public function publish(Request $request, int $announcement): RedirectResponse
    {
        $this->announcements->publish($this->actor($request), $announcement);

        return back()->with('status', 'Announcement publication recorded. A future publish date stays hidden until that time.');
    }

    public function archive(Request $request, int $announcement): RedirectResponse
    {
        $this->announcements->archive($this->actor($request), $announcement);

        return back()->with('status', 'Announcement archived. Employees no longer see it.');
    }

    public function attachment(Request $request, int $announcement): StreamedResponse
    {
        return $this->announcements->download($this->actor($request), $announcement);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'in:'.implode(',', array_keys(Announcements::CATEGORIES))],
            'visibility' => ['required', 'in:'.implode(',', array_keys(Announcements::VISIBILITY))],
            'publish_at' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
        ]);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->companyId() !== null, 403);

        return $user;
    }
}
